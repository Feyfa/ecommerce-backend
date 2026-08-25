<?php

namespace App\Console\Commands;

use App\Services\OutboxPublisherService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mengaktifkan kembali satu pesan outbox failed berdasarkan UUID eksplisit.
 */
class RetryOutboxMessageCommand extends Command
{
    protected $signature = 'outbox:retry {id : UUID pesan failed yang akan dicoba ulang}';

    protected $description = 'Reset one failed outbox message to pending for manual recovery.';

    /**
     * Memvalidasi UUID dan mereset satu pesan failed tanpa melakukan bulk mutation tersembunyi.
     *
     * @param  OutboxPublisherService  $publisher  Service lifecycle transactional outbox.
     *
     * @return int Kode keluar Artisan yang membedakan retry berhasil dan input yang ditolak.
     */
    public function handle(OutboxPublisherService $publisher): int
    {
        $messageId = (string) $this->argument('id');

        if (! Str::isUuid($messageId)) {
            $this->error('The outbox message ID must be a valid UUID.');

            return self::INVALID;
        }

        try {
            $message = $publisher->retryFailed($messageId);
        } catch (ModelNotFoundException) {
            $this->error('The requested outbox message was not found.');

            return self::FAILURE;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Outbox message {$message->id} was reset to pending.");

        return self::SUCCESS;
    }
}
