<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Models\SaldoWithdrawal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Membaca ulang status penarikan yang masih processing di Xendit untuk `withdrawal:check`.
 *
 * Saldo penarikan sudah ditahan. Service ini hanya melepas saldo ketika Xendit menyatakan
 * pencairan gagal, atau ketika external ID tetap tidak ditemukan setelah masa tunggu. Waktu
 * saja tidak pernah mengembalikan saldo; penarikan yang terlalu lama hanya dicatat critical.
 *
 * @phpstan-import-type DisbursementSummary from XenditService
 *
 * @phpstan-type CheckSummary array{claimed: int, completed: int, failed: int, processing: int}
 * @phpstan-type StatusSummary array{
 *     counts: array<string, int>,
 *     oldest_processing_at: CarbonImmutable|null,
 *     overdue_hours: int,
 *     overdue: Collection<int, SaldoWithdrawal>
 * }
 */
class WithdrawalCheckService
{
    /**
     * Menyiapkan service dengan pengelola status penarikan dan adapter Xendit.
     *
     * @param  WithdrawalService  $withdrawalService  Menerapkan status akhir dan pengembalian.
     * @param  XenditService  $xenditService  Membaca pencairan berdasarkan external ID.
     */
    public function __construct(
        private WithdrawalService $withdrawalService,
        private XenditService $xenditService,
    ) {}

    /* ========== KETERSEDIAAN TABEL START ========== */

    /**
     * Menentukan apakah migration penarikan sudah tersedia di environment ini.
     *
     * @return bool True ketika tabel `saldo_withdrawals` dapat dibaca.
     */
    public function tableExists(): bool
    {
        // Scheduler bisa berjalan sebelum migration deploy selesai.
        return Schema::hasTable('saldo_withdrawals');
    }

    /* ========== KETERSEDIAAN TABEL END ========== */

    /* ========== PENGECEKAN PENARIKAN START ========== */

    /**
     * Mengambil penarikan yang jatuh tempo lalu mengecek masing-masing ke Xendit.
     *
     * Satu penarikan yang gagal dicek tidak menghentikan penarikan lain dalam batch yang sama.
     *
     * @param  int  $limit  Jumlah maksimum penarikan yang diambil dalam satu run.
     *
     * @return CheckSummary Jumlah yang diambil dan status setiap penarikan setelah dicek.
     */
    public function checkDue(int $limit): array
    {
        // --- step 1 - ambil penarikan yang jatuh tempo - start ---
        $withdrawals = $this->claimDue($limit);
        // --- step 1 - ambil penarikan yang jatuh tempo - end ---

        // --- step 2 - cek setiap penarikan - start ---
        $summary = [
            'claimed' => $withdrawals->count(),
            'completed' => 0,
            'failed' => 0,
            'processing' => 0,
        ];

        foreach ($withdrawals as $withdrawal) {
            $status = $this->checkOne($withdrawal);
            $summary[$status->value]++;
        }
        // --- step 2 - cek setiap penarikan - end ---

        return $summary;
    }

