<?php

namespace Tests\Unit;

use App\Services\Clerk\ClerkBackendClientService;
use App\Services\Clerk\ClerkSecurityService;
use Clerk\Backend\ClerkBackend;
use Clerk\Backend\Models\Components\ExternalAccountWithVerification;
use Clerk\Backend\Models\Components\ExternalAccountWithVerificationObject;
use Clerk\Backend\Models\Components\Session;
use Clerk\Backend\Models\Components\SessionActivityResponse;
use Clerk\Backend\Models\Components\SessionObject;
use Clerk\Backend\Models\Components\Status;
use Clerk\Backend\Models\Components\User as ClerkUser;
use Clerk\Backend\Models\Components\VerificationOauthVerificationOauth;
use Clerk\Backend\Models\Components\VerificationOauthVerificationObject;
use Clerk\Backend\Models\Components\VerificationOauthVerificationStatus;
use Clerk\Backend\Models\Operations\DeleteExternalAccountResponse;
use Clerk\Backend\Models\Operations\GetUserResponse;
use Clerk\Backend\Users;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

class ClerkSecurityServiceTest extends TestCase
{
    /**
     * Memverifikasi formatted session exposes mobile category and normalized android label.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_formatted_session_exposes_mobile_category_and_normalized_android_label(): void
    {
        $service = new ClerkSecurityService(new ClerkBackendClientService());
        $method = new ReflectionMethod($service, 'formatSession');
        $activity = new SessionActivityResponse(
            object: 'session_activity',
            id: 'activity_test',
            isMobile: true,
            deviceType: 'Linux',
            browserName: 'Chrome'
        );
        $session = new Session(
            object: SessionObject::Session,
            id: 'session_test',
            userId: 'user_test',
            clientId: 'client_test',
            status: Status::Active,
            lastActiveAt: 1_750_000_000,
            expireAt: 1_750_003_600,
            abandonAt: 1_750_007_200,
            updatedAt: 1_750_000_000,
            createdAt: 1_750_000_000,
            latestActivity: $activity
        );

        $result = $method->invoke($service, $session, 'session_test');

        $this->assertTrue($result['is_mobile']);
        $this->assertSame('Chrome di Android', $result['device_label']);
    }

    /**
     * Memverifikasi device label uses mobile context.
     *
     * @param  bool  $isMobile  Konteks apakah perangkat harus diperlakukan sebagai mobile.
     * @param  string|null  $deviceType  Tipe perangkat yang akan dinormalisasi atau ditampilkan.
     * @param  string|null  $browserName  Nama browser pada fixture aktivitas session.
     * @param  string  $expected  Nilai hasil yang diharapkan oleh skenario pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    #[DataProvider('deviceLabelCases')]
    public function test_device_label_uses_mobile_context(
        bool $isMobile,
        ?string $deviceType,
        ?string $browserName,
        string $expected
    ): void {
        $service = new ClerkSecurityService(new ClerkBackendClientService());
        $method = new ReflectionMethod($service, 'resolveDeviceLabel');
        $activity = new SessionActivityResponse(
            object: 'session_activity',
            id: 'activity_test',
            isMobile: $isMobile,
            deviceType: $deviceType,
            browserName: $browserName
        );

        $this->assertSame($expected, $method->invoke($service, $activity));
    }

    /**
     * Menyediakan kombinasi browser, sistem operasi, tipe perangkat, konteks mobile, dan label yang
     * diharapkan. Dataset memastikan fallback label tetap konsisten untuk activity Clerk yang parsial.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public static function deviceLabelCases(): array
    {
        return [
            'Android reported as Linux' => [true, 'Linux', 'Chrome', 'Chrome di Android'],
            'desktop Linux' => [false, 'Linux', 'Chrome', 'Chrome di Linux'],
            'explicit Android' => [true, 'Android', 'Chrome', 'Chrome di Android'],
            'unknown mobile type' => [true, null, 'Chrome', 'Chrome di Perangkat mobile'],
            'unknown desktop type' => [false, null, 'Chrome', 'Chrome di Perangkat desktop'],
        ];
    }

    /**
     * Memverifikasi external account is connected after oauth is verified.
     *
     * @param  VerificationOauthVerificationStatus  $status  Status bisnis yang akan diterapkan atau difilter.
     * @param  bool|null  $emailVerified  Status verifikasi email provider yang diharapkan.
     * @param  bool  $expected  Nilai hasil yang diharapkan oleh skenario pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    #[DataProvider('providerVerificationCases')]
    public function test_external_account_is_connected_after_oauth_is_verified(
        VerificationOauthVerificationStatus $status,
        ?bool $emailVerified,
        bool $expected
    ): void {
        $service = new ClerkSecurityService(new ClerkBackendClientService());
        $method = new ReflectionMethod($service, 'isVerifiedProviderAccount');

        $account = new ExternalAccountWithVerification(
            object: ExternalAccountWithVerificationObject::GoogleAccount,
            id: 'eac_test',
            provider: 'oauth_google',
            identificationId: 'idn_test',
            providerUserId: 'google_test',
            approvedScopes: 'email profile',
            emailAddress: 'user@example.com',
            firstName: 'Test',
            lastName: 'User',
            publicMetadata: [],
            createdAt: 1,
            updatedAt: 1,
            verification: new VerificationOauthVerificationOauth(
                status: $status,
                strategy: 'oauth_google',
                expireAt: 2,
                object: VerificationOauthVerificationObject::VerificationOauth
            ),
            emailAddressVerified: $emailVerified
        );

        $this->assertSame($expected, $method->invoke($service, $account));
    }

    /**
     * Menyediakan variasi status OAuth dan verifikasi email provider beserta hasil koneksi yang
     * diharapkan. Dataset mengunci perbedaan antara account terhubung dan account yang belum valid.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public static function providerVerificationCases(): array
    {
        return [
            'fully verified' => [VerificationOauthVerificationStatus::Verified, true, true],
            'oauth pending' => [VerificationOauthVerificationStatus::Unverified, true, false],
            'oauth failed' => [VerificationOauthVerificationStatus::Failed, true, false],
            'provider email flag false' => [VerificationOauthVerificationStatus::Verified, false, true],
            'provider email flag unknown' => [VerificationOauthVerificationStatus::Verified, null, true],
        ];
    }

    /**
     * Memverifikasi only external account not found errors are safe to ignore.
     *
     * @param  string  $message  Pesan kegagalan yang menjelaskan alasan operasi dihentikan.
     * @param  bool  $expected  Nilai hasil yang diharapkan oleh skenario pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    #[DataProvider('externalAccountNotFoundCases')]
    public function test_only_external_account_not_found_errors_are_safe_to_ignore(string $message, bool $expected): void
    {
        $service = new ClerkSecurityService(new ClerkBackendClientService());
        $method = new ReflectionMethod($service, 'isExternalAccountNotFoundError');

        $this->assertSame($expected, $method->invoke($service, new RuntimeException($message)));
    }

    /**
     * Menyediakan exception Clerk not-found serta kegagalan lain untuk membuktikan hanya kondisi
     * resource benar-benar hilang yang aman diabaikan.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public static function externalAccountNotFoundCases(): array
    {
        return [
            'clerk code' => ['external_account_not_found', true],
            'clerk message' => ['The External Account was not found.', true],
            'unrelated failure' => ['Clerk request timed out.', false],
        ];
    }

    /**
     * Memverifikasi external account deletion ids are hydrated per provider.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_external_account_deletion_ids_are_hydrated_per_provider(): void
    {
        $service = new ClerkSecurityService(new ClerkBackendClientService());
        $method = new ReflectionMethod($service, 'hydrateExternalAccountDeletionIds');
        $googleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_shared'
        );
        $facebookAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::FacebookAccount,
            'oauth_facebook',
            'idn_shared'
        );
        $clerkUser = $this->makeClerkUser([$googleAccount, $facebookAccount]);
        $rawResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'external_accounts' => [
                    [
                        'id' => 'eac_google',
                        'identification_id' => 'idn_shared',
                        'provider' => 'oauth_google',
                    ],
                    [
                        'id' => 'eac_facebook',
                        'identification_id' => 'idn_shared',
                        'provider' => 'oauth_facebook',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $method->invoke($service, $clerkUser, $rawResponse);

        $this->assertSame('eac_google', $googleAccount->additionalProperties['external_account_id']);
        $this->assertSame('eac_facebook', $facebookAccount->additionalProperties['external_account_id']);
    }

    /**
     * Memverifikasi google cleanup uses clerk external account id and verifies deletion.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_google_cleanup_uses_clerk_external_account_id_and_verifies_deletion(): void
    {
        $googleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_google',
            ['external_account_id' => 'eac_google']
        );
        $users = $this->getMockBuilder(Users::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteExternalAccount', 'get'])
            ->getMock();

        $users->expects($this->once())
            ->method('deleteExternalAccount')
            ->with('user_test', 'eac_google')
            ->willReturn(new DeleteExternalAccountResponse(
                contentType: 'application/json',
                statusCode: 200,
                rawResponse: new Response(200)
            ));

        $users->expects($this->once())
            ->method('get')
            ->with('user_test')
            ->willReturn($this->makeClerkUserResponse([]));

        $service = $this->makeServiceWithUsers($users);
        $method = new ReflectionMethod($service, 'deleteProviderAccounts');

        $method->invoke($service, $this->makeClerkUser([$googleAccount]), [$googleAccount]);
    }

    /**
     * Memverifikasi failed google link cleanup only removes unverified account.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_failed_google_link_cleanup_only_removes_unverified_account(): void
    {
        $failedGoogleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_failed_google',
            ['external_account_id' => 'eac_failed_google'],
            VerificationOauthVerificationStatus::Unverified
        );
        $verifiedGoogleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_verified_google',
            ['external_account_id' => 'eac_verified_google']
        );
        $users = $this->getMockBuilder(Users::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteExternalAccount', 'get'])
            ->getMock();

        $users->expects($this->exactly(2))
            ->method('get')
            ->with('user_test')
            ->willReturnOnConsecutiveCalls(
                $this->makeClerkUserResponse([$failedGoogleAccount, $verifiedGoogleAccount]),
                $this->makeClerkUserResponse([$verifiedGoogleAccount])
            );
        $users->expects($this->once())
            ->method('deleteExternalAccount')
            ->with('user_test', 'eac_failed_google')
            ->willReturn(new DeleteExternalAccountResponse(
                contentType: 'application/json',
                statusCode: 200,
                rawResponse: new Response(200)
            ));

        $result = $this->makeServiceWithUsers($users)->cleanupFailedGoogleAccountLinks('user_test');

        $this->assertSame(['removed_total' => 1], $result);
    }

    /**
     * Memverifikasi failed google link cleanup preserves verified account.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_failed_google_link_cleanup_preserves_verified_account(): void
    {
        $verifiedGoogleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_verified_google',
            ['external_account_id' => 'eac_verified_google']
        );
        $users = $this->getMockBuilder(Users::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteExternalAccount', 'get'])
            ->getMock();

        $users->expects($this->once())
            ->method('get')
            ->with('user_test')
            ->willReturn($this->makeClerkUserResponse([$verifiedGoogleAccount]));
        $users->expects($this->never())
            ->method('deleteExternalAccount');

        $result = $this->makeServiceWithUsers($users)->cleanupFailedGoogleAccountLinks('user_test');

        $this->assertSame(['removed_total' => 0], $result);
    }

    /**
     * Memverifikasi identification id cannot be used as external account deletion id.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_identification_id_cannot_be_used_as_external_account_deletion_id(): void
    {
        $googleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_google'
        );
        $service = new ClerkSecurityService(new ClerkBackendClientService());
        $method = new ReflectionMethod($service, 'getExternalAccountDeletionId');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ID akun eksternal Clerk tidak dapat ditentukan. Silakan coba lagi.');

        $method->invoke($service, $googleAccount);
    }

    /**
     * Memverifikasi not found cleanup is rejected when external account is still connected.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_not_found_cleanup_is_rejected_when_external_account_is_still_connected(): void
    {
        $googleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_google',
            ['external_account_id' => 'eac_google']
        );
        $users = $this->getMockBuilder(Users::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteExternalAccount', 'get'])
            ->getMock();

        $users->expects($this->once())
            ->method('deleteExternalAccount')
            ->with('user_test', 'eac_google')
            ->willThrowException(new RuntimeException('external_account_not_found'));

        $users->expects($this->once())
            ->method('get')
            ->with('user_test')
            ->willReturn($this->makeClerkUserResponse([$googleAccount]));

        $service = $this->makeServiceWithUsers($users);
        $method = new ReflectionMethod($service, 'deleteProviderAccounts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Akun Google yang tidak sesuai belum berhasil dilepaskan. Silakan coba lagi.');

        $method->invoke($service, $this->makeClerkUser([$googleAccount]), [$googleAccount]);
    }

    /**
     * Memverifikasi not found cleanup is accepted when external account is no longer connected.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_not_found_cleanup_is_accepted_when_external_account_is_no_longer_connected(): void
    {
        $googleAccount = $this->makeExternalAccount(
            ExternalAccountWithVerificationObject::GoogleAccount,
            'oauth_google',
            'idn_google',
            ['external_account_id' => 'eac_google']
        );
        $users = $this->getMockBuilder(Users::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteExternalAccount', 'get'])
            ->getMock();

        $users->expects($this->once())
            ->method('deleteExternalAccount')
            ->with('user_test', 'eac_google')
            ->willThrowException(new RuntimeException('external_account_not_found'));

        $users->expects($this->once())
            ->method('get')
            ->with('user_test')
            ->willReturn($this->makeClerkUserResponse([]));

        $service = $this->makeServiceWithUsers($users);
        $method = new ReflectionMethod($service, 'deleteProviderAccounts');

        $method->invoke($service, $this->makeClerkUser([$googleAccount]), [$googleAccount]);
    }

    /**
     * Membentuk external account SDK dengan identifier, email, verification, dan additional properties
     * terkontrol. Object parsial ini cukup untuk menjalankan seluruh cabang validasi provider.
     *
     * @param  ExternalAccountWithVerificationObject  $object  Object SDK yang menjadi dasar pembuatan fixture.
     * @param  string  $provider  Nama provider OAuth yang sedang diperiksa.
     * @param  string  $identificationId  ID identification Clerk yang tidak boleh dipakai sebagai deletion ID.
     * @param  array<string, mixed>|null  $additionalProperties  Metadata tambahan SDK untuk fixture external account.
     * @param  VerificationOauthVerificationStatus  $verificationStatus  Status verifikasi provider pada fixture pengujian.
     *
     * @return ExternalAccountWithVerification  Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    private function makeExternalAccount(
        ExternalAccountWithVerificationObject $object,
        string $provider,
        string $identificationId,
        ?array $additionalProperties = null,
        VerificationOauthVerificationStatus $verificationStatus = VerificationOauthVerificationStatus::Verified
    ): ExternalAccountWithVerification {
        return new ExternalAccountWithVerification(
            object: $object,
            id: $identificationId,
            provider: $provider,
            identificationId: $identificationId,
            providerUserId: 'provider_user_'.$identificationId,
            approvedScopes: 'email profile',
            emailAddress: 'user@example.com',
            firstName: 'Test',
            lastName: 'User',
            publicMetadata: [],
            createdAt: 1,
            updatedAt: 1,
            verification: new VerificationOauthVerificationOauth(
                status: $verificationStatus,
                strategy: $provider,
                expireAt: 2,
                object: VerificationOauthVerificationObject::VerificationOauth
            ),
            additionalProperties: $additionalProperties,
            emailAddressVerified: true
        );
    }

    /**
     * Membentuk user Clerk parsial dengan daftar external account yang diberikan. Helper menjaga
     * fixture identity kecil dan fokus pada state yang sedang diuji.
     *
     * @param  array  $externalAccounts  Daftar external account Clerk yang akan difilter atau diproses.
     *
     * @return ClerkUser  Model identity user yang berhasil diperoleh dari Clerk.
     */
    private function makeClerkUser(array $externalAccounts): ClerkUser
    {
        $clerkUser = (new ReflectionClass(ClerkUser::class))->newInstanceWithoutConstructor();
        $clerkUser->id = 'user_test';
        $clerkUser->externalAccounts = $externalAccounts;

        return $clerkUser;
    }

