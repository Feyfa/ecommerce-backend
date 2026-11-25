<?php

namespace App\Console\Commands;

use App\Services\WithdrawalCheckService;
use Illuminate\Console\Command;

/**
 * Mengecek penarikan saldo yang masih diproses ke Xendit dan menerapkan hasil yang sudah pasti.
 *
 * Dijalankan scheduler setiap menit. Setiap penarikan punya jadwal cek sendiri, jadi satu run
 * hanya mengambil penarikan yang sudah jatuh tempo.
 */
class CheckPendingWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawal:check
        {--limit= : Jumlah maksimum penarikan yang dicek dalam satu run}';

    protected $description =
        'Check processing withdrawals against Xendit and settle confirmed results.';

    /**
     * Menjalankan satu batch pengecekan penarikan yang jatuh tempo.
     *
     * @param  WithdrawalCheckService  $checker  Service pengecekan status penarikan.
     *
     * @return int Kode keluar Artisan; INVALID untuk `--limit` yang bukan bilangan bulat positif.
     */
    public function handle(WithdrawalCheckService $checker): int
    {
        // --- step 1 - lewati environment yang belum migrate - start ---
        // Scheduler bisa berjalan sebelum migration selesai saat deploy.
        if (! $checker->tableExists()) {
            $this->warn('The saldo_withdrawals table is not available; the check was skipped.');

            return self::SUCCESS;
        }
        // --- step 1 - lewati environment yang belum migrate - end ---

        // --- step 2 - baca batas batch - start ---
        $limit = $this->batchLimit();

        if ($limit === null) {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }
        // --- step 2 - baca batas batch - end ---

        // --- step 3 - cek penarikan dan laporkan ringkasan - start ---
        $summary = $checker->checkDue($limit);

        $this->info(sprintf(
            'Withdrawal check completed: claimed=%d completed=%d failed=%d processing=%d.',
            $summary['claimed'],
            $summary['completed'],
            $summary['failed'],
            $summary['processing'],
        ));
        // --- step 3 - cek penarikan dan laporkan ringkasan - end ---

        return self::SUCCESS;
    }

    /**
     * Membaca `--limit`, atau `withdrawal.check_batch_size` ketika opsi tidak diisi.
     *
     * @return int|null Batas batch positif, atau null ketika nilainya tidak valid.
     */
    private function batchLimit(): ?int
    {
        // Batas default dijaga di bawah rate limit Xendit; lihat config/withdrawal.php.
        $input = $this->option('limit') ?? config('withdrawal.check_batch_size');
        $limit = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $limit === false ? null : $limit;
    }
}
