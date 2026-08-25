<?php

namespace Tests\Unit;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Services\OutboxRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi recorder hanya dapat membuat pesan durable di dalam transaction boundary bisnis.
 */
class OutboxRecorderServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memastikan recorder menolak pemanggilan di luar transaksi agar dual-write gap tidak kembali muncul.
     *
     * @return void Tidak mengembalikan nilai; pelanggaran boundary dinyatakan melalui exception.
     */
    public function test_recording_outside_a_database_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside an active database transaction');

        $this->app->make(OutboxRecorderService::class)->recordProductSync(
            'product-outside-transaction',
            OutboxRecorderService::SOURCE_PRODUCT_UPDATED,
        );
    }

    /**
     * Memastikan batch kosong tidak dapat melewati kewajiban transaction boundary recorder.
     *
     * @return void Tidak mengembalikan nilai; pelanggaran boundary dinyatakan melalui exception.
     */
    public function test_empty_batch_outside_a_database_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        $this->app->make(OutboxRecorderService::class)->recordProductSyncMany(
            [],
            OutboxRecorderService::SOURCE_CHECKOUT_STOCK_CHANGED,
        );
    }

    /**
     * Memastikan commit menyimpan event produk dan seller dengan kontrak payload versi pertama.
     *
     * @return void Tidak mengembalikan nilai; kedua row outbox diverifikasi melalui assertion.
     */
    public function test_committed_transaction_persists_product_and_seller_messages(): void
    {
        $recorder = $this->app->make(OutboxRecorderService::class);

        DB::transaction(function () use ($recorder): void {
            $recorder->recordProductSync('product-committed', OutboxRecorderService::SOURCE_PRODUCT_CREATED);
            $recorder->recordSellerSync('seller-committed', OutboxRecorderService::SOURCE_COMPANY_UPDATED);
        });

        $messages = OutboxMessage::query()->get();
        $productMessage = $messages->firstWhere('aggregate_id', 'product-committed');
        $sellerMessage = $messages->firstWhere('aggregate_id', 'seller-committed');
        $this->assertCount(2, $messages);
        $this->assertNotNull($productMessage);
        $this->assertNotNull($sellerMessage);
        $this->assertSame(OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value, $productMessage->event_type);
        $this->assertSame(OutboxAggregateType::PRODUCT->value, $productMessage->aggregate_type);
        $this->assertSame(OutboxStatus::PENDING, $productMessage->status);
        $this->assertSame(OutboxEventType::BUYER_CATALOG_SELLER_SYNC->value, $sellerMessage->event_type);
        $this->assertSame(OutboxAggregateType::SELLER->value, $sellerMessage->aggregate_type);
    }

    /**
     * Memastikan rollback operasi bisnis juga menghapus intent publikasi yang dibuat pada transaksi sama.
     *
     * @return void Tidak mengembalikan nilai; tidak adanya row outbox diverifikasi setelah rollback.
     */
    public function test_rolled_back_transaction_does_not_leave_an_outbox_message(): void
    {
        $recorder = $this->app->make(OutboxRecorderService::class);

        try {
            DB::transaction(function () use ($recorder): void {
                $recorder->recordProductSync('product-rolled-back', OutboxRecorderService::SOURCE_PRODUCT_DELETED);

                throw new RuntimeException('Rollback the business transaction.');
            });
            $this->fail('The transaction should propagate the simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback the business transaction.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outbox_messages', 0);
    }

    /**
     * Memastikan pencatatan kelompok menghasilkan satu row per produk unik pada checkout.
     *
     * @return void Tidak mengembalikan nilai; deduplication ID diverifikasi melalui aggregate outbox.
     */
    public function test_product_batch_records_each_unique_identifier_once(): void
    {
        $recorder = $this->app->make(OutboxRecorderService::class);

        DB::transaction(function () use ($recorder): void {
            $recorder->recordProductSyncMany(
                ['product-a', 'product-a', '', 'product-b'],
                OutboxRecorderService::SOURCE_CHECKOUT_STOCK_CHANGED,
            );
        });

        $this->assertSame(
            ['product-a', 'product-b'],
            OutboxMessage::query()->orderBy('aggregate_id')->pluck('aggregate_id')->all(),
        );
    }
}
