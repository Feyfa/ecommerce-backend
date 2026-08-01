<?php

namespace App\Services\Clerk;

use App\Models\User;
use Clerk\Backend\Models\Components\EmailAddress;
use Clerk\Backend\Models\Components\User as ClerkUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ClerkUserSyncService
{
    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  ClerkBackendClientService  $clerkBackendClientService  Service clerk backend client yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected ClerkBackendClientService $clerkBackendClientService
    ) {}

    /**
     * Tujuan method ini untuk mengambil identity user dari Clerk
     * lalu memastikan row users lokal selalu tersedia.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     *
     * @return User  Model user lokal yang berhasil ditemukan, dibuat, atau disinkronkan.
     */
    public function syncByClerkUserId(string $clerkUserId): User
    {
        return $this->syncByClerkUserIdWithStatus($clerkUserId)['user'];
    }

    /**
     * Mengembalikan status create agar auth bootstrap dapat membedakan
     * register baru dari login user existing secara terpercaya.
     *
     * Clerk user dimuat lebih dahulu lalu disinkronkan menggunakan flow transaksi bersama. Hasil
     * mengembalikan model lokal dan flag was_created untuk membedakan registration dari login.
     *
     * @param  string  $clerkUserId  Identity user dari token Clerk valid.
     * @param  callable|null  $afterSync  Callback yang dijalankan dalam transaction.
     *
     * @return array{user: User, was_created: bool}  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function syncByClerkUserIdWithStatus(string $clerkUserId, ?callable $afterSync = null): array
    {
        $response = $this->clerkBackendClientService
            ->makeSdk()
            ->users
            ->get($clerkUserId);

        if (! $response->user) {
            throw new RuntimeException('Authenticated Clerk user could not be found.');
        }

        return $this->syncClerkUserWithStatus($response->user, $afterSync);
    }

    /**
     * Tujuan method ini untuk attach Clerk user ke row lokal yang sudah ada
     * atau membuat row baru jika user lokal memang belum tersedia.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     *
     * @return User  Model user lokal yang berhasil ditemukan, dibuat, atau disinkronkan.
     */
    public function syncClerkUser(ClerkUser $clerkUser): User
    {
        return $this->syncClerkUserWithStatus($clerkUser)['user'];
    }

    /**
     * Callback dijalankan di dalam transaction user sync agar register dan
     * audit pertama dapat berhasil atau rollback sebagai satu unit.
     *
     * Email utama menjadi kunci pencocokan identity, sedangkan Clerk ID, nama, dan gambar diperbarui
     * dari provider. Callback audit dijalankan dalam transaksi yang sama agar pembuatan user dan event
     * pertama berhasil atau rollback bersama.
     *
     * @param  ClerkUser  $clerkUser  User yang sudah diambil dari Clerk Backend API.
     * @param  callable|null  $afterSync  Callback yang menerima user dan status create.
     *
     * @return array{user: User, was_created: bool}  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function syncClerkUserWithStatus(ClerkUser $clerkUser, ?callable $afterSync = null): array
    {
        $primaryEmail = $this->resolvePrimaryEmail($clerkUser);

        if (! $primaryEmail) {
            throw new RuntimeException('Authenticated Clerk user does not have a primary email address.');
        }

        $displayName = $this->resolveDisplayName($clerkUser, $primaryEmail);

        return DB::transaction(function () use ($clerkUser, $primaryEmail, $displayName, $afterSync): array {
            // --- step 1 - start - cari user lokal berdasarkan clerk_user_id
            $user = User::query()
                ->where('clerk_user_id', $clerkUser->id)
                ->lockForUpdate()
                ->first();
            // --- step 1 - end - cari user lokal berdasarkan clerk_user_id

            // --- step 2 - start - jika belum ada coba attach ke email lokal yang sama
            if (! $user && $primaryEmail) {
                $user = User::query()
                    ->whereNull('clerk_user_id')
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($primaryEmail)])
                    ->lockForUpdate()
                    ->first();
            }
            // --- step 2 - end - jika belum ada coba attach ke email lokal yang sama

            // --- step 3 - start - jika tetap belum ada buat row user lokal baru
            if (! $user) {
                $user = new User();
            }

            $wasCreated = ! $user->exists;
            // --- step 3 - end - jika tetap belum ada buat row user lokal baru

            // --- step 4 - start - sinkronkan identity utama dari Clerk
            $user->clerk_user_id = $clerkUser->id;

            if ($primaryEmail) {
                $user->email = $primaryEmail;
            }

            if ($displayName !== '') {
                $user->name = $displayName;
            }

            // Gambar Clerk belum langsung disalin ke field img lokal karena frontend
            // existing masih menganggap img sebagai path storage lokal Laravel.
            $user->save();

            $syncedUser = $user->fresh();

            if ($afterSync) {
                $afterSync($syncedUser, $wasCreated);
            }

            $syncResult = [
                'user' => $syncedUser,
                'was_created' => $wasCreated,
            ];
            // --- step 4 - end - sinkronkan identity utama dari Clerk

            return $syncResult;
        });
    }

    /**
     * Tujuan helper ini untuk mengambil email utama Clerk
     * berdasarkan primary_email_address_id terlebih dahulu.
     *
     * Primary email address ID diprioritaskan ketika tersedia, kemudian daftar email dipakai sebagai
     * fallback terkontrol. Helper mengembalikan null jika provider tidak menyediakan email yang dapat
     * dipercaya.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     *
     * @return string|null  Nilai teks yang telah dinormalisasi, atau null ketika sumber datanya tidak tersedia.
     */
    private function resolvePrimaryEmail(ClerkUser $clerkUser): ?string
    {
        foreach ($clerkUser->emailAddresses as $emailAddress) {
            if ($emailAddress instanceof EmailAddress && $emailAddress->id === $clerkUser->primaryEmailAddressId) {
                return $emailAddress->emailAddress;
            }
        }

        foreach ($clerkUser->emailAddresses as $emailAddress) {
            if ($emailAddress instanceof EmailAddress && ! empty($emailAddress->emailAddress)) {
                return $emailAddress->emailAddress;
            }
        }

        return null;
    }

    /**
     * Tujuan helper ini untuk membentuk nama tampilan yang konsisten
     * dari data identity Clerk.
     *
     * Nama depan dan belakang digabungkan setelah nilai kosong dibersihkan. Email atau fallback
     * identitas dipakai ketika Clerk tidak menyediakan nama yang dapat ditampilkan.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     * @param  string|null  $primaryEmail  Email utama yang digunakan sebagai fallback nama tampilan.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function resolveDisplayName(ClerkUser $clerkUser, ?string $primaryEmail): string
    {
        $fullName = trim(implode(' ', array_filter([
            $clerkUser->firstName,
            $clerkUser->lastName,
        ])));

        if ($fullName !== '') {
            return $fullName;
        }

        if (! empty($clerkUser->username)) {
            return $clerkUser->username;
        }

        if ($primaryEmail) {
            return Str::before($primaryEmail, '@');
        }

        return 'user-'.Str::lower(Str::substr($clerkUser->id, -8));
    }
}
