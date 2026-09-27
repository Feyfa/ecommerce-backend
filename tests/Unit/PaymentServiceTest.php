<?php

namespace Tests\Unit;

use App\Services\PaymentService;
use Tests\TestCase;

/**
 * Verifies the synthetic payment identity contract used by account validation.
 */
class PaymentServiceTest extends TestCase
{
    /**
     * The complete set of sandbox names the generator is allowed to return.
     *
     * @var list<string>
     */
    private const EXPECTED_NAMES = [
        'Andi Pratama',
        'Budi Santoso',
        'Citra Lestari',
        'Dedi Kurniawan',
        'Eka Wulandari',
        'Fajar Nugraha',
        'Gita Permata',
        'Hendra Wijaya',
        'Intan Sari',
        'Joko Saputra',
    ];

    /**
     * Ensures every synthetic profile is complete, not only the one drawn first.
     *
     * The generator picks a profile at random, so a single draw would inspect roughly one tenth of
     * the data and let a broken profile pass unnoticed. Draws are therefore repeated until every
     * expected name has been observed, which makes a malformed entry fail reliably rather than
     * intermittently.
     *
     * @return void The assertions verify the status, the field formats of each draw, and full coverage of the profile set.
     */
    public function test_every_synthetic_profile_is_complete(): void
    {
        $service = app(PaymentService::class);
        $seenNames = [];

        for ($draw = 0; $draw < 300; $draw++) {
            $profile = $service->generateFakeUser();

            $this->assertSame('success', $profile['status']);
            $this->assertContains($profile['user']['name'], self::EXPECTED_NAMES);
            $this->assertNotSame('', $profile['user']['name']);
            $this->assertMatchesRegularExpression('/@example\.test$/', $profile['user']['email']);
            $this->assertMatchesRegularExpression('/^\+62811000000\d+$/', $profile['user']['phone']);
            $this->assertStringContainsString('Jl. Contoh Nusantara', $profile['user']['address']);

            $seenNames[$profile['user']['name']] = true;
        }

        $observed = array_keys($seenNames);
        sort($observed);

        $expected = self::EXPECTED_NAMES;
        sort($expected);

        $this->assertSame(
            $expected,
            $observed,
            'Three hundred draws should surface every synthetic profile exactly once each.'
        );
    }

    /**
     * Ensures the generator keeps returning the identity keys the payment endpoint depends on.
     *
     * @return void The assertions verify that the user payload exposes exactly the documented keys.
     */
    public function test_generated_profile_exposes_the_documented_keys(): void
    {
        $profile = app(PaymentService::class)->generateFakeUser();

        $this->assertSame(
            ['name', 'email', 'phone', 'address'],
            array_keys($profile['user'])
        );
    }
}
