<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use App\Services\XenditService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies how the container builds the Xendit adapter and guards its timeouts.
 */
class XenditServiceRegistrationTest extends TestCase
{
    private const CONFIG_KEY = 'testing.xendit_timeout_seconds';

    /**
     * Confirms the singleton resolves with the default configuration and the application logger.
     *
     * @return void Checks that the container closure builds the adapter.
     */
    public function test_container_builds_the_service_from_the_default_configuration(): void
    {
        // A fresh resolution runs the registration closure instead of reusing an instance.
        $this->app->forgetInstance(XenditService::class);

        $this->assertInstanceOf(XenditService::class, $this->app->make(XenditService::class));
    }

    /**
     * Confirms positive numbers, including numeric env strings, become seconds.
     *
     * @param  mixed  $value  Configuration value as config or env would supply it.
     * @param  float  $expected  Seconds handed to the adapter.
     *
     * @return void Checks the normalized number of seconds.
     */
    #[DataProvider('validSeconds')]
    public function test_positive_seconds_configuration_returns_seconds(
        mixed $value,
        float $expected,
    ): void {
        config([self::CONFIG_KEY => $value]);

        $this->assertSame($expected, $this->readPositiveSeconds());
    }

    /**
     * Supplies values that keep a real time limit.
     *
     * @return array<string, array{mixed, float}> Configuration value and expected seconds.
     */
    public static function validSeconds(): array
    {
        return [
            'integer default' => [
                XenditService::DEFAULT_REQUEST_TIMEOUT_SECONDS,
                (float) XenditService::DEFAULT_REQUEST_TIMEOUT_SECONDS,
            ],
            'numeric env string' => ['12.5', 12.5],
        ];
    }

    /**
     * Rejects values that Guzzle would read as "wait forever" or that are not numbers at all.
     *
     * @param  mixed  $value  Configuration value that must not reach the adapter.
     *
     * @return void Expects the registration guard to throw.
     */
    #[DataProvider('secondsThatRemoveTheLimit')]
    public function test_positive_seconds_configuration_rejects_values_that_remove_the_limit(
        mixed $value,
    ): void {
        config([self::CONFIG_KEY => $value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Configuration ['.self::CONFIG_KEY.'] must be a number of seconds greater than zero.'
        );

        $this->readPositiveSeconds();
    }

    /**
     * Supplies zero, negative, and text values, including the empty string of a blank env line.
     *
     * @return array<string, array{mixed}> Configuration values that would remove the limit.
     */
    public static function secondsThatRemoveTheLimit(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'text' => ['abc'],
            'blank env value' => [''],
            'missing value' => [null],
        ];
    }

    /**
     * Calls the private registration guard on the test configuration key.
     *
     * @return float Seconds accepted by the guard.
     *
     * @throws RuntimeException When the guard rejects the configured value.
     */
    private function readPositiveSeconds(): float
    {
        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'positiveSecondsConfiguration');

        return $method->invoke($provider, self::CONFIG_KEY);
    }
}