    /**
     * Mengunci penarikan yang jatuh tempo dan menjadwalkan pengecekan berikutnya sebelum HTTP.
     *
     * Jadwal berikutnya ditulis saat klaim, bukan setelah Xendit menjawab. Kalau proses mati di
     * tengah, penarikan menunggu jeda berikutnya alih-alih dicek ulang setiap menit. PostgreSQL
     * memakai `SKIP LOCKED` agar run manual yang bersamaan dengan scheduler mengambil penarikan
     * berbeda. Transaksi selesai sebelum Xendit dipanggil, sehingga tidak ada lock yang ditahan
     * selama request HTTP.
     *
     * @param  int  $limit  Jumlah maksimum penarikan yang diambil.
     *
     * @return Collection<int, SaldoWithdrawal> Penarikan yang diambil run ini.
     */
    private function claimDue(int $limit): Collection
    {
        return DB::transaction(function () use ($limit): Collection {
            // --- step 1 - kunci penarikan yang jatuh tempo - start ---
            $now = CarbonImmutable::now();
            $query = SaldoWithdrawal::query()
                ->where('status', WithdrawalStatus::PROCESSING->value)
                ->where(function (Builder $due) use ($now): void {
                    // Row tanpa jadwal tidak boleh tertahan selamanya, jadi dianggap jatuh tempo.
                    $due->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now);
                })
                ->orderBy('next_check_at')
                ->orderBy('id')
                ->limit($limit);

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $query->lockForUpdate();
            }

            $withdrawals = $query->get();
            // --- step 1 - kunci penarikan yang jatuh tempo - end ---

            // --- step 2 - jadwalkan pengecekan berikutnya - start ---
            foreach ($withdrawals as $withdrawal) {
                $withdrawal->check_attempts++;
                $withdrawal->last_checked_at = $now;
                $withdrawal->next_check_at = $this->withdrawalService->nextCheckAt(
                    $withdrawal->check_attempts,
                    $now,
                );
                $withdrawal->save();
            }
            // --- step 2 - jadwalkan pengecekan berikutnya - end ---

            return $withdrawals;
        });
    }

    /**
     * Membaca satu penarikan di Xendit, menerapkan hasilnya, lalu mencatat yang terlalu lama.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang sudah diklaim run ini.
     *
     * @return WithdrawalStatus Status penarikan setelah pengecekan.
     */
    private function checkOne(SaldoWithdrawal $withdrawal): WithdrawalStatus
    {
        // --- step 1 - baca dan terapkan status di Xendit - start ---
        try {
            $lookup = $this->xenditService->findDisbursementByExternalId($withdrawal->external_id);

            $status = match ($lookup['status']) {
                'found' => $this->applyFound($withdrawal, $lookup['disbursements']),
                'not_found' => $this->applyNotFound($withdrawal),
                'unknown' => WithdrawalStatus::PROCESSING,
            };
        } catch (Throwable $exception) {
            // Penarikan ini dicek lagi sesuai jadwal yang sudah ditulis saat klaim.
            Log::error('Withdrawal check failed; the next scheduled check will retry.', [
                ...$this->withdrawalService->logContext($withdrawal),
                'exception' => $exception::class,
            ]);

            $status = WithdrawalStatus::PROCESSING;
        }
        // --- step 1 - baca dan terapkan status di Xendit - end ---

        // --- step 2 - catat penarikan yang terlalu lama - start ---
        if ($status === WithdrawalStatus::PROCESSING) {
            $this->logIfOverdue($withdrawal);
        }
        // --- step 2 - catat penarikan yang terlalu lama - end ---

        return $status;
    }

    /**
     * Menerapkan pencairan yang ditemukan untuk external ID penarikan.
     *
     * External ID juga menjadi idempotency key, jadi Xendit seharusnya hanya punya satu pencairan.
     * Lebih dari satu berarti ada data yang tidak terduga; saldo tetap ditahan untuk dicek manual.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang dicek.
     * @param  list<DisbursementSummary>  $disbursements  Pencairan dengan external ID tersebut.
     *
     * @return WithdrawalStatus Status penarikan setelah jawaban diterapkan.
     */
    private function applyFound(SaldoWithdrawal $withdrawal, array $disbursements): WithdrawalStatus
    {
        // --- step 1 - tahan penarikan dengan lebih dari satu pencairan - start ---
        if (count($disbursements) !== 1) {
            Log::critical('Several Xendit disbursements share one withdrawal external ID.', [
                ...$this->withdrawalService->logContext($withdrawal),
                'disbursement_count' => count($disbursements),
            ]);

            return WithdrawalStatus::PROCESSING;
        }
        // --- step 1 - tahan penarikan dengan lebih dari satu pencairan - end ---

        return $this->withdrawalService->applyDisbursementState($withdrawal, $disbursements[0]);
    }

    /**
     * Menggagalkan penarikan yang tetap tidak ditemukan di Xendit setelah masa tunggu.
     *
     * Request yang tidak pernah sampai ke Xendit, misalnya karena koneksi gagal atau proses mati
     * setelah saldo ditahan, tidak meninggalkan data di sana. Sebelum masa tunggu habis,
     * not-found tidak dipakai karena Xendit mungkin belum selesai menyimpan pencairan.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang tidak ditemukan.
     *
     * @return WithdrawalStatus FAILED setelah masa tunggu, selain itu PROCESSING.
     */
    private function applyNotFound(SaldoWithdrawal $withdrawal): WithdrawalStatus
    {
        // --- step 1 - tunggu sampai masa tunggu not-found habis - start ---
        $graceMinutes = $this->positiveIntegerConfig('withdrawal.not_found_grace_minutes');
        $graceEndsAt = $withdrawal->created_at?->addMinutes($graceMinutes);

        if ($graceEndsAt === null || $graceEndsAt->isFuture()) {
            return WithdrawalStatus::PROCESSING;
        }
        // --- step 1 - tunggu sampai masa tunggu not-found habis - end ---

        return $this->withdrawalService->markFailed(
            $withdrawal,
            WithdrawalService::NOT_FOUND_FAILURE_CODE,
        );
    }

    /**
     * Menulis satu log critical untuk penarikan yang masih processing lewat batas waktu.
     *
     * Saldo tetap ditahan. Update bersyarat pada `overdue_logged_at` memastikan log hanya ditulis
     * sekali walaupun dua run memeriksa penarikan yang sama.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang masih processing.
     *
     * @return void Hanya menulis penanda dan log.
     */
    private function logIfOverdue(SaldoWithdrawal $withdrawal): void
    {
        // --- step 1 - lewati penarikan yang belum lewat batas - start ---
        $overdueHours = $this->positiveIntegerConfig('withdrawal.overdue_hours');
        $overdueAt = $withdrawal->created_at?->addHours($overdueHours);

        if ($overdueAt === null || $overdueAt->isFuture()) {
            return;
        }
        // --- step 1 - lewati penarikan yang belum lewat batas - end ---

        // --- step 2 - tandai penarikan sekali saja - start ---
        $marked = SaldoWithdrawal::query()
            ->whereKey($withdrawal->id)
            ->where('status', WithdrawalStatus::PROCESSING->value)
            ->whereNull('overdue_logged_at')
            ->update(['overdue_logged_at' => CarbonImmutable::now()]);

        if ($marked !== 1) {
            return;
        }
        // --- step 2 - tandai penarikan sekali saja - end ---

        // --- step 3 - tulis log critical - start ---
        Log::critical('Withdrawal is still processing past the overdue limit; balance is held.', [
            ...$this->withdrawalService->logContext($withdrawal),
            'check_attempts' => $withdrawal->check_attempts,
            'created_at' => $withdrawal->created_at?->toIso8601String(),
        ]);
        // --- step 3 - tulis log critical - end ---
    }

    /* ========== PENGECEKAN PENARIKAN END ========== */

    /* ========== LAPORAN STATUS START ========== */

    /**
     * Merangkum penarikan per status dan daftar penarikan yang terlalu lama untuk dicek manual.
     *
     * Dipakai `withdrawal:status`. Hanya membaca database; Xendit tidak dipanggil.
     *
     * @return StatusSummary Jumlah per status, processing tertua, batas jam, dan yang overdue.
     */
    public function status(): array
    {
        // --- step 1 - hitung penarikan per status - start ---
        $counts = [];

        foreach (WithdrawalStatus::cases() as $status) {
            $counts[$status->value] = SaldoWithdrawal::query()
                ->where('status', $status->value)
                ->count();
        }
        // --- step 1 - hitung penarikan per status - end ---

        // --- step 2 - cari penarikan processing tertua - start ---
        $processing = SaldoWithdrawal::query()
            ->where('status', WithdrawalStatus::PROCESSING->value)
            ->orderBy('created_at');

        $oldest = (clone $processing)->first();
        // --- step 2 - cari penarikan processing tertua - end ---

        // --- step 3 - cari penarikan processing yang overdue - start ---
        $overdueHours = $this->positiveIntegerConfig('withdrawal.overdue_hours');
        $overdueBefore = CarbonImmutable::now()->subHours($overdueHours);

        $overdue = (clone $processing)->where('created_at', '<=', $overdueBefore)->get();
        // --- step 3 - cari penarikan processing yang overdue - end ---

        return [
            'counts' => $counts,
            'oldest_processing_at' => $oldest?->created_at,
            'overdue_hours' => $overdueHours,
            'overdue' => $overdue,
        ];
    }

    /* ========== LAPORAN STATUS END ========== */

    /* ========== KONFIGURASI START ========== */

    /**
     * Membaca konfigurasi penarikan yang wajib berupa bilangan bulat positif.
     *
     * Nilai nol atau salah ketik akan membuat masa tunggu not-found hilang, sehingga saldo bisa
     * dikembalikan sebelum Xendit selesai menyimpan pencairan.
     *
     * @param  string  $key  Key konfigurasi di `config/withdrawal.php`.
     *
     * @return int Nilai konfigurasi.
     *
     * @throws RuntimeException Ketika nilai bukan bilangan bulat positif.
     */
    private function positiveIntegerConfig(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value <= 0) {
            throw new RuntimeException("Configuration [{$key}] must be a positive integer.");
        }

        return $value;
    }

    /* ========== KONFIGURASI END ========== */
}
