<?php

namespace App\Services;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Mengklaim, mempublikasikan, menjadwalkan retry, dan membersihkan transactional outbox.
 */
class OutboxPublisherService
{
    /**
     * Menyiapkan publisher dengan dispatcher event buyer catalog yang didukung versi awal.
     *
     * @param  BuyerCatalogSyncDispatcherService  $buyerCatalogSyncDispatcher  Dispatcher job Redis buyer catalog.
     */
    public function __construct(private BuyerCatalogSyncDispatcherService $buyerCatalogSyncDispatcher) {}

    /**
     * Mempublikasikan beberapa batch pesan due tanpa menahan transaction lock selama network call.
     *
     * @param  int  $batchSize  Jumlah maksimum pesan yang diklaim per transaksi.
     * @param  int  $maxBatches  Jumlah maksimum batch dalam satu invocation terjadwal.
     *
     * @return array{claimed: int, published: int, retried: int, failed: int} Ringkasan hasil publikasi.
     *
     * @throws RuntimeException Ketika konfigurasi numerik outbox tidak dapat dikonversi dengan aman.
     */
    public function publishDue(int $batchSize, int $maxBatches): array
    {
        $batchSize = min(100, max(1, $batchSize));
        $maxBatches = min(10, max(1, $maxBatches));
        $summary = [
            'claimed' => 0,
            'published' => 0,
            'retried' => 0,
            'failed' => 0,
        ];

        if (! $this->tableExists()) {
            return $summary;
        }

        $lockTimeout = max(1, $this->integerConfig('outbox.lock_timeout_seconds'));
        $maxAttempts = max(1, $this->integerConfig('outbox.max_attempts'));
        $retryBase = max(1, $this->integerConfig('outbox.retry_base_seconds'));
        $retryMaximum = max($retryBase, $this->integerConfig('outbox.retry_max_seconds'));

        // --- step 1 - start - proses batch due secara bounded
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $messages = $this->claimBatch($batchSize, $lockTimeout);

            if ($messages->isEmpty()) {
                break;
            }

            $summary['claimed'] += $messages->count();

            foreach ($messages as $message) {
                $result = $this->publishClaimed($message, $maxAttempts, $retryBase, $retryMaximum);
                $summary[$result]++;
            }

            if ($messages->count() < $batchSize) {
                break;
            }
        }
        // --- step 1 - end - proses batch due secara bounded

