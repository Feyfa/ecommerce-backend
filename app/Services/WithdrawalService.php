<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Models\SaldoWithdrawal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mengelola siklus penarikan saldo: menahan saldo, mengirim pencairan, dan menerapkan hasilnya.
 *
 * Saldo dipotong dan row `saldo_withdrawals` dibuat dalam satu transaksi singkat sebelum Xendit
 * dipanggil. Request ke Xendit berjalan tanpa lock database. Status akhir hanya diterapkan pada
 * row yang masih `processing` dan sedang dikunci, sehingga pengiriman pertama dan
 * `withdrawal:check` tidak bisa mengembalikan saldo dua kali.
 *
 * @phpstan-import-type DisbursementSummary from XenditService
 *
 * @phpstan-type HoldResult array{status: 'error', message: string}|array{
 *     status: 'success',
 *     withdrawal: SaldoWithdrawal
 * }
 * @phpstan-type SubmitResult array{status: 'error', message: string}|array{
 *     status: 'success',
 *     withdrawalStatus: WithdrawalStatus
 * }
 * @phpstan-type WithdrawalLogContext array{
 *     withdrawal_id: string,
 *     user_id: string,
 *     amount: int,
 *     external_id: string
 * }
 */
class WithdrawalService
{
    // Kode gagal untuk penarikan yang tidak pernah diterima Xendit; nilainya tampil di
    // `withdrawal:status`, sedangkan kode Xendit memakai huruf besar dan garis bawah yang sama.
    public const NOT_FOUND_FAILURE_CODE = 'NOT_FOUND';

    // Status pencairan Xendit yang sudah final. Status lain, termasuk PENDING, berarti dana
    // belum pasti sampai, jadi saldo tetap ditahan.
    private const PROVIDER_COMPLETED = 'COMPLETED';

    private const PROVIDER_FAILED = 'FAILED';

    private const PROVIDER_PENDING = 'PENDING';

    // Dipakai ketika Xendit menerima request lalu langsung menyatakannya gagal tanpa pesan.
    private const FAILED_MESSAGE = 'Penarikan Gagal, Saldo Sudah Dikembalikan';

    /**
     * Menyiapkan service dengan pengelola saldo dan adapter Xendit.
     *
     * @param  SaldoService  $saldoService  Memotong dan mengembalikan saldo user.
     * @param  XenditService  $xenditService  Mengirim dan membaca pencairan di Xendit.
     */
    public function __construct(
        private SaldoService $saldoService,
        private XenditService $xenditService,
    ) {}

    /* ========== PENAHANAN SALDO START ========== */

    /**
     * Memotong saldo dan mencatat penarikan berstatus processing sebelum Xendit dipanggil.
     *
     * Dipanggil di dalam transaksi controller yang sudah mengunci row saldo. External ID disimpan
     * di sini, sebelum request dikirim, agar penarikan yang jawabannya hilang tetap bisa dicari.
     * Pengecekan pertama dijadwalkan sesuai jeda pertama `withdrawal.check_backoff_minutes`.
     *
     * @param  string  $userId  Pemilik saldo yang menarik dana.
     * @param  string|null  $paymentUserId  Rekening tujuan yang tersimpan milik user.
     * @param  int  $amount  Nominal penarikan dalam rupiah bulat yang sudah lolos validasi.
     * @param  string  $externalId  External ID unik yang juga menjadi idempotency key Xendit.
     *
     * @return HoldResult Row penarikan baru, atau pesan jika saldo tidak bisa dipotong.
     *
     * @throws Throwable Jika query gagal; potongan saldo, riwayat, dan row penarikan di-rollback.
     */
    public function hold(
        string $userId,
        ?string $paymentUserId,
        int $amount,
        string $externalId,
    ): array {
        // Potongan saldo, riwayat, dan row penarikan tersimpan bersama atau tidak sama sekali.
        // Generic DB::transaction melebarkan literal status menjadi string biasa.
        /** @var HoldResult $hold */
        $hold = DB::transaction(
            fn (): array => $this->holdInTransaction($userId, $paymentUserId, $amount, $externalId)
        );

        return $hold;
    }

