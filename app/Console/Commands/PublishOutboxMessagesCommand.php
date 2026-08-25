<?php

namespace App\Console\Commands;

use App\Services\OutboxPublisherService;
use Illuminate\Console\Command;

/**
 * Mempublikasikan pesan transactional outbox yang due ke transport asynchronous terkait.
 */
class PublishOutboxMessagesCommand extends Command
{
    protected $signature = 'outbox:publish
        {--batch= : Jumlah pesan per claim batch}
        {--max-batches= : Jumlah maksimum batch per invocation}';

    protected $description = 'Publish due transactional outbox messages to their asynchronous transport.';

    /**
     * Menjalankan publisher secara bounded agar invocation scheduler selalu selesai dan tidak overlap.
     *
     * @param  OutboxPublisherService  $publisher  Service lifecycle transactional outbox.
     *
     * @return int Kode keluar Artisan berdasarkan keberhasilan invocation publisher.
     */
    public function handle(OutboxPublisherService $publisher): int
    {
        if (! $publisher->tableExists()) {
            $this->warn('The outbox_messages table is not available; publication was skipped.');

            return self::SUCCESS;
        }

        $batchSize = min(100, max(1, (int) ($this->option('batch') ?: config('outbox.batch_size'))));
        $maxBatches = min(10, max(1, (int) ($this->option('max-batches') ?: config('outbox.max_batches'))));
        $summary = $publisher->publishDue($batchSize, $maxBatches);

        $this->info(sprintf(
            'Outbox publication completed: claimed=%d published=%d retried=%d failed=%d.',
            $summary['claimed'],
            $summary['published'],
            $summary['retried'],
            $summary['failed'],
        ));

        return self::SUCCESS;
    }
}