        return $summary;
    }

    /**
     * Menghapus pesan published yang telah melewati retention tanpa menyentuh pending atau failed.
     *
     * @param  int  $retentionDays  Jumlah hari riwayat published yang dipertahankan.
     * @param  int  $batchSize  Jumlah maksimum row yang dihapus per query.
     *
     * @return int Jumlah row published yang berhasil dihapus.
     */
    public function prunePublished(int $retentionDays, int $batchSize = 1000): int
    {
        if (! $this->tableExists()) {
            return 0;
        }

        $retentionDays = max(1, $retentionDays);
        $batchSize = max(1, $batchSize);
        $deleted = 0;
        $cutoff = now()->subDays($retentionDays);

        do {
            $ids = OutboxMessage::query()
                ->where('status', OutboxStatus::PUBLISHED->value)
                ->where('published_at', '<', $cutoff)
                ->orderBy('published_at')
                ->limit($batchSize)
                ->pluck('id');

            $batchDeleted = $ids->isEmpty()
                ? 0
                : OutboxMessage::query()->whereIn('id', $ids)->delete();
            $deleted += $batchDeleted;
        } while ($batchDeleted === $batchSize);

        return $deleted;
    }

    /**
     * Mengaktifkan kembali satu pesan failed untuk retry manual yang eksplisit.
     *
     * @param  string  $messageId  UUID pesan failed yang akan dijadwalkan ulang.
     *
     * @return OutboxMessage Pesan yang sudah kembali berstatus pending.
     *
     * @throws RuntimeException Ketika tabel outbox belum tersedia pada environment saat ini.
     * @throws ModelNotFoundException Ketika UUID pesan tidak ditemukan.
     * @throws InvalidArgumentException Ketika pesan tidak berada pada status failed.
     */
    public function retryFailed(string $messageId): OutboxMessage
    {
        if (! $this->tableExists()) {
            throw new RuntimeException('The outbox_messages table is not available.');
        }

        return DB::transaction(function () use ($messageId): OutboxMessage {
            $message = OutboxMessage::query()->lockForUpdate()->findOrFail($messageId);

            if ($message->status !== OutboxStatus::FAILED) {
                throw new InvalidArgumentException('Only failed outbox messages can be retried manually.');
            }

            $message->forceFill([
                'status' => OutboxStatus::PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'locked_at' => null,
                'published_at' => null,
            ])->save();

            $reloaded = $message->fresh();

            if ($reloaded === null) {
                throw (new ModelNotFoundException)->setModel(OutboxMessage::class, $messageId);
            }

            return $reloaded;
        });
    }

    /**
     * Merangkum jumlah pesan per status dan umur pending tertua untuk pemeriksaan operasional.
     *
     * @return array{available: bool, counts: array<string, int>, oldest_pending_at: string|null} Status outbox.
     *
     * @throws RuntimeException Ketika hasil agregat timestamp database bukan scalar atau null.
     */
    public function status(): array
    {
        if (! $this->tableExists()) {
            return [
                'available' => false,
                'counts' => [],
                'oldest_pending_at' => null,
            ];
        }

        $counts = OutboxMessage::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static function (mixed $count): int {
                /** @var int|numeric-string $count */
                return (int) $count;
            })
            ->all();
        $oldestPending = OutboxMessage::query()
            ->where('status', OutboxStatus::PENDING->value)
            ->min('created_at');

        if ($oldestPending !== null && ! is_scalar($oldestPending)) {
            throw new RuntimeException('Invalid oldest pending outbox timestamp.');
        }

        return [
            'available' => true,
            'counts' => $counts,
            'oldest_pending_at' => $oldestPending !== null ? (string) $oldestPending : null,
        ];
    }

    /**
     * Menentukan apakah migration outbox sudah tersedia pada rollout environment saat ini.
     *
     * @return bool True ketika publisher aman mengakses tabel outbox_messages.
     */
    public function tableExists(): bool
    {
        return Schema::hasTable('outbox_messages');
    }

    /**
     * Mengklaim pesan due dan stale secara atomik dengan locking yang sesuai database runtime.
     *
     * @param  int  $batchSize  Jumlah maksimum pesan yang diklaim.
     * @param  int  $lockTimeout  Umur claim dalam detik sebelum dapat diambil kembali.
     *
     * @return Collection<int, OutboxMessage> Pesan beserta locked_at token untuk publikasi di luar transaksi.
     */
    private function claimBatch(int $batchSize, int $lockTimeout): Collection
    {
        return DB::transaction(function () use ($batchSize, $lockTimeout): Collection {
            $now = CarbonImmutable::now();
            $staleBefore = $now->subSeconds($lockTimeout);
            $query = OutboxMessage::query()
                ->where(function (Builder $query) use ($now, $staleBefore): void {
                    $query
                        ->where(function (Builder $pending) use ($now): void {
                            $pending
                                ->where('status', OutboxStatus::PENDING->value)
                                ->where(function (Builder $available) use ($now): void {
                                    $available
                                        ->whereNull('available_at')
                                        ->orWhere('available_at', '<=', $now);
                                });
                        })
                        ->orWhere(function (Builder $processing) use ($staleBefore): void {
                            $processing
                                ->where('status', OutboxStatus::PROCESSING->value)
                                ->where(function (Builder $stale) use ($staleBefore): void {
                                    $stale
                                        ->whereNull('locked_at')
                                        ->orWhere('locked_at', '<=', $staleBefore);
                                });
                        });
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($batchSize);

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $query->lockForUpdate();
            }

            $messages = $query->get();

            foreach ($messages as $message) {
                $message->forceFill([
                    'status' => OutboxStatus::PROCESSING,
                    'attempts' => $message->attempts + 1,
                    'locked_at' => $now,
                ])->save();
            }

            return $messages;
        });
    }

    /**
     * Mempublikasikan satu claim dan menerapkan outcome hanya jika locked_at token masih dimiliki.
     *
     * @param  OutboxMessage  $message  Pesan processing yang diklaim invocation saat ini.
     * @param  int  $maxAttempts  Batas percobaan sebelum kegagalan menjadi terminal.
     * @param  int  $retryBase  Delay awal retry dalam detik.
     * @param  int  $retryMaximum  Batas delay retry dalam detik.
     *
     * @return 'published'|'retried'|'failed' Nama counter ringkasan untuk outcome pesan.
     */
    private function publishClaimed(OutboxMessage $message, int $maxAttempts, int $retryBase, int $retryMaximum): string
    {
        try {
            $this->dispatchMessage($message);
            $this->updateClaim($message, [
                'status' => OutboxStatus::PUBLISHED->value,
                'available_at' => null,
                'locked_at' => null,
                'published_at' => now(),
            ]);

            return 'published';
        } catch (InvalidArgumentException $exception) {
            $this->markFailed($message, $exception, true);

            return 'failed';
        } catch (Throwable $exception) {
            if ($message->attempts >= $maxAttempts) {
                $this->markFailed($message, $exception, false);

                return 'failed';
            }

            $delay = $this->retryDelaySeconds($message->attempts, $retryBase, $retryMaximum);
            $this->updateClaim($message, [
                'status' => OutboxStatus::PENDING->value,
                'available_at' => now()->addSeconds($delay),
                'locked_at' => null,
                'last_error' => $this->safeError($exception),
            ]);

            Log::warning('Outbox publication failed and was scheduled for retry.', [
                'outbox_id' => $message->id,
                'event_type' => $message->event_type,
                'aggregate_type' => $message->aggregate_type,
                'aggregate_id' => $message->aggregate_id,
                'attempts' => $message->attempts,
                'retry_delay_seconds' => $delay,
                'exception' => $exception,
            ]);

            return 'retried';
        }
    }

    /**
     * Memvalidasi schema dan source string sebelum meneruskan event ke dispatcher buyer catalog.
     *
     * @param  OutboxMessage  $message  Pesan yang event dan aggregate-nya akan diterjemahkan.
     *
     * @return void Job terkait dikirim ke Redis atau exception validasi dilempar.
     */
    private function dispatchMessage(OutboxMessage $message): void
    {
        $rawPayload = $message->getAttribute('payload');
        $payload = is_array($rawPayload) ? $rawPayload : [];
        $source = $payload['source'] ?? null;

        if (($payload['schema_version'] ?? null) !== 1 || ! is_string($source) || trim($source) === '') {
            throw new InvalidArgumentException('Outbox payload must contain schema_version 1 and a non-empty source.');
        }

        $eventType = OutboxEventType::tryFrom((string) $message->event_type);

        match ($eventType) {
            OutboxEventType::BUYER_CATALOG_PRODUCT_SYNC => $this->dispatchProductMessage($message),
            OutboxEventType::BUYER_CATALOG_SELLER_SYNC => $this->dispatchSellerMessage($message),
            null => throw new InvalidArgumentException('Unsupported outbox event type.'),
        };
    }

    /**
     * Memvalidasi aggregate produk lalu mengirim job sinkronisasi produk.
     *
     * @param  OutboxMessage  $message  Pesan product sync yang telah lolos validasi payload.
     *
     * @return void Job produk dikirim atau exception kontrak dilempar.
     */
    private function dispatchProductMessage(OutboxMessage $message): void
    {
        if (
            $message->aggregate_type !== OutboxAggregateType::PRODUCT->value
            || trim((string) $message->aggregate_id) === ''
        ) {
            throw new InvalidArgumentException('Product sync requires a non-empty product aggregate.');
        }

        $this->buyerCatalogSyncDispatcher->dispatchProduct((string) $message->aggregate_id);
    }

    /**
     * Memvalidasi aggregate seller lalu mengirim job sinkronisasi seluruh katalog seller.
     *
     * @param  OutboxMessage  $message  Pesan seller sync yang telah lolos validasi payload.
     *
     * @return void Job seller dikirim atau exception kontrak dilempar.
     */
    private function dispatchSellerMessage(OutboxMessage $message): void
    {
        if (
            $message->aggregate_type !== OutboxAggregateType::SELLER->value
            || trim((string) $message->aggregate_id) === ''
        ) {
            throw new InvalidArgumentException('Seller sync requires a non-empty seller aggregate.');
        }

        $this->buyerCatalogSyncDispatcher->dispatchSeller((string) $message->aggregate_id);
    }

    /**
     * Menandai claim terminal failed dan mencatat konteks lengkap untuk intervensi operator.
     *
     * @param  OutboxMessage  $message  Pesan yang tidak dapat dipublikasikan lagi secara otomatis.
     * @param  Throwable  $exception  Kegagalan validasi permanen atau transport pada attempt terakhir.
     * @param  bool  $permanent  Menunjukkan kegagalan kontrak yang tidak layak di-retry.
     *
     * @return void Status terminal diterapkan hanya jika claim token masih dimiliki.
     */
    private function markFailed(OutboxMessage $message, Throwable $exception, bool $permanent): void
    {
        $this->updateClaim($message, [
            'status' => OutboxStatus::FAILED->value,
            'available_at' => null,
            'locked_at' => null,
            'last_error' => $this->safeError($exception),
        ]);

        Log::critical('Outbox publication reached a terminal failure.', [
            'outbox_id' => $message->id,
            'event_type' => $message->event_type,
            'aggregate_type' => $message->aggregate_type,
            'aggregate_id' => $message->aggregate_id,
            'attempts' => $message->attempts,
            'permanent' => $permanent,
            'exception' => $exception,
        ]);
    }

    /**
     * Memperbarui outcome hanya ketika status dan locked_at masih cocok dengan claim invocation ini.
     *
     * @param  OutboxMessage  $message  Pesan beserta locked_at token saat claim.
     * @param  array<string, mixed>  $attributes  Nilai outcome yang akan diterapkan.
     *
     * @return bool True ketika claim masih dimiliki dan berhasil diperbarui.
     */
    private function updateClaim(OutboxMessage $message, array $attributes): bool
    {
        $updated = OutboxMessage::query()
            ->whereKey($message->id)
            ->where('status', OutboxStatus::PROCESSING->value)
            ->where('locked_at', $message->locked_at)
            ->update($attributes);

        if ($updated !== 1) {
            Log::warning('Outbox claim outcome was ignored because the lock token changed.', [
                'outbox_id' => $message->id,
                'locked_at' => $message->locked_at?->toIso8601String(),
            ]);
        }

        return $updated === 1;
    }

    /**
     * Menghitung exponential backoff berdasarkan attempt dengan batas maksimum operasional.
     *
     * @param  int  $attempts  Jumlah attempt termasuk percobaan yang baru gagal.
     * @param  int  $retryBase  Delay awal retry dalam detik.
     * @param  int  $retryMaximum  Batas delay retry dalam detik.
     *
     * @return int Delay retry dalam detik.
     */
    private function retryDelaySeconds(int $attempts, int $retryBase, int $retryMaximum): int
    {
        $exponent = min(max(0, $attempts - 1), 30);

        return min($retryMaximum, $retryBase * (2 ** $exponent));
    }

    /**
     * Membaca batas numerik outbox sambil menolak konfigurasi yang tidak dapat dicast dengan aman.
     *
     * Konfigurasi diperiksa sebelum claim agar nilai array atau objek tidak meninggalkan pesan processing.
     *
     * @param  string  $key  Nama konfigurasi outbox yang akan dibaca.
     *
     * @return int Nilai scalar atau null yang dikonversi seperti cast konfigurasi sebelumnya.
     *
     * @throws RuntimeException Ketika nilai konfigurasi berupa array atau objek.
     */
    private function integerConfig(string $key): int
    {
        $value = config($key);

        if (! is_scalar($value) && $value !== null) {
            throw new RuntimeException("Invalid outbox configuration: {$key}.");
        }

        return (int) $value;
    }

    /**
     * Membentuk pesan error bounded tanpa menyalin stack trace atau credential ke database.
     *
     * @param  Throwable  $exception  Kegagalan publisher yang akan diringkas.
     *
     * @return string Class dan pesan exception dengan panjang maksimum 4000 karakter.
     */
    private function safeError(Throwable $exception): string
    {
        return Str::limit($exception::class.': '.$exception->getMessage(), 4000, '');
    }
}
