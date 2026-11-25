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
     * @return void Tidak mengembalikan nilai; semua jadwal terdaftar setelah method selesai.
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

        // Setiap penarikan punya jadwal cek sendiri; run per menit hanya mengambil yang jatuh
        // tempo. Satu run bisa lebih dari satu menit saat Xendit lambat, jadi run berikutnya
        // dilewati sampai run itu selesai. Mutex kedaluwarsa setelah 10 menit agar proses yang
        // mati tidak menghentikan pengecekan selamanya.
        $schedule
            ->command('withdrawal:check')
            ->everyMinute()
            ->withoutOverlapping(10);
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
