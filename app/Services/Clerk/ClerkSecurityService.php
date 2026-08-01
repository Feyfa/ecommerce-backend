<?php

namespace App\Services\Clerk;

use App\Models\User as LocalUser;
use Carbon\Carbon;
use Clerk\Backend\Models\Components\ExternalAccountWithVerification;
use Clerk\Backend\Models\Components\Session;
use Clerk\Backend\Models\Components\SessionActivityResponse;
use Clerk\Backend\Models\Components\User as ClerkUser;
use Clerk\Backend\Models\Components\VerificationOauthVerificationStatus;
use Clerk\Backend\Models\Operations\GetSessionListRequest;
use Clerk\Backend\Models\Operations\GetUserListRequest;
use Clerk\Backend\Models\Operations\Status as SessionListStatus;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class ClerkSecurityService
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
     * Tujuan method ini untuk membentuk ringkasan keamanan akun
     * dari data identity yang dimiliki Clerk.
     *
     * Data user Clerk, passkey, external account, serta session aktif diproyeksikan ke ringkasan yang
     * aman. Status koneksi dan faktor keamanan dihitung dari state provider, bukan dari flag yang
     * dikirim frontend.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getSummary(string $clerkUserId): array
    {
        // --- step 1 - start - ambil status metode login dan perlindungan akun
        $clerkUser = $this->getClerkUser($clerkUserId);
        $hasGoogleAccount = $this->hasVerifiedProvider($clerkUser, 'google');
        $passkeyCount = count($clerkUser->passkeys);
        $isMfaEnabled = $clerkUser->twoFactorEnabled || $clerkUser->totpEnabled;
        // --- step 1 - end - ambil status metode login dan perlindungan akun

        // --- step 2 - start - bentuk ringkasan keamanan untuk frontend
        $summary = [
            'sign_in_methods' => [
                [
                    'key' => 'password',
                    'label' => 'Password',
                    'status' => $clerkUser->passwordEnabled ? 'active' : 'inactive',
                    'status_label' => $clerkUser->passwordEnabled ? 'Aktif' : 'Belum dibuat',
                    'description' => 'Gunakan email utama akun ini untuk masuk dengan password.',
                    'action_label' => $clerkUser->passwordEnabled ? 'Ubah password' : 'Buat password',
                    'is_enabled' => $clerkUser->passwordEnabled,
                ],
                [
                    'key' => 'google',
                    'label' => 'Google',
                    'status' => $hasGoogleAccount ? 'connected' : 'not_connected',
                    'status_label' => $hasGoogleAccount ? 'Terhubung' : 'Belum terhubung',
                    'description' => 'Masuk menggunakan akun Google.',
                    'action_label' => $hasGoogleAccount ? '' : 'Hubungkan',
                    'is_enabled' => $hasGoogleAccount,
                ],
                [
                    'key' => 'passkey',
                    'label' => 'Passkey',
                    'status' => $passkeyCount > 0 ? 'active' : 'inactive',
                    'status_label' => $passkeyCount > 0 ? 'Aktif' : 'Belum aktif',
                    'description' => 'Gunakan biometrik atau PIN perangkat untuk login lebih aman.',
                    'action_label' => $passkeyCount > 0 ? 'Kelola' : 'Tambah',
                    'is_enabled' => $passkeyCount > 0,
                    'feature_available' => (bool) config('clerk.features.passkey', false),
                    'meta' => [
                        'total' => $passkeyCount,
                        'passkeys' => $this->formatPasskeys($clerkUser->passkeys),
                    ],
                ],
            ],
            'additional_protections' => [
                [
                    'key' => 'mfa',
                    'label' => 'Two-Factor Authentication',
                    'status' => $isMfaEnabled ? 'active' : 'inactive',
                    'status_label' => $isMfaEnabled ? 'Aktif' : 'Belum aktif',
                    'description' => 'Tambahkan verifikasi tambahan menggunakan aplikasi authenticator.',
                    'action_label' => $isMfaEnabled ? 'Kelola' : 'Aktifkan',
                    'is_enabled' => $isMfaEnabled,
                    'feature_available' => (bool) config('clerk.features.totp', false),
                    'meta' => [
                        'totp_enabled' => $clerkUser->totpEnabled,
                        'backup_code_enabled' => $clerkUser->backupCodeEnabled,
                    ],
                ],
            ],
        ];
        // --- step 2 - end - bentuk ringkasan keamanan untuk frontend

        return $summary;
    }

    /**
     * Tujuan method ini untuk mengambil daftar session aktif milik user
     * dan menandai session yang sedang dipakai request saat ini.
     *
     * Seluruh session user dimuat dari Clerk dan session saat ini ditandai menggunakan ID request.
     * Payload provider dinormalisasi menjadi label perangkat, lokasi, serta waktu aktivitas untuk
     * halaman keamanan.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  string  $currentSessionId  ID session Clerk yang sedang digunakan dan harus dipertahankan.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getActiveSessions(string $clerkUserId, string $currentSessionId): array
    {
        // --- step 1 - start - ambil session aktif dari Clerk
        $response = $this->clerkBackendClientService
            ->makeSdk()
            ->sessions
            ->list(new GetSessionListRequest(
                userId: $clerkUserId,
                status: SessionListStatus::Active,
                paginated: false,
                limit: 50,
                offset: 0
            ));
        // --- step 1 - end - ambil session aktif dari Clerk

        // --- step 2 - start - format dan urutkan session berdasarkan aktivitas terbaru
        $sessions = collect($response->sessionList ?? [])
            ->map(fn (Session $session) => $this->formatSession($session, $currentSessionId))
            ->sortByDesc('last_active_at_timestamp')
            ->values()
            ->all();
        // --- step 2 - end - format dan urutkan session berdasarkan aktivitas terbaru

        return [
            'current_session_id' => $currentSessionId,
            'sessions' => $sessions,
        ];
    }

    /**
     * Tujuan method ini untuk mencabut session lain setelah memastikan
     * session tersebut benar-benar milik user yang sedang login.
     *
     * Session target dimuat dan diverifikasi kepemilikannya sebelum revoke diminta ke Clerk. Session
     * aktif saat ini dilindungi agar user tidak memutus request yang sedang digunakan melalui endpoint
     * perangkat lain.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  string  $currentSessionId  ID session Clerk yang sedang digunakan dan harus dipertahankan.
     * @param  string  $sessionId  ID session Clerk yang menjadi target operasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function revokeSession(string $clerkUserId, string $currentSessionId, string $sessionId): array
    {
        if ($sessionId === $currentSessionId) {
            throw new RuntimeException('Current session cannot be revoked from this action.');
        }

        $session = $this->getOwnedSession($clerkUserId, $sessionId);

        $this->clerkBackendClientService
            ->makeSdk()
            ->sessions
            ->revoke($session->id);

        return [
            'revoked_session_id' => $session->id,
        ];
    }

    /**
     * Tujuan method ini untuk mencabut semua session aktif lain
     * tanpa menutup session yang sedang dipakai user saat ini.
     *
     * Daftar session difilter dengan mempertahankan current session, lalu setiap target dicabut
     * melalui Clerk. Hasil tidak bergantung pada daftar ID dari client sehingga batas ownership tetap
     * dikendalikan server.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  string  $currentSessionId  ID session Clerk yang sedang digunakan dan harus dipertahankan.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function revokeOtherSessions(string $clerkUserId, string $currentSessionId): array
    {
        // --- step 1 - start - siapkan session dan client Clerk
        $sessionList = $this->getActiveSessions($clerkUserId, $currentSessionId)['sessions'];
        $revokedSessionIds = [];
        $sdk = $this->clerkBackendClientService->makeSdk();
        // --- step 1 - end - siapkan session dan client Clerk

        // --- step 2 - start - cabut seluruh session selain session aktif
        foreach ($sessionList as $session) {
            if ($session['is_current']) {
                continue;
            }

            $sdk->sessions->revoke($session['id']);

            $revokedSessionIds[] = $session['id'];
        }
        // --- step 2 - end - cabut seluruh session selain session aktif

        return [
            'revoked_total' => count($revokedSessionIds),
            'revoked_session_ids' => $revokedSessionIds,
        ];
    }

    /**
     * Tujuan method ini untuk memastikan Google yang baru dihubungkan
     * benar-benar milik email akun lokal yang sedang login.
     *
     * External account Google dicocokkan dengan email utama local user dan status OAuth provider.
     * Koneksi yang tidak sesuai dibersihkan, sedangkan account terverifikasi yang valid dipertahankan
     * sebagai identity akun.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  LocalUser  $localUser  Model user lokal yang sedang dihubungkan dengan identity provider.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function validateGoogleAccountLink(string $clerkUserId, LocalUser $localUser): array
    {
        // --- step 1 - start - pisahkan akun Google terverifikasi dan belum terverifikasi
        $clerkUser = $this->getClerkUser($clerkUserId);
        $googleAccounts = $this->getProviderAccounts($clerkUser, 'google');
        $verifiedGoogleAccounts = collect($googleAccounts)
            ->filter(fn (ExternalAccountWithVerification $account) => $this->isVerifiedProviderAccount($account))
            ->values()
            ->all();

        $unverifiedGoogleAccounts = collect($googleAccounts)
            ->reject(fn (ExternalAccountWithVerification $account) => $this->isVerifiedProviderAccount($account))
            ->values()
            ->all();

        $this->deleteProviderAccounts($clerkUser, $unverifiedGoogleAccounts);
        // --- step 1 - end - pisahkan akun Google terverifikasi dan belum terverifikasi

        // --- step 2 - start - pastikan tersedia akun Google terverifikasi
        if (count($verifiedGoogleAccounts) === 0) {
            throw new RuntimeException('Akun Google belum berhasil dihubungkan.');
        }
        // --- step 2 - end - pastikan tersedia akun Google terverifikasi

        // --- step 3 - start - cari akun Google dengan email lokal yang sama
        $validGoogleAccount = null;

        foreach ($verifiedGoogleAccounts as $googleAccount) {
            if (! $this->isSameEmail($googleAccount->emailAddress, $localUser->email)) {
                continue;
            }

            $this->ensureProviderAccountIsNotUsedByAnotherUser($clerkUser->id, $googleAccount);
            $validGoogleAccount = $googleAccount;
            break;
        }
        // --- step 3 - end - cari akun Google dengan email lokal yang sama

        // --- step 4 - start - bersihkan akun tidak valid dan bentuk hasil
        if (! $validGoogleAccount) {
            $this->deleteProviderAccounts($clerkUser, $verifiedGoogleAccounts);

            throw new RuntimeException('Email Google harus sama dengan email akun Anda.');
        }

        $this->deleteInvalidProviderAccounts($clerkUser, $verifiedGoogleAccounts, $validGoogleAccount->id);

        $result = [
            'provider' => 'google',
            'email' => $validGoogleAccount->emailAddress,
            'external_account_id' => $this->getExternalAccountDeletionId($validGoogleAccount),
        ];
        // --- step 4 - end - bersihkan akun tidak valid dan bentuk hasil

        return $result;
    }

    /**
     * Tujuan method ini untuk membersihkan external account Google sementara
     * yang ditinggalkan Clerk setelah OAuth gagal, dibatalkan, atau kedaluwarsa.
     *
     * Service memuat ulang user Clerk dan memilih hanya koneksi Google yang belum terverifikasi.
     * Account valid tidak disentuh, termasuk ketika provider melaporkan kondisi not-found yang masih
     * perlu diverifikasi ulang.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function cleanupFailedGoogleAccountLinks(string $clerkUserId): array
    {
        $clerkUser = $this->getClerkUser($clerkUserId);
        $failedGoogleAccounts = collect($this->getProviderAccounts($clerkUser, 'google'))
            ->reject(fn (ExternalAccountWithVerification $account) => $this->isVerifiedProviderAccount($account))
            ->values()
            ->all();

        $this->deleteProviderAccounts($clerkUser, $failedGoogleAccounts);

        return [
            'removed_total' => count($failedGoogleAccounts),
        ];
    }

    /**
     * Tujuan helper ini untuk mengambil user Clerk yang valid.
     *
     * Response provider harus memuat object user yang sesuai dengan identifier diminta. Bentuk
     * response yang tidak lengkap diterjemahkan menjadi kegagalan terkontrol sebelum helper lain
     * mengakses identity.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     *
     * @return ClerkUser  Model identity user yang berhasil diperoleh dari Clerk.
     */
    private function getClerkUser(string $clerkUserId): ClerkUser
    {
        $response = $this->clerkBackendClientService
            ->makeSdk()
            ->users
            ->get($clerkUserId);

        if (! $response->user) {
            throw new RuntimeException('Authenticated Clerk user could not be found.');
        }

        $this->hydrateExternalAccountDeletionIds($response->user, $response->rawResponse);

        return $response->user;
    }

    /**
     * Tujuan helper ini untuk melengkapi model external account SDK dengan ID
     * resource `eac_` yang hanya tersedia pada raw response untuk Google/Facebook.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     * @param  ResponseInterface  $rawResponse  Response mentah SDK yang diperlukan untuk membaca metadata provider.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    private function hydrateExternalAccountDeletionIds(ClerkUser $clerkUser, ResponseInterface $rawResponse): void
    {
        // --- step 1 - start - ekstrak id penghapusan dari raw response
        $deletionIds = $this->extractExternalAccountDeletionIds($rawResponse);
        // --- step 1 - end - ekstrak id penghapusan dari raw response

        // --- step 2 - start - pasangkan id penghapusan ke model external account
        foreach ($clerkUser->externalAccounts as $externalAccount) {
            if (! $externalAccount instanceof ExternalAccountWithVerification) {
                continue;
            }

            $lookupKey = $this->getExternalAccountLookupKey(
                $externalAccount->provider,
                $externalAccount->identificationId
            );

            if (! isset($deletionIds[$lookupKey])) {
                continue;
            }

            $externalAccount->additionalProperties = array_merge(
                $externalAccount->additionalProperties ?? [],
                ['external_account_id' => $deletionIds[$lookupKey]]
            );
        }
        // --- step 2 - end - pasangkan id penghapusan ke model external account
    }

    /**
     * Tujuan helper ini untuk mengambil ID resource external account dari raw
     * response Clerk tanpa bergantung pada kelengkapan model SDK yang terpasang.
     *
     * @param  ResponseInterface  $rawResponse  Response mentah SDK yang diperlukan untuk membaca metadata provider.
     *
     * @return array<string, string>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function extractExternalAccountDeletionIds(ResponseInterface $rawResponse): array
    {
        // --- step 1 - start - validasi payload external account
        $payload = json_decode((string) $rawResponse->getBody(), true);

        if (! is_array($payload) || ! is_array($payload['external_accounts'] ?? null)) {
            return [];
        }
        // --- step 1 - end - validasi payload external account

        // --- step 2 - start - indeks id penghapusan berdasarkan provider dan identification
        $deletionIds = [];

        foreach ($payload['external_accounts'] as $externalAccount) {
            if (! is_array($externalAccount)) {
                continue;
            }

            $provider = trim((string) ($externalAccount['provider'] ?? ''));
            $identificationId = trim((string) (
                $externalAccount['identification_id']
                ?? $externalAccount['id']
                ?? ''
            ));
            $deletionId = trim((string) (
                $externalAccount['external_account_id']
                ?? $externalAccount['id']
                ?? ''
            ));

            if ($provider === '' || $identificationId === '' || $deletionId === '') {
                continue;
            }

            $deletionIds[$this->getExternalAccountLookupKey($provider, $identificationId)] = $deletionId;
        }
        // --- step 2 - end - indeks id penghapusan berdasarkan provider dan identification

        return $deletionIds;
    }

    /**
     * Tujuan helper ini untuk membentuk key provider dan identification yang
     * stabil agar ID Google dan Facebook tidak dapat saling tertukar.
     *
     * @param  string  $provider  Nama provider OAuth yang sedang diperiksa.
     * @param  string  $identificationId  ID identification Clerk yang tidak boleh dipakai sebagai deletion ID.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function getExternalAccountLookupKey(string $provider, string $identificationId): string
    {
        return mb_strtolower(trim($provider)).'|'.trim($identificationId);
    }

    /**
     * Tujuan helper ini untuk memastikan session yang diminta
     * tidak bisa melewati batas kepemilikan user.
     *
     * Session target dimuat dari Clerk lalu user ID-nya dibandingkan dengan actor. Perbedaan ownership
     * diperlakukan sebagai not found atau forbidden tanpa membocorkan detail session asing.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  string  $sessionId  ID session Clerk yang menjadi target operasi.
     *
     * @return Session  Model session Clerk yang telah diverifikasi kepemilikannya.
     */
    private function getOwnedSession(string $clerkUserId, string $sessionId): Session
    {
        $response = $this->clerkBackendClientService
            ->makeSdk()
            ->sessions
            ->get($sessionId);

        if (! $response->session || $response->session->userId !== $clerkUserId) {
            throw new RuntimeException('Session could not be found for the authenticated user.');
        }

        return $response->session;
    }

    /**
     * Tujuan helper ini untuk mengecek provider OAuth yang sudah tersambung.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     * @param  string  $provider  Nama provider OAuth yang sedang diperiksa.
     *
     * @return bool  True ketika kondisi has verified provider terpenuhi; false jika tidak.
     */
    private function hasVerifiedProvider(ClerkUser $clerkUser, string $provider): bool
    {
        return collect($this->getProviderAccounts($clerkUser, $provider))
            ->contains(fn (ExternalAccountWithVerification $account) => $this->isVerifiedProviderAccount($account));
    }

    /**
     * External account hanya dianggap terhubung setelah provider dan Clerk
     * menyatakan verifikasinya selesai.
     *
     * @param  ExternalAccountWithVerification  $externalAccount  External account Clerk yang sedang diperiksa.
     *
     * @return bool  True ketika kondisi is verified provider account terpenuhi; false jika tidak.
     */
    private function isVerifiedProviderAccount(ExternalAccountWithVerification $externalAccount): bool
    {
        return $externalAccount->verification?->status === VerificationOauthVerificationStatus::Verified;
    }

    /**
     * Tujuan helper ini untuk mengambil external account dari provider tertentu.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     * @param  string  $provider  Nama provider OAuth yang sedang diperiksa.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function getProviderAccounts(ClerkUser $clerkUser, string $provider): array
    {
        return collect($clerkUser->externalAccounts)
            ->filter(function ($externalAccount) use ($provider) {
                return $externalAccount instanceof ExternalAccountWithVerification
                    && str_contains($externalAccount->provider, $provider);
            })
            ->values()
            ->all();
    }

    /**
     * Tujuan helper ini untuk mengecek email tanpa terpengaruh huruf besar kecil.
     *
     * @param  string|null  $firstEmail  Email external account pertama untuk perbandingan.
     * @param  string|null  $secondEmail  Email external account kedua untuk perbandingan.
     *
     * @return bool  True ketika kondisi is same email terpenuhi; false jika tidak.
     */
    private function isSameEmail(?string $firstEmail, ?string $secondEmail): bool
    {
        return mb_strtolower(trim((string) $firstEmail)) === mb_strtolower(trim((string) $secondEmail));
    }

    /**
     * Tujuan helper ini untuk memastikan provider account tidak dipakai user Clerk lain.
     *
     * Identitas provider dicari pada user Clerk lain sebelum linking dianggap aman. Pemeriksaan
     * mencegah satu external account dipakai untuk menghubungkan dua local account berbeda.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  ExternalAccountWithVerification  $externalAccount  External account Clerk yang sedang diperiksa.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    private function ensureProviderAccountIsNotUsedByAnotherUser(
        string $clerkUserId,
        ExternalAccountWithVerification $externalAccount
    ): void {
        $response = $this->clerkBackendClientService
            ->makeSdk()
            ->users
            ->list(new GetUserListRequest(
                provider: $externalAccount->provider,
                providerUserId: [$externalAccount->providerUserId],
                limit: 2,
                offset: 0
            ));

        $otherUser = collect($response->userList ?? [])
            ->first(fn (ClerkUser $user) => $user->id !== $clerkUserId);

        if ($otherUser) {
            throw new RuntimeException('Akun Google sudah digunakan oleh akun lain.');
        }
    }

    /**
     * Tujuan helper ini untuk menghapus external account provider yang gagal validasi.
     *
     * Hanya external account provider yang belum terverifikasi dan memiliki deletion ID valid yang
     * diputus. Setelah penghapusan, state Clerk dimuat ulang untuk memastikan account benar-benar
     * tidak lagi terhubung.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     * @param  array  $externalAccounts  Daftar external account Clerk yang akan difilter atau diproses.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    private function deleteProviderAccounts(ClerkUser $clerkUser, array $externalAccounts): void
    {
        $deletableAccounts = collect($externalAccounts)
            ->filter(fn ($externalAccount) => $externalAccount instanceof ExternalAccountWithVerification)
            ->values()
            ->all();

        foreach ($deletableAccounts as $externalAccount) {
            $this->deleteExternalAccount(
                $clerkUser->id,
                $this->getExternalAccountDeletionId($externalAccount)
            );
        }

        $this->ensureProviderAccountsAreDeleted($clerkUser->id, $deletableAccounts);
    }

    /**
     * Tujuan helper ini untuk membersihkan akun provider tambahan
     * tanpa menghapus akun provider yang sudah valid.
     *
     * @param  ClerkUser  $clerkUser  Model identity user yang diperoleh dari Clerk.
     * @param  array  $externalAccounts  Daftar external account Clerk yang akan difilter atau diproses.
     * @param  string  $validExternalAccountId  ID resource external account yang valid untuk penghapusan.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    private function deleteInvalidProviderAccounts(ClerkUser $clerkUser, array $externalAccounts, string $validExternalAccountId): void
    {
        $invalidExternalAccounts = collect($externalAccounts)
            ->filter(fn ($externalAccount) => $externalAccount instanceof ExternalAccountWithVerification)
            ->reject(fn (ExternalAccountWithVerification $externalAccount) => $externalAccount->id === $validExternalAccountId)
            ->values()
            ->all();

        $this->deleteProviderAccounts($clerkUser, $invalidExternalAccounts);
    }

    /**
     * Tujuan helper ini untuk memilih ID resource yang diterima endpoint delete
     * Clerk dan mencegah identification ID `idn_` terkirim sebagai penggantinya.
     *
     * @param  ExternalAccountWithVerification  $externalAccount  External account Clerk yang sedang diperiksa.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function getExternalAccountDeletionId(ExternalAccountWithVerification $externalAccount): string
    {
        $externalAccountId = trim((string) (
            $externalAccount->additionalProperties['external_account_id']
            ?? ''
        ));

        if (str_starts_with($externalAccountId, 'eac_')) {
            return $externalAccountId;
        }

        $modelId = trim($externalAccount->id);

        if (str_starts_with($modelId, 'eac_')) {
            return $modelId;
        }

        throw new RuntimeException('ID akun eksternal Clerk tidak dapat ditentukan. Silakan coba lagi.');
    }

    /**
     * Tujuan helper ini untuk memutus external account dari Clerk user.
     *
     * Deletion ID Clerk divalidasi agar bukan identification ID atau identifier yang ambigu. Setelah
     * request delete, user dimuat ulang untuk memastikan external account tidak lagi terhubung.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  string  $externalAccountId  ID resource external account yang menjadi target penghapusan.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    private function deleteExternalAccount(string $clerkUserId, string $externalAccountId): void
    {
        try {
            $this->clerkBackendClientService
                ->makeSdk()
                ->users
                ->deleteExternalAccount($clerkUserId, $externalAccountId);
        } catch (Throwable $throwable) {
            if ($this->isExternalAccountNotFoundError($throwable)) {
                return;
            }

            throw $throwable;
        }
    }

    /**
     * Tujuan helper ini untuk memastikan respons not-found hanya dianggap aman
     * ketika external account memang sudah tidak ada pada user Clerk terbaru.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  array  $deletedExternalAccounts  Daftar external account yang telah dihapus pada proses sebelumnya.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    private function ensureProviderAccountsAreDeleted(string $clerkUserId, array $deletedExternalAccounts): void
    {
        // --- step 1 - start - validasi dan muat ulang user Clerk
        if (count($deletedExternalAccounts) === 0) {
            return;
        }

        $currentClerkUser = $this->getClerkUser($clerkUserId);
        // --- step 1 - end - validasi dan muat ulang user Clerk

        // --- step 2 - start - pastikan akun yang dihapus tidak lagi terhubung
        foreach ($deletedExternalAccounts as $deletedExternalAccount) {
            if (! $deletedExternalAccount instanceof ExternalAccountWithVerification) {
                continue;
            }

            $stillConnected = collect($currentClerkUser->externalAccounts)
                ->contains(function ($currentExternalAccount) use ($deletedExternalAccount) {
                    return $currentExternalAccount instanceof ExternalAccountWithVerification
                        && $this->isSameExternalAccount($currentExternalAccount, $deletedExternalAccount);
                });

            if ($stillConnected) {
                throw new RuntimeException('Akun Google yang tidak sesuai belum berhasil dilepaskan. Silakan coba lagi.');
            }
        }
        // --- step 2 - end - pastikan akun yang dihapus tidak lagi terhubung
    }

    /**
     * Tujuan helper ini untuk membandingkan external account berdasarkan provider
     * dan identification ID, dengan provider user ID sebagai fallback yang aman.
     *
     * @param  ExternalAccountWithVerification  $firstExternalAccount  External account pertama untuk skenario perbandingan.
     * @param  ExternalAccountWithVerification  $secondExternalAccount  External account kedua untuk skenario perbandingan.
     *
     * @return bool  True ketika kondisi is same external account terpenuhi; false jika tidak.
     */
    private function isSameExternalAccount(
        ExternalAccountWithVerification $firstExternalAccount,
        ExternalAccountWithVerification $secondExternalAccount
    ): bool {
        if (mb_strtolower($firstExternalAccount->provider) !== mb_strtolower($secondExternalAccount->provider)) {
            return false;
        }

        $firstIdentificationId = trim($firstExternalAccount->identificationId);
        $secondIdentificationId = trim($secondExternalAccount->identificationId);

        if ($firstIdentificationId !== '' && $secondIdentificationId !== '') {
            return $firstIdentificationId === $secondIdentificationId;
        }

        return trim($firstExternalAccount->providerUserId) !== ''
            && $firstExternalAccount->providerUserId === $secondExternalAccount->providerUserId;
    }

    /**
     * Cleanup external account dibuat idempotent karena Clerk dapat lebih dulu
     * menghapus account sementara ketika user membatalkan OAuth.
     *
     * @param  Throwable  $throwable  Exception provider yang akan diklasifikasikan.
     *
     * @return bool  True ketika kondisi is external account not found error terpenuhi; false jika tidak.
     */
    private function isExternalAccountNotFoundError(Throwable $throwable): bool
    {
        $message = mb_strtolower($throwable->getMessage());

        return str_contains($message, 'external_account_not_found')
            || str_contains($message, 'external account was not found');
    }

    /**
     * Tujuan helper ini untuk membentuk daftar passkey yang aman ditampilkan
     * di halaman pengaturan tanpa membawa detail kredensial WebAuthn.
     *
     * Hanya nama, waktu dibuat, dan metadata tampilan yang diperlukan yang diproyeksikan dari passkey
     * Clerk. Material kredensial WebAuthn tidak dimasukkan ke payload frontend.
     *
     * @param  array  $passkeys  Daftar passkey Clerk yang akan diproyeksikan secara aman.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function formatPasskeys(array $passkeys): array
    {
        return collect($passkeys)
            ->map(function ($passkey) {
                $lastUsedAt = $this->normalizeTimestamp($passkey->lastUsedAt);

                return [
                    'id' => $passkey->id,
                    'name' => $passkey->name ?: 'Passkey tanpa nama',
                    'last_used_at' => $lastUsedAt?->toIso8601String(),
                    'last_used_at_timestamp' => $lastUsedAt?->timestamp ?? 0,
                ];
            })
            ->filter(fn (array $passkey) => $passkey['id'] !== null && $passkey['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * Tujuan helper ini untuk mengubah session Clerk menjadi payload kecil
     * yang aman dan mudah ditampilkan frontend.
     *
     * Session activity, perangkat, lokasi, dan waktu terakhir aktif dinormalisasi ke struktur kecil.
     * Flag current ditentukan server menggunakan session ID request.
     *
     * @param  Session  $session  Model session Clerk yang akan diproyeksikan.
     * @param  string  $currentSessionId  ID session Clerk yang sedang digunakan dan harus dipertahankan.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function formatSession(Session $session, string $currentSessionId): array
    {
        $activity = $session->latestActivity;
        $lastActiveAt = $this->normalizeTimestamp($session->lastActiveAt);

        return [
            'id' => $session->id,
            'status' => $session->status->value,
            'is_current' => $session->id === $currentSessionId,
            'is_mobile' => (bool) ($activity?->isMobile ?? false),
            'device_label' => $this->resolveDeviceLabel($activity),
            'location_label' => $this->resolveLocationLabel($activity),
            'last_active_at' => $lastActiveAt?->toIso8601String(),
            'last_active_at_timestamp' => $lastActiveAt?->timestamp ?? 0,
        ];
    }

    /**
     * Tujuan helper ini untuk membuat label perangkat walaupun data Clerk
     * tidak selalu berisi browser dan tipe device lengkap.
     *
     * Browser, sistem operasi, dan tipe perangkat digabungkan berdasarkan data yang tersedia. Fallback
     * tetap menghasilkan label manusiawi tanpa mengarang detail yang tidak diberikan Clerk.
     *
     * @param  SessionActivityResponse|null  $activity  Metadata aktivitas session Clerk yang akan diformat.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function resolveDeviceLabel(?SessionActivityResponse $activity): string
    {
        // --- step 1 - start - validasi dan normalisasi informasi perangkat
        if (! $activity) {
            return 'Perangkat tidak dikenal';
        }

        $browserName = trim((string) $activity->browserName);
        $deviceType = $this->resolveSessionDeviceType($activity);
        // --- step 1 - end - validasi dan normalisasi informasi perangkat

        // --- step 2 - start - pilih label perangkat paling informatif
        if ($browserName !== '' && $deviceType !== '') {
            return "{$browserName} di {$deviceType}";
        }

        if ($browserName !== '') {
            return $browserName;
        }

        if ($deviceType !== '') {
            return $deviceType;
        }

        // --- step 2 - end - pilih label perangkat paling informatif

        return 'Perangkat tidak dikenal';
    }

    /**
     * Tujuan helper ini untuk menormalkan tipe perangkat dari Clerk agar
     * Android mobile yang berbasis Linux tidak ditampilkan sebagai desktop Linux.
     *
     * @param  SessionActivityResponse  $activity  Metadata aktivitas session Clerk yang akan diformat.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function resolveSessionDeviceType(SessionActivityResponse $activity): string
    {
        $deviceType = trim((string) $activity->deviceType);

        if ($activity->isMobile && strcasecmp($deviceType, 'Linux') === 0) {
            return 'Android';
        }

        if ($deviceType !== '') {
            return $this->formatDeviceType($deviceType);
        }

        return $activity->isMobile ? 'Perangkat mobile' : 'Perangkat desktop';
    }

    /**
     * Tujuan helper ini untuk menampilkan lokasi hanya saat Clerk
     * memang mengirim city atau country.
     *
     * City dan country digabungkan hanya ketika nilainya tersedia. Jika Clerk tidak memberikan lokasi,
     * helper mengembalikan label kosong atau fallback yang tidak menebak posisi user.
     *
     * @param  SessionActivityResponse|null  $activity  Metadata aktivitas session Clerk yang akan diformat.
     *
     * @return string|null  Nilai teks yang telah dinormalisasi, atau null ketika sumber datanya tidak tersedia.
     */
    private function resolveLocationLabel(?SessionActivityResponse $activity): ?string
    {
        if (! $activity) {
            return null;
        }

        $segments = array_filter([
            trim((string) $activity->city),
            trim((string) $activity->country),
        ]);

        return count($segments) > 0 ? implode(', ', $segments) : null;
    }

    /**
     * Tujuan helper ini untuk membuat tipe perangkat lebih nyaman dibaca.
     *
     * @param  string  $deviceType  Tipe perangkat yang akan dinormalisasi atau ditampilkan.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function formatDeviceType(string $deviceType): string
    {
        $normalizedDeviceType = str_replace(['_', '-'], ' ', $deviceType);

        return ucwords($normalizedDeviceType);
    }

    /**
     * Tujuan helper ini untuk menerima timestamp Clerk baik dalam detik
     * maupun milidetik tanpa membuat tanggal frontend menjadi salah.
     *
     * Timestamp milidetik dikonversi ke detik sebelum Carbon dibuat, sedangkan timestamp detik
     * dipertahankan. Nilai kosong atau tidak valid menghasilkan null daripada tanggal yang
     * menyesatkan.
     *
     * @param  int|null  $timestamp  Timestamp provider dalam detik atau milidetik.
     *
     * @return Carbon|null  Waktu yang telah dinormalisasi, atau null ketika timestamp tidak tersedia.
     */
    private function normalizeTimestamp(?int $timestamp): ?Carbon
    {
        if (! $timestamp) {
            return null;
        }

        $normalizedTimestamp = $timestamp > 9999999999
            ? (int) floor($timestamp / 1000)
            : $timestamp;

        return Carbon::createFromTimestamp($normalizedTimestamp);
    }
}
