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
    public function __construct(
        protected ClerkBackendClientService $clerkBackendClientService
    ) {
    }

    /**
     * Tujuan method ini untuk mengambil identity user dari Clerk
     * lalu memastikan row users lokal selalu tersedia.
     */
    public function syncByClerkUserId(string $clerkUserId): User
    {
        $response = $this->clerkBackendClientService
            ->makeSdk()
            ->users
            ->get($clerkUserId);

        if (!$response->user) {
            throw new RuntimeException('Authenticated Clerk user could not be found.');
        }

        return $this->syncClerkUser($response->user);
    }

    /**
     * Tujuan method ini untuk attach Clerk user ke row lokal yang sudah ada
     * atau membuat row baru jika user lokal memang belum tersedia.
     */
    public function syncClerkUser(ClerkUser $clerkUser): User
    {
        $primaryEmail = $this->resolvePrimaryEmail($clerkUser);

        if (!$primaryEmail) {
            throw new RuntimeException('Authenticated Clerk user does not have a primary email address.');
        }

        $displayName = $this->resolveDisplayName($clerkUser, $primaryEmail);

        return DB::transaction(function () use ($clerkUser, $primaryEmail, $displayName): User {
            /* step 1: cari user lokal berdasarkan clerk_user_id */
            $user = User::query()
                ->where('clerk_user_id', $clerkUser->id)
                ->lockForUpdate()
                ->first();

            /* step 2: jika belum ada, coba attach ke email lokal yang sama */
            if (!$user && $primaryEmail) {
                $user = User::query()
                    ->whereNull('clerk_user_id')
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($primaryEmail)])
                    ->lockForUpdate()
                    ->first();
            }

            /* step 3: jika tetap belum ada, buat row user lokal baru */
            if (!$user) {
                $user = new User();

                /*
                 * password random sementara masih dipertahankan karena
                 * column password lama belum dibersihkan pada fase ini.
                 */
                $user->password = Str::uuid()->toString() . Str::random(24);
            }

            /* step 4: sinkronkan identity utama dari Clerk */
            $user->clerk_user_id = $clerkUser->id;

            if ($primaryEmail) {
                $user->email = $primaryEmail;
            }

            if ($displayName !== '') {
                $user->name = $displayName;
            }

            /*
             * img dari Clerk belum langsung disalin ke field img lokal
             * karena frontend existing masih menganggap img adalah path
             * storage lokal Laravel.
             */
            $user->save();

            return $user->fresh();
        });
    }

    /**
     * Tujuan helper ini untuk mengambil email utama Clerk
     * berdasarkan primary_email_address_id terlebih dahulu.
     */
    private function resolvePrimaryEmail(ClerkUser $clerkUser): ?string
    {
        foreach ($clerkUser->emailAddresses as $emailAddress) {
            if ($emailAddress instanceof EmailAddress && $emailAddress->id === $clerkUser->primaryEmailAddressId) {
                return $emailAddress->emailAddress;
            }
        }

        foreach ($clerkUser->emailAddresses as $emailAddress) {
            if ($emailAddress instanceof EmailAddress && !empty($emailAddress->emailAddress)) {
                return $emailAddress->emailAddress;
            }
        }

        return null;
    }

    /**
     * Tujuan helper ini untuk membentuk nama tampilan yang konsisten
     * dari data identity Clerk.
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

        if (!empty($clerkUser->username)) {
            return $clerkUser->username;
        }

        if ($primaryEmail) {
            return Str::before($primaryEmail, '@');
        }

        return 'user-' . Str::lower(Str::substr($clerkUser->id, -8));
    }
}
