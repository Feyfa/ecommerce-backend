<?php

namespace Tests\Unit;

use App\Services\OutboxRecorderService;
use LogicException;
use Tests\TestCase;

/**
 * Memverifikasi recorder menolak operasi yang tidak berada dalam transaction boundary bisnis.
 *
 * Class ini sengaja tidak memakai RefreshDatabase agar transaction wrapper milik test runner
 * tidak menyamarkan kondisi tanpa transaksi yang sedang diverifikasi.
 */
class OutboxRecorderTransactionBoundaryTest extends TestCase
{
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
}