    /**
     * Memotong saldo lalu membuat row penarikan di transaksi yang sedang berjalan.
     *
     * @param  string  $userId  Pemilik saldo yang menarik dana.
     * @param  string|null  $paymentUserId  Rekening tujuan yang tersimpan milik user.
     * @param  int  $amount  Nominal penarikan dalam rupiah bulat.
     * @param  string  $externalId  External ID unik untuk Xendit.
     *
     * @return HoldResult Row penarikan baru, atau pesan jika saldo tidak bisa dipotong.
     */
    private function holdInTransaction(
        string $userId,
        ?string $paymentUserId,
        int $amount,
        string $externalId,
    ): array {
        // --- step 1 - potong saldo dan tulis riwayat penarikan - start ---
        $deduction = $this->saldoService->deductWithdrawalBalance($userId, $paymentUserId, $amount);

        if ($deduction['status'] === 'error') {
            return ['status' => 'error', 'message' => $deduction['message']];
        }
        // --- step 1 - potong saldo dan tulis riwayat penarikan - end ---

        // --- step 2 - catat penarikan yang menunggu Xendit - start ---
        $withdrawal = SaldoWithdrawal::create([
            'user_id' => $userId,
            'payment_user_id' => $paymentUserId,
            'saldo_history_id' => $deduction['saldoHistoryId'],
            'external_id' => $externalId,
            'amount' => $amount,
            'refund_portion' => $deduction['refundPortion'],
            'income_portion' => $deduction['incomePortion'],
            'status' => WithdrawalStatus::PROCESSING,
            'next_check_at' => $this->nextCheckAt(0, CarbonImmutable::now()),
        ]);
        // --- step 2 - catat penarikan yang menunggu Xendit - end ---

        return ['status' => 'success', 'withdrawal' => $withdrawal];
    }

    /* ========== PENAHANAN SALDO END ========== */

    /* ========== PENGIRIMAN PENCAIRAN START ========== */

    /**
     * Mengirim pencairan untuk penarikan yang sudah ditahan, lalu menerapkan jawaban Xendit.
     *
     * Harus dipanggil setelah transaksi penahanan saldo selesai, supaya lock saldo tidak ditahan
     * selama request HTTP. Penolakan 4xx atau status FAILED mengembalikan saldo dan menghasilkan
     * error untuk user. Jawaban yang hilang membiarkan penarikan tetap processing. Kegagalan
     * database saat menerapkan jawaban hanya dicatat, karena saldo sudah ditahan dan
     * `withdrawal:check` akan membaca ulang statusnya di Xendit.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan processing yang baru ditahan.
     * @param  string  $bankCode  Kode bank Xendit, misalnya `BCA`.
     * @param  string  $holderName  Nama pemilik rekening tujuan.
     * @param  string  $accountNumber  Nomor rekening tujuan, termasuk nol di awal.
     * @param  string  $description  Deskripsi transfer yang dikirim ke Xendit.
     *
     * @return SubmitResult Status penarikan untuk respons sukses, atau pesan penolakan.
     */
    public function submit(
        SaldoWithdrawal $withdrawal,
        string $bankCode,
        string $holderName,
        string $accountNumber,
        string $description,
    ): array {
        // --- step 1 - kirim pencairan ke Xendit - start ---
        $result = $this->xenditService->disbursement(
            external_id: $withdrawal->external_id,
            amount: $withdrawal->amount,
            bank_code: $bankCode,
            account_holder_name: $holderName,
            account_number: $accountNumber,
            description: $description,
        );
        // --- step 1 - kirim pencairan ke Xendit - end ---

        // --- step 2 - terapkan jawaban Xendit - start ---
        try {
            $status = match ($result['status']) {
                'success' => $this->applyDisbursementState($withdrawal, $result['disbursement']),
                'error' => $this->markFailed($withdrawal, $result['error_code']),
                'unknown' => $this->keepProcessingAfterUnknownAnswer($withdrawal),
            };
        } catch (Throwable $exception) {
            Log::error('Withdrawal outcome could not be saved; withdrawal:check will retry.', [
                ...$this->logContext($withdrawal),
                'exception' => $exception::class,
            ]);

            return ['status' => 'success', 'withdrawalStatus' => WithdrawalStatus::PROCESSING];
        }
        // --- step 2 - terapkan jawaban Xendit - end ---

        // --- step 3 - laporkan penarikan yang gagal - start ---
        // Pesan Xendit dipertahankan untuk penolakan 4xx, sama seperti sebelum TOK-88.
        if ($status === WithdrawalStatus::FAILED) {
            $message = $result['status'] === 'error' ? $result['message'] : self::FAILED_MESSAGE;

            return ['status' => 'error', 'message' => $message];
        }
        // --- step 3 - laporkan penarikan yang gagal - end ---

        return ['status' => 'success', 'withdrawalStatus' => $status];
    }

    /**
     * Membiarkan penarikan processing ketika jawaban Xendit tidak bisa dipakai.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang hasilnya belum pasti.
     *
     * @return WithdrawalStatus Selalu PROCESSING; saldo tetap ditahan.
     */
    private function keepProcessingAfterUnknownAnswer(SaldoWithdrawal $withdrawal): WithdrawalStatus
    {
        // Adapter sudah mencatat detail teknis; log ini menambahkan penarikan yang terdampak.
        Log::warning('Withdrawal outcome is unknown; the balance stays held.', [
            ...$this->logContext($withdrawal),
        ]);

        return WithdrawalStatus::PROCESSING;
    }

