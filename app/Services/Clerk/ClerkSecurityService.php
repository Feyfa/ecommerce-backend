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
    public function __construct(
        protected ClerkBackendClientService $clerkBackendClientService
    ) {}

    /**
     * Tujuan method ini untuk membentuk ringkasan keamanan akun
     * dari data identity yang dimiliki Clerk.
     */
    public function getSummary(string $clerkUserId): array
    {
        $clerkUser = $this->getClerkUser($clerkUserId);
        $hasGoogleAccount = $this->hasVerifiedProvider($clerkUser, 'google');
        $passkeyCount = count($clerkUser->passkeys);
        $isMfaEnabled = $clerkUser->twoFactorEnabled || $clerkUser->totpEnabled;

        return [
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
    }

    /**
     * Tujuan method ini untuk mengambil daftar session aktif milik user
     * dan menandai session yang sedang dipakai request saat ini.
     */
    public function getActiveSessions(string $clerkUserId, string $currentSessionId): array
    {
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

        $sessions = collect($response->sessionList ?? [])
            ->map(fn (Session $session) => $this->formatSession($session, $currentSessionId))
            ->sortByDesc('last_active_at_timestamp')
            ->values()
            ->all();

        return [
            'current_session_id' => $currentSessionId,
            'sessions' => $sessions,
        ];
    }

    /**
     * Tujuan method ini untuk mencabut session lain setelah memastikan
     * session tersebut benar-benar milik user yang sedang login.
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
     */
    public function revokeOtherSessions(string $clerkUserId, string $currentSessionId): array
    {
        $sessionList = $this->getActiveSessions($clerkUserId, $currentSessionId)['sessions'];
        $revokedSessionIds = [];
        $sdk = $this->clerkBackendClientService->makeSdk();

        foreach ($sessionList as $session) {
            if ($session['is_current']) {
                continue;
            }

            $sdk->sessions->revoke($session['id']);

            $revokedSessionIds[] = $session['id'];
        }

        return [
            'revoked_total' => count($revokedSessionIds),
            'revoked_session_ids' => $revokedSessionIds,
        ];
    }

    /**
     * Tujuan method ini untuk memastikan Google yang baru dihubungkan
     * benar-benar milik email akun lokal yang sedang login.
     */
    public function validateGoogleAccountLink(string $clerkUserId, LocalUser $localUser): array
    {
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

        $this->deleteProviderAccounts($clerkUser->id, $unverifiedGoogleAccounts);

        if (count($verifiedGoogleAccounts) === 0) {
            throw new RuntimeException('Akun Google belum berhasil dihubungkan.');
        }

        $validGoogleAccount = null;

        foreach ($verifiedGoogleAccounts as $googleAccount) {
            if (! $this->isSameEmail($googleAccount->emailAddress, $localUser->email)) {
                continue;
            }

            $this->ensureProviderAccountIsNotUsedByAnotherUser($clerkUser->id, $googleAccount);
            $validGoogleAccount = $googleAccount;
            break;
        }

        if (! $validGoogleAccount) {
            $this->deleteProviderAccounts($clerkUser->id, $verifiedGoogleAccounts);

            throw new RuntimeException('Email Google harus sama dengan email akun Anda.');
        }

        $this->deleteInvalidProviderAccounts($clerkUser->id, $verifiedGoogleAccounts, $validGoogleAccount->id);

        return [
            'provider' => 'google',
            'email' => $validGoogleAccount->emailAddress,
            'external_account_id' => $this->getExternalAccountDeletionId($validGoogleAccount),
        ];
    }

    /**
     * Tujuan helper ini untuk mengambil user Clerk yang valid.
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
     */
    private function hydrateExternalAccountDeletionIds(ClerkUser $clerkUser, ResponseInterface $rawResponse): void
    {
        $deletionIds = $this->extractExternalAccountDeletionIds($rawResponse);

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
    }

    /**
     * Tujuan helper ini untuk mengambil ID resource external account dari raw
     * response Clerk tanpa bergantung pada kelengkapan model SDK yang terpasang.
     *
     * @return array<string, string>
     */
    private function extractExternalAccountDeletionIds(ResponseInterface $rawResponse): array
    {
        $payload = json_decode((string) $rawResponse->getBody(), true);

        if (! is_array($payload) || ! is_array($payload['external_accounts'] ?? null)) {
            return [];
        }

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

        return $deletionIds;
    }

    /**
     * Tujuan helper ini untuk membentuk key provider dan identification yang
     * stabil agar ID Google dan Facebook tidak dapat saling tertukar.
     */
    private function getExternalAccountLookupKey(string $provider, string $identificationId): string
    {
        return mb_strtolower(trim($provider)).'|'.trim($identificationId);
    }

    /**
     * Tujuan helper ini untuk memastikan session yang diminta
     * tidak bisa melewati batas kepemilikan user.
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
     */
    private function hasVerifiedProvider(ClerkUser $clerkUser, string $provider): bool
    {
        return collect($this->getProviderAccounts($clerkUser, $provider))
            ->contains(fn (ExternalAccountWithVerification $account) => $this->isVerifiedProviderAccount($account));
    }

    /**
     * External account hanya dianggap terhubung setelah provider dan Clerk
     * menyatakan verifikasinya selesai.
     */
    private function isVerifiedProviderAccount(ExternalAccountWithVerification $externalAccount): bool
    {
        return $externalAccount->verification?->status === VerificationOauthVerificationStatus::Verified;
    }

    /**
     * Tujuan helper ini untuk mengambil external account dari provider tertentu.
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
     */
    private function isSameEmail(?string $firstEmail, ?string $secondEmail): bool
    {
        return mb_strtolower(trim((string) $firstEmail)) === mb_strtolower(trim((string) $secondEmail));
    }

    /**
     * Tujuan helper ini untuk memastikan provider account tidak dipakai user Clerk lain.
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
     */
    private function deleteProviderAccounts(string $clerkUserId, array $externalAccounts): void
    {
        $deletableAccounts = collect($externalAccounts)
            ->filter(fn ($externalAccount) => $externalAccount instanceof ExternalAccountWithVerification)
            ->values()
            ->all();

        foreach ($deletableAccounts as $externalAccount) {
            $this->deleteExternalAccount(
                $clerkUserId,
                $this->getExternalAccountDeletionId($externalAccount)
            );
        }

        $this->ensureProviderAccountsAreDeleted($clerkUserId, $deletableAccounts);
    }

    /**
     * Tujuan helper ini untuk membersihkan akun provider tambahan
     * tanpa menghapus akun provider yang sudah valid.
     */
    private function deleteInvalidProviderAccounts(string $clerkUserId, array $externalAccounts, string $validExternalAccountId): void
    {
        $invalidExternalAccounts = collect($externalAccounts)
            ->filter(fn ($externalAccount) => $externalAccount instanceof ExternalAccountWithVerification)
            ->reject(fn (ExternalAccountWithVerification $externalAccount) => $externalAccount->id === $validExternalAccountId)
            ->values()
            ->all();

        $this->deleteProviderAccounts($clerkUserId, $invalidExternalAccounts);
    }

    /**
     * Tujuan helper ini untuk memilih ID resource yang diterima endpoint delete
     * Clerk dan mencegah identification ID `idn_` terkirim sebagai penggantinya.
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
     */
    private function ensureProviderAccountsAreDeleted(string $clerkUserId, array $deletedExternalAccounts): void
    {
        if (count($deletedExternalAccounts) === 0) {
            return;
        }

        $currentClerkUser = $this->getClerkUser($clerkUserId);

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
    }

    /**
     * Tujuan helper ini untuk membandingkan external account berdasarkan provider
     * dan identification ID, dengan provider user ID sebagai fallback yang aman.
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
     */
    private function isExternalAccountNotFoundError(\Throwable $throwable): bool
    {
        $message = mb_strtolower($throwable->getMessage());

        return str_contains($message, 'external_account_not_found')
            || str_contains($message, 'external account was not found');
    }

    /**
     * Tujuan helper ini untuk membentuk daftar passkey yang aman ditampilkan
     * di halaman pengaturan tanpa membawa detail kredensial WebAuthn.
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
     */
    private function formatSession(Session $session, string $currentSessionId): array
    {
        $activity = $session->latestActivity;
        $lastActiveAt = $this->normalizeTimestamp($session->lastActiveAt);

        return [
            'id' => $session->id,
            'status' => $session->status->value,
            'is_current' => $session->id === $currentSessionId,
            'device_label' => $this->resolveDeviceLabel($activity),
            'location_label' => $this->resolveLocationLabel($activity),
            'last_active_at' => $lastActiveAt?->toIso8601String(),
            'last_active_at_timestamp' => $lastActiveAt?->timestamp ?? 0,
        ];
    }

    /**
     * Tujuan helper ini untuk membuat label perangkat walaupun data Clerk
     * tidak selalu berisi browser dan tipe device lengkap.
     */
    private function resolveDeviceLabel(?SessionActivityResponse $activity): string
    {
        if (! $activity) {
            return 'Perangkat tidak dikenal';
        }

        $browserName = trim((string) $activity->browserName);
        $deviceType = trim((string) $activity->deviceType);

        if ($browserName !== '' && $deviceType !== '') {
            return "{$browserName} di {$this->formatDeviceType($deviceType)}";
        }

        if ($browserName !== '') {
            return $browserName;
        }

        if ($deviceType !== '') {
            return $this->formatDeviceType($deviceType);
        }

        return $activity->isMobile ? 'Perangkat mobile' : 'Perangkat desktop';
    }

    /**
     * Tujuan helper ini untuk menampilkan lokasi hanya saat Clerk
     * memang mengirim city atau country.
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
     */
    private function formatDeviceType(string $deviceType): string
    {
        $normalizedDeviceType = str_replace(['_', '-'], ' ', $deviceType);

        return ucwords($normalizedDeviceType);
    }

    /**
     * Tujuan helper ini untuk menerima timestamp Clerk baik dalam detik
     * maupun milidetik tanpa membuat tanggal frontend menjadi salah.
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
