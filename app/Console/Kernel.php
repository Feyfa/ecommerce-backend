<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Mendaftarkan command terjadwal yang harus dijalankan oleh scheduler aplikasi.
     *
     * @param  Schedule  $schedule  Scheduler Laravel yang menerima pendaftaran command.
     *
     * @return void Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule
            ->command('outbox:publish')
            ->everyMinute()
            ->withoutOverlapping(10);

        $schedule
            ->command('outbox:prune --days=7')
            ->dailyAt('02:00')
            ->withoutOverlapping(60);
    }

    /**
     * Memuat command dari direktori console dan mendaftarkan route command tambahan.
     *
     * @return void Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