    /* ========== PENGIRIMAN PENCAIRAN END ========== */

    /* ========== PENERAPAN STATUS AKHIR START ========== */

    /**
     * Menerapkan status pencairan dari Xendit pada penarikan yang masih diproses.
     *
     * COMPLETED menyelesaikan penarikan, FAILED mengembalikan saldo, dan status lain membiarkannya
     * processing sambil menyimpan ID pencairan Xendit bila ada. Status yang tidak dikenal dicatat
     * sebagai `warning`, karena saldo tidak boleh dilepas berdasarkan tebakan.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang statusnya dibaca dari Xendit.
     * @param  DisbursementSummary  $disbursement  ID, status, dan kode gagal dari Xendit.
     *
     * @return WithdrawalStatus Status penarikan setelah jawaban diterapkan.
     *
     * @throws Throwable Jika query gagal; perubahan status dan saldo di-rollback bersama.
     */
    public function applyDisbursementState(
        SaldoWithdrawal $withdrawal,
        array $disbursement,
    ): WithdrawalStatus {
        // --- step 1 - selesaikan status akhir dari Xendit - start ---
        $providerStatus = $disbursement['status'];
        $providerId = $disbursement['id'];

        if ($providerStatus === self::PROVIDER_COMPLETED) {
            return $this->markCompleted($withdrawal, $providerId);
        }

        if ($providerStatus === self::PROVIDER_FAILED) {
            return $this->markFailed($withdrawal, $disbursement['failure_code'], $providerId);
        }
        // --- step 1 - selesaikan status akhir dari Xendit - end ---

        // --- step 2 - catat status yang tidak dikenal - start ---
        if ($providerStatus !== self::PROVIDER_PENDING) {
            Log::warning('Xendit returned an unrecognized disbursement status.', [
                ...$this->logContext($withdrawal),
                'provider_status' => $providerStatus,
            ]);
        }
        // --- step 2 - catat status yang tidak dikenal - end ---

        return $this->recordProviderId($withdrawal, $providerId);
    }

    /**
     * Menandai penarikan gagal lalu mengembalikan saldo ke bucket asalnya dalam satu transaksi.
     *
     * Dipanggil untuk penolakan 4xx, status FAILED dari Xendit, dan penarikan yang tidak
     * ditemukan setelah masa tunggu. Penarikan yang sudah tidak processing dibiarkan apa adanya,
     * sehingga saldo tidak pernah dikembalikan dua kali.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang gagal.
     * @param  string|null  $failureCode  Kode error atau kode gagal dari Xendit, atau NOT_FOUND.
     * @param  string|null  $providerId  ID pencairan Xendit bila jawaban membawanya.
     *
     * @return WithdrawalStatus FAILED, atau status akhir lain yang sudah tersimpan lebih dulu.
     *
     * @throws Throwable Jika query gagal; status dan saldo di-rollback bersama.
     */
    public function markFailed(
        SaldoWithdrawal $withdrawal,
        ?string $failureCode,
        ?string $providerId = null,
    ): WithdrawalStatus {
        // Pengembalian dan status gagal berada di transaksi yang sama agar tidak pernah terpisah.
        return $this->settle($withdrawal, function (SaldoWithdrawal $locked) use (
            $failureCode,
            $providerId,
        ): void {
            // --- step 1 - kembalikan saldo - start ---
            $refundHistoryId = $this->saldoService->refundWithdrawalBalance(
                $locked->user_id,
                $locked->payment_user_id,
                $locked->refund_portion,
                $locked->income_portion,
            );
            // --- step 1 - kembalikan saldo - end ---

            // --- step 2 - tandai penarikan gagal - start ---
            $locked->status = WithdrawalStatus::FAILED;
            $locked->failure_code = $failureCode;
            $locked->provider_disbursement_id ??= $providerId;
            $locked->refund_saldo_history_id = $refundHistoryId;
            $locked->settled_at = CarbonImmutable::now();
            // --- step 2 - tandai penarikan gagal - end ---
        });
    }

    /**
     * Menandai penarikan selesai setelah Xendit menyatakan dana terkirim.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang selesai.
     * @param  string|null  $providerId  ID pencairan Xendit.
     *
     * @return WithdrawalStatus COMPLETED, atau status akhir lain yang sudah tersimpan lebih dulu.
     */
    private function markCompleted(
        SaldoWithdrawal $withdrawal,
        ?string $providerId,
    ): WithdrawalStatus {
        $complete = function (SaldoWithdrawal $locked) use ($providerId): void {
            $locked->status = WithdrawalStatus::COMPLETED;
            $locked->provider_disbursement_id ??= $providerId;
            $locked->settled_at = CarbonImmutable::now();
        };

        return $this->settle($withdrawal, $complete);
    }

