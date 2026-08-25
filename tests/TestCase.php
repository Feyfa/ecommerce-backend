<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Isolates automated tests from external queue consumers and derived services.
     *
     * Product mutations dispatch search-index jobs in production. Faking the queue for every test
     * prevents SQLite fixtures from reaching a local Redis or Meilisearch instance while preserving
     * explicit dispatch assertions when a test needs to add them.
     *
     * @return void Queue fake tersedia bagi setiap test setelah bootstrap parent selesai.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }
}
