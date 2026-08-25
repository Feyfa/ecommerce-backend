<?php

namespace App\Console\Commands;

use App\Services\OutboxPublisherService;
use Illuminate\Console\Command;

/**
 * Membersihkan riwayat outbox published tanpa menghapus pekerjaan pending atau failed.
 */
class PruneOutboxMessagesCommand extends Command
{
    protected $signature = 'outbox:prune {--days= : Umur minimum pesan published yang boleh dihapus}';

    protected $description = 'Delete published outbox messages older than the configured retention.';

    /**
     * Menghapus row published di luar retention yang dipilih operator atau konfigurasi.
     *
     * @param  OutboxPublisherService  $publisher  Service lifecycle transactional outbox.
     *
     * @return int Kode keluar Artisan setelah cleanup selesai atau dilewati dengan aman.
     */
    public function handle(OutboxPublisherService $publisher): int
    {
        if (! $publisher->tableExists()) {
            $this->warn('The outbox_messages table is not available; pruning was skipped.');

            return self::SUCCESS;
        }

        $retentionDays = max(1, (int) ($this->option('days') ?: config('outbox.published_retention_days')));
        $deleted = $publisher->prunePublished($retentionDays);

        $this->info("Deleted {$deleted} published outbox messages older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}
