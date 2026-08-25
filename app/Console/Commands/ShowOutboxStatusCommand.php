<?php

namespace App\Console\Commands;

use App\Enums\OutboxStatus;
use App\Services\OutboxPublisherService;
use Illuminate\Console\Command;

/**
 * Menampilkan ringkasan operasional outbox tanpa memodifikasi lifecycle pesan.
 */
class ShowOutboxStatusCommand extends Command
{
    protected $signature = 'outbox:status';

    protected $description = 'Show outbox counts by status and the oldest pending timestamp.';

    /**
     * Menampilkan count setiap status serta umur pending tertua untuk kebutuhan monitoring manual.
     *
     * @param  OutboxPublisherService  $publisher  Service lifecycle transactional outbox.
     *
     * @return int Kode keluar Artisan setelah status ditampilkan atau tabel belum tersedia.
     */
    public function handle(OutboxPublisherService $publisher): int
    {
        $status = $publisher->status();

        if (! $status['available']) {
            $this->warn('The outbox_messages table is not available.');

            return self::SUCCESS;
        }

        $rows = collect(OutboxStatus::cases())
            ->map(fn (OutboxStatus $outboxStatus): array => [
                $outboxStatus->value,
                $status['counts'][$outboxStatus->value] ?? 0,
            ])
            ->all();

        $this->table(['Status', 'Count'], $rows);
        $this->line('Oldest pending: '.($status['oldest_pending_at'] ?? 'none'));

        return self::SUCCESS;
    }
}
