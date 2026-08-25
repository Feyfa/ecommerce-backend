<?php

namespace Tests\Integration;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi claim outbox konkuren memakai SKIP LOCKED pada PostgreSQL testing terisolasi.
 */
class PostgresOutboxLockingTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Menolak integration test ketika koneksi bukan PostgreSQL testing yang eksplisit.
     *
     * @return void Fixture hanya dilanjutkan pada database disposable yang aman.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL outbox locking test requires DB_CONNECTION=pgsql.');
        }

        $database = basename((string) DB::connection()->getDatabaseName());

        if (preg_match('/(?:^|[_\-.])test(?:ing)?(?:$|[_\-.])/i', $database) !== 1) {
            throw new RuntimeException('PostgreSQL outbox locking test requires a database name containing test/testing.');
        }
    }

    /**
     * Memastikan koneksi kedua melewati row pertama yang dikunci dan dapat mengklaim row berikutnya.
     *
     * @return void Kedua koneksi di-rollback setelah identitas row yang diperoleh diverifikasi.
     */
    public function test_skip_locked_allows_concurrent_publishers_to_claim_different_rows(): void
    {
        $first = $this->message('product-lock-a');
        $second = $this->message('product-lock-b');
        [$connectionA, $connectionB] = $this->lockingConnections();

        $connectionA->beginTransaction();
        $connectionB->beginTransaction();

        try {
            $claimA = $connectionA->table('outbox_messages')
                ->where('status', OutboxStatus::PENDING->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();
            $claimB = $connectionB->table('outbox_messages')
                ->where('status', OutboxStatus::PENDING->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            $this->assertNotNull($claimA);
            $this->assertNotNull($claimB);
            $this->assertNotSame($claimA->id, $claimB->id);
            $this->assertEqualsCanonicalizing([$first->id, $second->id], [$claimA->id, $claimB->id]);
        } finally {
            $connectionB->rollBack();
            $connectionA->rollBack();
            DB::purge('outbox_lock_a');
            DB::purge('outbox_lock_b');
        }
    }

    /**
     * Membuat dua koneksi PostgreSQL terpisah menuju database testing yang sama.
     *
     * @return array{Connection, Connection} Koneksi independen untuk simulasi publisher konkuren.
     */
    private function lockingConnections(): array
    {
        $configuration = config('database.connections.pgsql');
        config([
            'database.connections.outbox_lock_a' => $configuration,
            'database.connections.outbox_lock_b' => $configuration,
        ]);

        return [
            DB::connection('outbox_lock_a'),
            DB::connection('outbox_lock_b'),
        ];
    }

    /**
     * Membuat pesan pending yang dapat diklaim pada integration database.
     *
     * @param  string  $aggregateId  ID produk unik untuk membedakan row claim.
     *
     * @return OutboxMessage Pesan pending yang tersimpan pada database testing.
     */
    private function message(string $aggregateId): OutboxMessage
    {
        return OutboxMessage::create([
            'event_type' => OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC->value,
            'aggregate_type' => OutboxAggregateType::PRODUCT->value,
            'aggregate_id' => $aggregateId,
            'payload' => [
                'schema_version' => 1,
                'source' => 'postgres.locking.test',
            ],
            'status' => OutboxStatus::PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
