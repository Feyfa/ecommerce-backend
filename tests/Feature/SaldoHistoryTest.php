<?php

namespace Tests\Feature;

use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\SaldoHistory;
use App\Models\TransactionInvoice;
use App\Models\TransactionUser;
use App\Models\User;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menjaga deskripsi riwayat saldo ketika referensi hasil LEFT JOIN tersedia atau kosong.
 */
class SaldoHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const HISTORY_PRICE = 125_000;

    /**
     * Memastikan ID invoice diprioritaskan sebelum memakai nomor transaksi.
     *
     * @return void Memeriksa deskripsi yang sama pada daftar dan detail saldo.
     */
    public function test_incoming_description_prefers_invoice_id_then_falls_back(): void
    {
        // --- step 1 - siapkan transaksi saldo masuk - start ---
        $seller = User::factory()->create();
        $buyer = User::factory()->create(['name' => 'Pembeli Uji']);
        $invoice = TransactionInvoice::create(['user_id_buyer' => $buyer->id]);
        $transaction = TransactionUser::create([
            'user_id_seller' => $seller->id,
            'user_id_buyer' => $buyer->id,
            'transaction_invoice_id' => $invoice->id,
            'transaction_number' => 'TRX-UJI',
        ]);
        // --- step 1 - siapkan transaksi saldo masuk - end ---

        // --- step 2 - periksa referensi invoice pada riwayat - start ---
        $history = SaldoHistory::create([
            'user_id' => $seller->id,
            'transaction_user_id' => $transaction->id,
            'type' => 'incoming',
            'price' => self::HISTORY_PRICE,
        ]);

        $this->assertHistoryDescription($history, "Pembelian Dari Pembeli Uji - INV {$invoice->id}");
        // --- step 2 - periksa referensi invoice pada riwayat - end ---

        // --- step 3 - periksa fallback nomor transaksi - start ---
        $transaction->update(['transaction_invoice_id' => null]);

        $this->assertHistoryDescription($history, 'Pembelian Dari Pembeli Uji - INV TRX-UJI');
        // --- step 3 - periksa fallback nomor transaksi - end ---
    }

    /**
     * Memastikan deskripsi penarikan mempertahankan detail rekening dari hasil join.
     *
     * @return void Memeriksa format penarikan dengan referensi rekening tersedia dan kosong.
     */
    public function test_withdrawal_description_preserves_bank_details_and_missing_join(): void
    {
        // --- step 1 - siapkan rekening penarikan - start ---
        $owner = User::factory()->create();
        $bank = PaymentList::create([
            'type' => 'withdrawal',
            'slug' => 'bca',
        ]);
        $account = PaymentUser::create([
            'user_id' => $owner->id,
            'payment_id' => $bank->id,
            'account' => '001234',
            'name' => 'Pemilik Uji',
        ]);
        // --- step 1 - siapkan rekening penarikan - end ---

        // --- step 2 - periksa deskripsi penarikan - start ---
        $history = SaldoHistory::create([
            'user_id' => $owner->id,
            'payment_user_id' => $account->id,
            'type' => 'withdrawal',
            'price' => self::HISTORY_PRICE,
        ]);

        $this->assertHistoryDescription(
            $history,
            'Penarikan Saldo Sebesar Rp125.000 Ke Bank BCA 001234 (Pemilik Uji)'
        );
        // --- step 2 - periksa deskripsi penarikan - end ---

        // --- step 3 - periksa deskripsi ketika join rekening kosong - start ---
        $history->update(['payment_user_id' => null]);

        // Respons lama mempertahankan spasi untuk slug dan nomor rekening yang kosong.
        $this->assertHistoryDescription($history, 'Penarikan Saldo Sebesar Rp125.000 Ke Bank   ()');
        // --- step 3 - periksa deskripsi ketika join rekening kosong - end ---
    }

    /**
     * Memeriksa deskripsi riwayat melalui kedua jalur baca untuk fixture yang sama.
     *
     * @param  SaldoHistory  $history  Satu-satunya riwayat milik pengguna uji.
     * @param  string  $expectedDescription  Teks yang perlu dipertahankan persis.
     *
     * @return void Memverifikasi isi daftar dan detail riwayat.
     */
    private function assertHistoryDescription(SaldoHistory $history, string $expectedDescription): void
    {
        // --- step 1 - ambil riwayat dari service - start ---
        $service = app(SaldoService::class);
        $listedHistory = $service->getSaldoHistory($history->user_id, '', '');
        $detailedHistory = $service->getSaldoById($history->id);
        // --- step 1 - ambil riwayat dari service - end ---

        // --- step 2 - bandingkan deskripsi riwayat - start ---
        $this->assertSame('success', $listedHistory['status']);
        $this->assertSame('success', $detailedHistory['status']);

        $row = $listedHistory['saldoHistory']->sole();
        $this->assertSame($history->id, $row['id']);
        $this->assertSame($expectedDescription, $row['description']);
        $this->assertSame($row, $detailedHistory['saldoHistory']);
        // --- step 2 - bandingkan deskripsi riwayat - end ---
    }
}