    /**
     * Menyimpan ID pencairan Xendit pada penarikan yang masih menunggu hasil akhir.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang masih PENDING di Xendit.
     * @param  string|null  $providerId  ID pencairan Xendit bila jawaban membawanya.
     *
     * @return WithdrawalStatus PROCESSING, atau status akhir yang sudah tersimpan lebih dulu.
     */
    private function recordProviderId(
        SaldoWithdrawal $withdrawal,
        ?string $providerId,
    ): WithdrawalStatus {
        // Status tetap processing; hanya ID pertama dari Xendit yang disimpan.
        $recordId = function (SaldoWithdrawal $locked) use ($providerId): void {
            $locked->provider_disbursement_id ??= $providerId;
        };

        return $this->settle($withdrawal, $recordId);
    }

    /**
     * Mengunci row penarikan dan menerapkan perubahan hanya jika statusnya masih processing.
     *
     * Lock diambil pada row penarikan lebih dulu, lalu pada `saldo_users` saat pengembalian.
     * Penahanan saldo hanya menyisipkan row penarikan baru dan tidak mengunci row penarikan lain,
     * sehingga urutan ini tidak membentuk deadlock dengan penarikan maupun kredit income.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang akan diubah.
     * @param  callable(SaldoWithdrawal): void  $change  Perubahan pada row yang sudah dikunci.
     *
     * @return WithdrawalStatus Status yang tersimpan setelah transaksi selesai.
     *
     * @throws Throwable Jika query gagal; seluruh perubahan di-rollback.
     */
    private function settle(SaldoWithdrawal $withdrawal, callable $change): WithdrawalStatus
    {
        return DB::transaction(function () use ($withdrawal, $change): WithdrawalStatus {
            // --- step 1 - kunci row penarikan terbaru - start ---
            $locked = SaldoWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            // Pemanggil lain mungkin sudah menyelesaikannya sejak row ini dibaca.
            if ($locked->status !== WithdrawalStatus::PROCESSING) {
                return $locked->status;
            }
            // --- step 1 - kunci row penarikan terbaru - end ---

            // --- step 2 - terapkan perubahan - start ---
            $change($locked);
            $locked->save();
            // --- step 2 - terapkan perubahan - end ---

            return $locked->status;
        });
    }

    /* ========== PENERAPAN STATUS AKHIR END ========== */

    /* ========== JADWAL PENGECEKAN START ========== */

    /**
     * Menghitung waktu pengecekan berikutnya dari jumlah pengecekan yang sudah dijadwalkan.
     *
     * Jeda diambil dari `withdrawal.check_backoff_minutes`. Jeda terakhir dipakai untuk semua
     * pengecekan berikutnya, sehingga penarikan lama dicek paling jarang sesuai nilai itu.
     *
     * @param  int  $checksDone  Jumlah pengecekan yang sudah diambil, nol saat penarikan dibuat.
     * @param  CarbonImmutable  $from  Waktu acuan, biasanya sekarang.
     *
     * @return CarbonImmutable Waktu paling awal penarikan boleh dicek lagi.
     */
    public function nextCheckAt(int $checksDone, CarbonImmutable $from): CarbonImmutable
    {
        // Jeda bertambah agar penarikan yang lama PENDING tidak menghabiskan rate limit Xendit.
        /** @var list<int> $backoffMinutes */
        $backoffMinutes = config('withdrawal.check_backoff_minutes');
        $lastIndex = count($backoffMinutes) - 1;

        return $from->addMinutes($backoffMinutes[min($checksDone, $lastIndex)]);
    }

    /* ========== JADWAL PENGECEKAN END ========== */

    /* ========== KONTEKS LOG START ========== */

    /**
     * Menyusun konteks log penarikan tanpa nomor rekening maupun nama pemilik.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan yang dicatat.
     *
     * @return WithdrawalLogContext ID yang cukup untuk mencari penarikan di database dan Xendit.
     */
    public function logContext(SaldoWithdrawal $withdrawal): array
    {
        // Satu tempat untuk field log agar data rekening tidak pernah ikut tercatat.
        return [
            'withdrawal_id' => $withdrawal->id,
            'user_id' => $withdrawal->user_id,
            'amount' => $withdrawal->amount,
            'external_id' => $withdrawal->external_id,
        ];
    }

    /* ========== KONTEKS LOG END ========== */
}
