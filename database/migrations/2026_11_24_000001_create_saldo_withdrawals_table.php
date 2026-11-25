<?php

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel status penarikan saldo yang dikirim ke Xendit.
     *
     * `saldo_histories` tetap menjadi buku mutasi saldo, sedangkan tabel ini menyimpan status
     * pencairan di provider dan jadwal pengecekan ulang `withdrawal:check`. External ID disimpan
     * sebelum request dikirim, sehingga penarikan yang jawabannya hilang masih bisa dicari di
     * Xendit. Nomor rekening dan nama pemilik sengaja tidak disimpan; datanya tetap di
     * `payment_users`.
     *
     * @return void Perubahan diterapkan langsung pada schema database aktif.
     */
    public function up(): void
    {
        Schema::create('saldo_withdrawals', function (Blueprint $table): void {
            // --- step 1 - identitas dan baris riwayat - start ---
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('payment_user_id')->nullable();
            $table->uuid('saldo_history_id')->unique();
            $table->uuid('refund_saldo_history_id')->nullable();
            // --- step 1 - identitas dan baris riwayat - end ---

            // --- step 2 - referensi Xendit - start ---
            // External ID juga dikirim sebagai idempotency key, jadi harus unik.
            $table->string('external_id', 100)->unique();
            $table->string('provider_disbursement_id', 100)->nullable();
            // --- step 2 - referensi Xendit - end ---

            // --- step 3 - nominal dalam rupiah bulat - start ---
            // Porsi refund dan income disimpan agar pengembalian masuk ke bucket asalnya.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('refund_portion');
            $table->unsignedBigInteger('income_portion');
            // --- step 3 - nominal dalam rupiah bulat - end ---

            // --- step 4 - status pencairan - start ---
            $table->string('status', 20)->default(WithdrawalStatus::PROCESSING->value);
            $table->string('failure_code', 100)->nullable();
            $table->unsignedSmallInteger('check_attempts')->default(0);
            // --- step 4 - status pencairan - end ---

            // --- step 5 - kolom waktu - start ---
            // Kolom waktu sengaja tanpa zona waktu, sama seperti `saldo_histories`. Laravel
            // menulis jam Asia/Jakarta tanpa offset. Pada `timestamptz`, PostgreSQL membaca
            // jam itu dengan zona sesi koneksi. Dengan sesi UTC, `created_at` terbaca 7 jam di
            // masa depan, sehingga masa tunggu not-found 10 menit baru habis setelah sekitar
            // 7 jam 10 menit dan saldo user tertahan selama itu. Zona sesi bergantung pada
            // server database, karena `config/database.php` maupun service PostgreSQL di CI
            // dan Docker Compose tidak mengaturnya.
            $table->timestamp('next_check_at', 6)->nullable();
            $table->timestamp('last_checked_at', 6)->nullable();
            $table->timestamp('overdue_logged_at', 6)->nullable();
            $table->timestamp('settled_at', 6)->nullable();
            $table->timestamps(6);
            // --- step 5 - kolom waktu - end ---

            // Claim scheduler mencari status processing yang sudah jatuh tempo.
            $table->index(['status', 'next_check_at'], 'saldo_withdrawals_due_index');
        });
    }

    /**
     * Menghapus tabel status penarikan ketika migration dibatalkan.
     *
     * @return void Perubahan diterapkan langsung pada schema database aktif.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldo_withdrawals');
    }
};
