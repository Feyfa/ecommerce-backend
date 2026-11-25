<?php

namespace App\Console\Commands;

use App\Models\SaldoWithdrawal;
use App\Services\WithdrawalCheckService;
use Illuminate\Console\Command;

/**
 * Menampilkan ringkasan penarikan saldo untuk tim internal tanpa mengubah data.
 *
 * Penarikan yang masih diproses lewat batas `withdrawal.overdue_hours` sudah ditulis sebagai
 * log critical. Command ini adalah tempat mengecek penarikan tersebut secara manual. Nomor
 * rekening dan nama pemilik tidak ditampilkan.
 */
class ShowWithdrawalStatusCommand extends Command
{
    protected $signature = 'withdrawal:status';

    protected $description =
        'Show withdrawal counts by status and withdrawals processing too long.';

    /**
     * Menampilkan jumlah per status, processing tertua, dan tabel penarikan overdue.
     *
     * @param  WithdrawalCheckService  $checker  Service yang membaca ringkasan penarikan.
     *
     * @return int Kode keluar Artisan setelah ringkasan ditampilkan atau tabel belum tersedia.
     */
    public function handle(WithdrawalCheckService $checker): int
    {
        // --- step 1 - lewati environment yang belum migrate - start ---
        if (! $checker->tableExists()) {
            $this->warn('The saldo_withdrawals table is not available.');

            return self::SUCCESS;
        }
        // --- step 1 - lewati environment yang belum migrate - end ---

        // --- step 2 - tampilkan jumlah per status - start ---
        $status = $checker->status();
        $countRows = [];

        foreach ($status['counts'] as $name => $count) {
            $countRows[] = [$name, $count];
        }

        $this->table(['Status', 'Count'], $countRows);
        // --- step 2 - tampilkan jumlah per status - end ---

        // --- step 3 - tampilkan penarikan yang tertahan lama - start ---
        $oldestProcessing = $status['oldest_processing_at']?->toIso8601String() ?? 'none';
        $overdueCount = $status['overdue']->count();

        $this->line("Oldest processing: {$oldestProcessing}");
        $this->line("Processing longer than {$status['overdue_hours']} hours: {$overdueCount}");

        if ($status['overdue']->isNotEmpty()) {
            $this->table(
                ['ID', 'User ID', 'Amount', 'External ID', 'Created', 'Checks', 'Last checked'],
                $status['overdue']->map($this->overdueRow(...))->all(),
            );
        }
        // --- step 3 - tampilkan penarikan yang tertahan lama - end ---

        return self::SUCCESS;
    }

    /**
     * Menyusun satu baris tabel untuk penarikan overdue.
     *
     * @param  SaldoWithdrawal  $withdrawal  Penarikan processing yang lewat batas waktu.
     *
     * @return list<int|string> ID yang cukup untuk mencari penarikan di database dan di Xendit.
     */
    private function overdueRow(SaldoWithdrawal $withdrawal): array
    {
        // Data rekening sengaja tidak ditampilkan; external ID cukup untuk mencari di Xendit.
        return [
            $withdrawal->id,
            $withdrawal->user_id,
            $withdrawal->amount,
            $withdrawal->external_id,
            $withdrawal->created_at?->toIso8601String() ?? '-',
            $withdrawal->check_attempts,
            $withdrawal->last_checked_at?->toIso8601String() ?? '-',
        ];
    }
}
