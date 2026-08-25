<?php

namespace Tests\Feature;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Jobs\SyncBuyerProductSearchJob;
use App\Jobs\SyncSellerBuyerCatalogSearchJob;
use App\Models\OutboxMessage;
use App\Services\BuyerCatalogSyncDispatcherService;
use App\Services\OutboxPublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi lifecycle publisher outbox dari claim hingga publish, retry, failure, dan cleanup.
 */
class OutboxPublisherServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memastikan event produk dan seller diterjemahkan ke job search lalu ditandai published.
     *
     * @return void Tidak mengembalikan nilai; queue dan outcome outbox diverifikasi melalui assertion.
     */
    public function test_due_product_and_seller_messages_are_published(): void
    {
        $productMessage = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-published',
        );
        $sellerMessage = $this->message(
            OutboxEventType::BUYER_CATALOG_SELLER_SYNC->value,
            OutboxAggregateType::SELLER->value,
            'seller-published',
        );

        $summary = $this->app->make(OutboxPublisherService::class)->publishDue(100, 10);

        $this->assertSame([
            'claimed' => 2,
            'published' => 2,
            'retried' => 0,
            'failed' => 0,
        ], $summary);
        Queue::assertPushed(
            SyncBuyerProductSearchJob::class,
            fn (SyncBuyerProductSearchJob $job): bool => $job->productId === 'product-published',
        );
        Queue::assertPushed(
            SyncSellerBuyerCatalogSearchJob::class,
            fn (SyncSellerBuyerCatalogSearchJob $job): bool => $job->sellerId === 'seller-published',
        );

        foreach ([$productMessage, $sellerMessage] as $message) {
            $message->refresh();
            $this->assertSame(OutboxStatus::PUBLISHED, $message->status);
            $this->assertSame(1, $message->attempts);
            $this->assertNull($message->locked_at);
            $this->assertNotNull($message->published_at);
        }
    }

    /**
     * Memastikan kegagalan Redis mempertahankan pesan pending dengan exponential backoff pertama.
     *
     * @return void Tidak mengembalikan nilai; retry metadata diverifikasi melalui assertion.
     */
    public function test_transport_failure_schedules_a_retry_without_losing_the_message(): void
    {
        $message = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-retry',
        );
        $dispatcher = Mockery::mock(BuyerCatalogSyncDispatcherService::class);
        $dispatcher->shouldReceive('dispatchProduct')
            ->once()
            ->with('product-retry')
            ->andThrow(new RuntimeException('Redis connection refused.'));
        $beforePublish = now();

        $summary = (new OutboxPublisherService($dispatcher))->publishDue(100, 10);

        $message->refresh();
        $this->assertSame(1, $summary['retried']);
        $this->assertSame(OutboxStatus::PENDING, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertNull($message->locked_at);
        $this->assertNull($message->published_at);
        $this->assertGreaterThanOrEqual(
            $beforePublish->addSeconds(60)->getTimestamp(),
            $message->available_at->getTimestamp(),
        );
        $this->assertStringContainsString('Redis connection refused.', (string) $message->last_error);
    }

    /**
     * Memastikan attempt ke-20 menjadi failed dan tidak lagi dijadwalkan otomatis.
     *
     * @return void Tidak mengembalikan nilai; status terminal dan critical log diverifikasi.
     */
    public function test_twentieth_transport_failure_becomes_terminal_failed(): void
    {
        $message = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-terminal',
            ['attempts' => 19],
        );
        $exception = new RuntimeException('Redis remained unavailable.');
        $dispatcher = Mockery::mock(BuyerCatalogSyncDispatcherService::class);
        $dispatcher->shouldReceive('dispatchProduct')->once()->andThrow($exception);
        Log::spy();

        $summary = (new OutboxPublisherService($dispatcher))->publishDue(100, 10);

        $message->refresh();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(OutboxStatus::FAILED, $message->status);
        $this->assertSame(20, $message->attempts);
        $this->assertNull($message->available_at);
        Log::shouldHaveReceived('critical')->once();
    }

    /**
     * Memastikan active processing lock dilewati sementara stale lock dapat diklaim kembali.
     *
     * @return void Tidak mengembalikan nilai; locking state diverifikasi setelah publication.
     */
    public function test_active_lock_is_skipped_and_stale_lock_is_reclaimed(): void
    {
        $active = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-active-lock',
            [
                'status' => OutboxStatus::PROCESSING,
                'attempts' => 1,
                'locked_at' => now(),
            ],
        );
        $stale = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-stale-lock',
            [
                'status' => OutboxStatus::PROCESSING,
                'attempts' => 1,
                'locked_at' => now()->subSeconds(301),
            ],
        );
        $dispatcher = Mockery::mock(BuyerCatalogSyncDispatcherService::class);
        $dispatcher->shouldReceive('dispatchProduct')->once()->with('product-stale-lock');

        $summary = (new OutboxPublisherService($dispatcher))->publishDue(100, 10);

        $active->refresh();
        $stale->refresh();
        $this->assertSame(1, $summary['claimed']);
        $this->assertSame(OutboxStatus::PROCESSING, $active->status);
        $this->assertSame(1, $active->attempts);
        $this->assertSame(OutboxStatus::PUBLISHED, $stale->status);
        $this->assertSame(2, $stale->attempts);
    }

    /**
     * Memastikan event tidak dikenal gagal permanen tanpa memanggil transport Redis.
     *
     * @return void Tidak mengembalikan nilai; status failed dan absence dispatch diverifikasi.
     */
    public function test_unsupported_event_is_failed_without_transport_dispatch(): void
    {
        $message = $this->message('unsupported.event', 'unknown', 'unknown-aggregate');
        $dispatcher = Mockery::mock(BuyerCatalogSyncDispatcherService::class);
        $dispatcher->shouldNotReceive('dispatchProduct');
        $dispatcher->shouldNotReceive('dispatchSeller');
        Log::spy();

        $summary = (new OutboxPublisherService($dispatcher))->publishDue(100, 10);

        $message->refresh();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(OutboxStatus::FAILED, $message->status);
        $this->assertStringContainsString('Unsupported outbox event type.', (string) $message->last_error);
    }

    /**
     * Memastikan payload dengan schema version yang tidak didukung gagal permanen sebelum transport dipanggil.
     *
     * @return void Tidak mengembalikan nilai; status terminal dan absence dispatch diverifikasi.
     */
    public function test_invalid_payload_is_failed_without_transport_dispatch(): void
    {
        $message = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-invalid-payload',
            [
                'payload' => [
                    'schema_version' => 2,
                    'source' => 'test.fixture',
                ],
            ],
        );
        $dispatcher = Mockery::mock(BuyerCatalogSyncDispatcherService::class);
        $dispatcher->shouldNotReceive('dispatchProduct');
        $dispatcher->shouldNotReceive('dispatchSeller');

        $summary = (new OutboxPublisherService($dispatcher))->publishDue(100, 10);

        $message->refresh();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(OutboxStatus::FAILED, $message->status);
        $this->assertStringContainsString('schema_version 1', (string) $message->last_error);
    }

    /**
     * Memastikan stale claim dapat mengirim duplikat aman ketika job sebelumnya mungkin sudah diterima Redis.
     *
     * @return void Tidak mengembalikan nilai; duplicate delivery dan final published state diverifikasi.
     */
    public function test_stale_claim_recovery_allows_safe_duplicate_delivery(): void
    {
        Queue::push(new SyncBuyerProductSearchJob('product-duplicate'));
        $message = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-duplicate',
            [
                'status' => OutboxStatus::PROCESSING,
                'attempts' => 1,
                'locked_at' => now()->subSeconds(301),
            ],
        );

        $this->app->make(OutboxPublisherService::class)->publishDue(100, 10);

        Queue::assertPushed(SyncBuyerProductSearchJob::class, 2);
        $message->refresh();
        $this->assertSame(OutboxStatus::PUBLISHED, $message->status);
        $this->assertSame(2, $message->attempts);
    }

    /**
     * Memastikan retry manual dan cleanup hanya mengubah row yang memang termasuk target operasi.
     *
     * @return void Tidak mengembalikan nilai; command lifecycle diverifikasi melalui database.
     */
    public function test_retry_and_prune_commands_preserve_required_messages(): void
    {
        $failed = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-manual-retry',
            [
                'status' => OutboxStatus::FAILED,
                'attempts' => 20,
                'available_at' => null,
                'last_error' => 'Previous terminal failure.',
            ],
        );
        $oldPublished = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-old-published',
            [
                'status' => OutboxStatus::PUBLISHED,
                'available_at' => null,
                'published_at' => now()->subDays(8),
            ],
        );
        $recentPublished = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-recent-published',
            [
                'status' => OutboxStatus::PUBLISHED,
                'available_at' => null,
                'published_at' => now()->subDays(6),
            ],
        );

        $this->artisan('outbox:retry', ['id' => $failed->id])->assertSuccessful();
        $this->artisan('outbox:prune', ['--days' => 7])->assertSuccessful();

        $failed->refresh();
        $this->assertSame(OutboxStatus::PENDING, $failed->status);
        $this->assertSame(0, $failed->attempts);
        $this->assertNotNull($failed->available_at);
        $this->assertModelMissing($oldPublished);
        $this->assertModelExists($recentPublished);
    }

    /**
     * Memastikan command publish dan status menggunakan lifecycle publisher yang sama dengan scheduler.
     *
     * @return void Tidak mengembalikan nilai; output command, queue, dan row published diverifikasi.
     */
    public function test_publish_and_status_commands_process_due_messages(): void
    {
        $message = $this->message(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            OutboxAggregateType::PRODUCT->value,
            'product-command-publish',
        );

        $this->artisan('outbox:publish')
            ->expectsOutput('Outbox publication completed: claimed=1 published=1 retried=0 failed=0.')
            ->assertSuccessful();
        $this->artisan('outbox:status')
            ->expectsOutput('Oldest pending: none')
            ->assertSuccessful();

        $message->refresh();
        $this->assertSame(OutboxStatus::PUBLISHED, $message->status);
        Queue::assertPushed(
            SyncBuyerProductSearchJob::class,
            fn (SyncBuyerProductSearchJob $job): bool => $job->productId === 'product-command-publish',
        );
    }

    /**
     * Memastikan caller tidak dapat melampaui batas 100 row per batch atau 10 batch per invocation.
     *
     * @return void Tidak mengembalikan nilai; jumlah claim bounded diverifikasi pada dua invocation.
     */
    public function test_publisher_enforces_batch_and_invocation_limits(): void
    {
        foreach (range(1, 101) as $index) {
            $this->message(
                OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
                OutboxAggregateType::PRODUCT->value,
                "product-batch-limit-{$index}",
            );
        }

        $firstSummary = $this->app->make(OutboxPublisherService::class)->publishDue(1000, 1);

        $this->assertSame(100, $firstSummary['claimed']);
        $this->assertSame(1, OutboxMessage::query()->where('status', OutboxStatus::PENDING->value)->count());

        foreach (range(1, 11) as $index) {
            $this->message(
                OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
                OutboxAggregateType::PRODUCT->value,
                "product-invocation-limit-{$index}",
            );
        }

        $secondSummary = $this->app->make(OutboxPublisherService::class)->publishDue(1, 100);

        $this->assertSame(10, $secondSummary['claimed']);
        $this->assertSame(2, OutboxMessage::query()->where('status', OutboxStatus::PENDING->value)->count());
    }

    /**
     * Membuat row outbox dengan kontrak default yang dapat dioverride oleh skenario lifecycle.
     *
     * @param  string  $eventType  Event publisher yang disimpan sebagai string agar invalid event tetap terbaca.
     * @param  string  $aggregateType  Jenis aggregate target event.
     * @param  string  $aggregateId  ID aggregate target event.
     * @param  array<string, mixed>  $overrides  Nilai lifecycle khusus skenario.
     *
     * @return OutboxMessage Pesan outbox yang tersimpan sebagai fixture.
     */
    private function message(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $overrides = [],
    ): OutboxMessage {
        return OutboxMessage::create([
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => [
                'schema_version' => 1,
                'source' => 'test.fixture',
            ],
            'status' => OutboxStatus::PENDING,
            'attempts' => 0,
            'available_at' => now(),
            ...$overrides,
        ]);
    }
}
