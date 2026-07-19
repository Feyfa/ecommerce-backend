<?php

namespace Tests\Unit;

use App\Services\Clerk\ClerkBackendClientService;
use App\Services\Clerk\ClerkSecurityService;
use Clerk\Backend\ClerkBackend;
use Clerk\Backend\Models\Components\ExternalAccountWithVerification;
use Clerk\Backend\Models\Components\ExternalAccountWithVerificationObject;
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

    #[DataProvider('externalAccountNotFoundCases')]
    public function test_only_external_account_not_found_errors_are_safe_to_ignore(string $message, bool $expected): void
    {
        $service = new ClerkSecurityService(new ClerkBackendClientService());
        $method = new ReflectionMethod($service, 'isExternalAccountNotFoundError');

        $this->assertSame($expected, $method->invoke($service, new RuntimeException($message)));
    }

    public static function externalAccountNotFoundCases(): array
    {
        return [
            'clerk code' => ['external_account_not_found', true],
            'clerk message' => ['The External Account was not found.', true],
            'unrelated failure' => ['Clerk request timed out.', false],
        ];
    }

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
                        'id' => 'idn_shared',
                        'identification_id' => 'idn_shared',
                        'provider' => 'oauth_google',
                        'external_account_id' => 'eac_google',
                    ],
                    [
                        'id' => 'idn_shared',
                        'identification_id' => 'idn_shared',
                        'provider' => 'oauth_facebook',
                        'external_account_id' => 'eac_facebook',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $method->invoke($service, $clerkUser, $rawResponse);

        $this->assertSame('eac_google', $googleAccount->additionalProperties['external_account_id']);
        $this->assertSame('eac_facebook', $facebookAccount->additionalProperties['external_account_id']);
    }

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

        $method->invoke($service, 'user_test', [$googleAccount]);
    }

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

        $method->invoke($service, 'user_test', [$googleAccount]);
    }

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

        $method->invoke($service, 'user_test', [$googleAccount]);
    }

    /**
     * Tujuan helper ini untuk membuat external account minimal bagi unit test.
     *
     * @param  array<string, mixed>|null  $additionalProperties
     */
    private function makeExternalAccount(
        ExternalAccountWithVerificationObject $object,
        string $provider,
        string $identificationId,
        ?array $additionalProperties = null
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
                status: VerificationOauthVerificationStatus::Verified,
                strategy: $provider,
                expireAt: 2,
                object: VerificationOauthVerificationObject::VerificationOauth
            ),
            additionalProperties: $additionalProperties,
            emailAddressVerified: true
        );
    }

    /**
     * Tujuan helper ini untuk membuat Clerk user parsial yang hanya membawa
     * external account yang dibutuhkan unit test.
     */
    private function makeClerkUser(array $externalAccounts): ClerkUser
    {
        $clerkUser = (new ReflectionClass(ClerkUser::class))->newInstanceWithoutConstructor();
        $clerkUser->externalAccounts = $externalAccounts;

        return $clerkUser;
    }

    /**
     * Tujuan helper ini untuk membuat response GET user Clerk bagi verifikasi cleanup.
     */
    private function makeClerkUserResponse(array $externalAccounts): GetUserResponse
    {
        $rawExternalAccounts = array_map(function (ExternalAccountWithVerification $externalAccount) {
            return [
                'id' => $externalAccount->id,
                'identification_id' => $externalAccount->identificationId,
                'provider' => $externalAccount->provider,
                'external_account_id' => $externalAccount->additionalProperties['external_account_id']
                    ?? $externalAccount->id,
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
     * Tujuan helper ini untuk menyusun service dengan Users SDK yang dapat dikontrol test.
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
