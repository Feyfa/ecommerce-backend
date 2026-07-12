<?php

namespace Tests\Unit;

use App\Services\Clerk\ClerkBackendClientService;
use App\Services\Clerk\ClerkSecurityService;
use Clerk\Backend\Models\Components\ExternalAccountWithVerification;
use Clerk\Backend\Models\Components\ExternalAccountWithVerificationObject;
use Clerk\Backend\Models\Components\VerificationOauthVerificationObject;
use Clerk\Backend\Models\Components\VerificationOauthVerificationOauth;
use Clerk\Backend\Models\Components\VerificationOauthVerificationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
}