    /**
     * Membungkus model Clerk user ke response HTTP palsu yang menyerupai hasil SDK. Service dapat
     * memproses fixture melalui jalur parsing yang sama dengan provider.
     *
     * @param  array  $externalAccounts  Daftar external account Clerk yang akan difilter atau diproses.
     *
     * @return GetUserResponse  Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    private function makeClerkUserResponse(array $externalAccounts): GetUserResponse
    {
        $rawExternalAccounts = array_map(function (ExternalAccountWithVerification $externalAccount) {
            return [
                'id' => $externalAccount->additionalProperties['external_account_id']
                    ?? $externalAccount->id,
                'identification_id' => $externalAccount->identificationId,
                'provider' => $externalAccount->provider,
            ];
        }, $externalAccounts);

        return new GetUserResponse(
            contentType: 'application/json',
            statusCode: 200,
            rawResponse: new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['external_accounts' => $rawExternalAccounts], JSON_THROW_ON_ERROR)
            ),
            user: $this->makeClerkUser($externalAccounts)
        );
    }

    /**
     * Menyusun ClerkSecurityService dengan Users SDK palsu dan callback yang dapat dikontrol test.
     * Dependency ini memungkinkan request provider serta hasil reload diverifikasi tanpa jaringan.
     *
     * @param  Users  $users  Users SDK palsu yang digunakan oleh unit test.
     *
     * @return ClerkSecurityService  Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    private function makeServiceWithUsers(Users $users): ClerkSecurityService
    {
        $sdk = (new ReflectionClass(ClerkBackend::class))->newInstanceWithoutConstructor();
        $sdk->users = $users;

        $client = $this->createMock(ClerkBackendClientService::class);
        $client->method('makeSdk')->willReturn($sdk);

        return new ClerkSecurityService($client);
    }
}
