<?php

namespace App\Services;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Mencatat intent publikasi durable di dalam transaksi PostgreSQL milik operasi bisnis.
 */
class OutboxRecorderService
{
    public const SOURCE_PRODUCT_CREATED = 'product.created';

    public const SOURCE_PRODUCT_UPDATED = 'product.updated';

    public const SOURCE_PRODUCT_DELETED = 'product.deleted';

    public const SOURCE_CHECKOUT_STOCK_CHANGED = 'checkout.stock_changed';

    public const SOURCE_COMPANY_UPDATED = 'company.updated';

    public const SOURCE_CLERK_NAME_CHANGED = 'clerk.name_changed';

    /**
     * Mencatat kebutuhan sinkronisasi produk bersama transaksi yang mengubah state terkait.
     *
     * @param  string  $productId  ID produk yang state PostgreSQL terbarunya harus diproyeksikan.
     * @param  string  $source  Sumber mutasi untuk kebutuhan observability outbox.
     *
     * @return OutboxMessage Pesan pending yang tersimpan dalam transaksi aktif.
     *
     * @throws LogicException Ketika recorder dipanggil tanpa transaksi database aktif.
     * @throws InvalidArgumentException Ketika ID produk atau source kosong setelah dinormalisasi.
     */
    public function recordProductSync(string $productId, string $source): OutboxMessage
    {
        return $this->record(
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC,
            OutboxAggregateType::PRODUCT,
            $productId,
            $source,
        );
    }

    /**
     * Mencatat satu event per produk unik untuk mutasi kelompok seperti checkout multi-item.
     *
     * @param  array<int, string>  $productIds  ID produk yang akan dinormalisasi dan dibuat unik.
     * @param  string  $source  Sumber mutasi bersama untuk seluruh produk.
     *
     * @return Collection<int, OutboxMessage> Pesan pending bagi setiap produk unik dan tidak kosong.
     *
     * @throws LogicException Ketika recorder dipanggil tanpa transaksi database aktif.
     * @throws InvalidArgumentException Ketika source atau salah satu ID produk tidak valid setelah dinormalisasi.
     */
    public function recordProductSyncMany(array $productIds, string $source): Collection
    {
        $this->assertActiveTransaction();

        $uniqueProductIds = collect($productIds)
            ->map(static fn ($productId): string => trim((string) $productId))
            ->filter()
            ->unique()
            ->values();

        return $uniqueProductIds->map(
            fn (string $productId): OutboxMessage => $this->recordProductSync($productId, $source),
        );
    }

    /**
     * Mencatat kebutuhan sinkronisasi seluruh katalog seller bersama transaksi identitas atau lokasi.
     *
     * @param  string  $sellerId  ID seller yang seluruh produk terkaitnya harus diproyeksikan ulang.
     * @param  string  $source  Sumber mutasi untuk kebutuhan observability outbox.
     *
     * @return OutboxMessage Pesan pending yang tersimpan dalam transaksi aktif.
     *
     * @throws LogicException Ketika recorder dipanggil tanpa transaksi database aktif.
     * @throws InvalidArgumentException Ketika ID seller atau source kosong setelah dinormalisasi.
     */
    public function recordSellerSync(string $sellerId, string $source): OutboxMessage
    {
        return $this->record(
            OutboxEventType::BUYER_CATALOG_SELLER_SYNC,
            OutboxAggregateType::SELLER,
            $sellerId,
            $source,
        );
    }

    /**
     * Menyimpan satu pesan outbox setelah memverifikasi transaction boundary dan identifier domain.
     *
     * @param  OutboxEventType  $eventType  Event yang menentukan job publisher.
     * @param  OutboxAggregateType  $aggregateType  Jenis aggregate target event.
     * @param  string  $aggregateId  ID aggregate yang akan diteruskan kepada job.
     * @param  string  $source  Sumber mutasi yang menciptakan intent publikasi.
     *
     * @return OutboxMessage Pesan pending yang dibuat pada koneksi database aktif.
     *
     * @throws LogicException Ketika recorder dipanggil tanpa transaksi database aktif.
     * @throws InvalidArgumentException Ketika aggregate ID atau source kosong setelah dinormalisasi.
     */
    private function record(
        OutboxEventType $eventType,
        OutboxAggregateType $aggregateType,
        string $aggregateId,
        string $source,
    ): OutboxMessage {
        $this->assertActiveTransaction();

        $aggregateId = trim($aggregateId);
        $source = trim($source);

        if ($aggregateId === '' || $source === '') {
            throw new InvalidArgumentException('Outbox aggregate ID and source must not be empty.');
        }

        return OutboxMessage::create([
            'event_type' => $eventType->value,
            'aggregate_type' => $aggregateType->value,
            'aggregate_id' => $aggregateId,
            'payload' => [
                'schema_version' => 1,
                'source' => $source,
            ],
            'status' => OutboxStatus::PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }

    /**
     * Menjaga agar setiap jalur recorder, termasuk batch kosong, hanya berjalan dalam transaksi aktif.
     *
     * @return void Eksekusi dilanjutkan ketika transaction boundary tersedia.
     *
     * @throws LogicException Ketika koneksi database tidak memiliki transaksi aktif.
     */
    private function assertActiveTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Outbox messages must be recorded inside an active database transaction.');
        }
    }
}
