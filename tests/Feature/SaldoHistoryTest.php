<?php

namespace Tests\Feature;

use App\Enums\WithdrawalStatus;
use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\SaldoHistory;
use App\Models\SaldoWithdrawal;
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
     * Menjaga agar jenis mutasi lama tidak menyembunyikan baris valid dalam daftar yang sama.
     *
     * @return void Kedua pembaca memakai label netral sambil mempertahankan jenis mutasi asli.
     */
    public function test_unknown_types_keep_valid_history_visible(): void
    {
        // --- step 1 - siapkan mutasi valid - start ---
        $owner = User::factory()->create();
        $incoming = SaldoHistory::create([
            'user_id' => $owner->id,
            'type' => 'incoming',
            'price' => self::HISTORY_PRICE,
        ]);
        // --- step 1 - siapkan mutasi valid - end ---

        // --- step 2 - siapkan mutasi dengan jenis lama - start ---
        $legacyRows = [];

        foreach (['legacy_adjustment', null] as $type) {
            $legacyRows[] = SaldoHistory::create([
                'user_id' => $owner->id,
                'type' => $type,
                'price' => self::HISTORY_PRICE,
            ]);
        }
        // --- step 2 - siapkan mutasi dengan jenis lama - end ---

        // --- step 3 - periksa daftar riwayat - start ---
        $service = app(SaldoService::class);
        $result = $service->getSaldoHistory($owner->id, '', '');

        $this->assertSame('success', $result['status']);

        $rows = $result['saldoHistory']->keyBy('id');

        $this->assertCount(count($legacyRows) + 1, $rows);
        $this->assertSame('Pemasukan Saldo', $rows[$incoming->id]['title']);
        // --- step 3 - periksa daftar riwayat - end ---

        // --- step 4 - periksa fallback untuk jenis lama - start ---
        foreach ($legacyRows as $legacyRow) {
            $row = $rows[$legacyRow->id];
            $detail = $service->getSaldoById($legacyRow->id);

            $this->assertSame($legacyRow->type, $row['type']);
            $this->assertSame('Mutasi Saldo', $row['title']);
            $this->assertSame('Jenis mutasi saldo tidak dikenali.', $row['description']);
            $this->assertEquals(self::HISTORY_PRICE, $row['price']);
            $this->assertSame('success', $detail['status']);
            $this->assertSame($row, $detail['saldoHistory']);
        }
        // --- step 4 - periksa fallback untuk jenis lama - end ---
    }

    /**
     * Membedakan nominal riwayat yang tidak tercatat dari penarikan bernilai nol.
     *
     * @return void Tampilan nominal berubah sesuai nilai yang tersimpan.
     */
    public function test_withdrawal_description_distinguishes_null_price_from_zero(): void
    {
        // --- step 1 - siapkan penarikan tanpa nominal - start ---
        $owner = User::factory()->create();
        $history = SaldoHistory::create([
            'user_id' => $owner->id,
            'type' => 'withdrawal',
            'price' => null,
        ]);
        // --- step 1 - siapkan penarikan tanpa nominal - end ---

        // --- step 2 - periksa penanda nominal kosong - start ---
        $row = $this->assertHistoryDescription(
            $history,
            'Penarikan Saldo Sebesar — Ke Bank   ()',
        );

        $this->assertNull($row['price']);
        // --- step 2 - periksa penanda nominal kosong - end ---

        // --- step 3 - periksa pemformatan nominal nol - start ---
        $history->update(['price' => 0]);

        $row = $this->assertHistoryDescription(
            $history,
            'Penarikan Saldo Sebesar Rp0 Ke Bank   ()',
        );

        $this->assertEquals(0, $row['price']);
        // --- step 3 - periksa pemformatan nominal nol - end ---
    }

    /**
     * Menampilkan status penarikan dan baris pengembalian saldo pada daftar dan detail.
     *
     * @return void Penarikan baru membawa status, sedangkan penarikan lama dan pengembalian null.
     */
    public function test_withdrawal_rows_carry_their_status_and_refunds_are_described(): void
    {
        // --- step 1 - siapkan rekening dan riwayat penarikan - start ---
        $owner = User::factory()->create();
        $bank = PaymentList::create(['type' => 'withdrawal', 'slug' => 'bca']);
        $account = PaymentUser::create([
            'user_id' => $owner->id,
            'payment_id' => $bank->id,
            'account' => '001234',
            'name' => 'Pemilik Uji',
        ]);
        $rowFields = [
            'user_id' => $owner->id,
            'payment_user_id' => $account->id,
            'price' => self::HISTORY_PRICE,
        ];

        $withdrawal = SaldoHistory::create([...$rowFields, 'type' => 'withdrawal']);
        $refund = SaldoHistory::create([...$rowFields, 'type' => 'withdrawal_refund']);
        $legacyWithdrawal = SaldoHistory::create([...$rowFields, 'type' => 'withdrawal']);
        // --- step 1 - siapkan rekening dan riwayat penarikan - end ---

        // --- step 2 - siapkan status penarikan yang gagal - start ---
        // Penarikan lama sebelum TOK-88 sengaja tidak diberi row status.
        SaldoWithdrawal::create([
            'user_id' => $owner->id,
            'payment_user_id' => $account->id,
            'saldo_history_id' => $withdrawal->id,
            'refund_saldo_history_id' => $refund->id,
            'external_id' => 'dis-bca-riwayat-uji',
            'amount' => self::HISTORY_PRICE,
            'refund_portion' => 0,
            'income_portion' => self::HISTORY_PRICE,
            'status' => WithdrawalStatus::FAILED,
        ]);
        // --- step 2 - siapkan status penarikan yang gagal - end ---

        // --- step 3 - baca daftar riwayat - start ---
        $service = app(SaldoService::class);
        $rows = $service->getSaldoHistory($owner->id, '', '')['saldoHistory']->keyBy('id');
        // --- step 3 - baca daftar riwayat - end ---

        // --- step 4 - periksa status pada setiap baris - start ---
        $this->assertSame('failed', $rows[$withdrawal->id]['withdrawal_status']);
        $this->assertNull($rows[$refund->id]['withdrawal_status']);
        $this->assertNull($rows[$legacyWithdrawal->id]['withdrawal_status']);
        $this->assertSame(
            $rows[$withdrawal->id],
            $service->getSaldoById($withdrawal->id)['saldoHistory'],
        );
        // --- step 4 - periksa status pada setiap baris - end ---

        // --- step 5 - periksa baris pengembalian - start ---
        $this->assertSame('Pengembalian Saldo', $rows[$refund->id]['title']);
        $this->assertSame(
            'Pengembalian Penarikan Saldo Sebesar Rp125.000 Dari Bank BCA 001234 (Pemilik Uji)',
            $rows[$refund->id]['description'],
        );
        // --- step 5 - periksa baris pengembalian - end ---
    }

    /**
     * Menjaga bentuk detail saat ID kosong atau tidak punya baris riwayat.
     *
     * @return void Kedua kondisi memakai respons yang sudah dipakai pemanggil.
     */
    public function test_history_detail_keeps_missing_id_responses(): void
    {
        // --- step 1 - siapkan ID tanpa riwayat - start ---
        // ID user menyediakan UUID valid tanpa membuat baris riwayat.
        $owner = User::factory()->create();
        $service = app(SaldoService::class);
        // --- step 1 - siapkan ID tanpa riwayat - end ---

        // --- step 2 - periksa respons detail kosong - start ---
        $this->assertSame(
            ['status' => 'error', 'message' => 'user id cannot be empty'],
            $service->getSaldoById(''),
        );
        $this->assertSame(
            ['status' => 'success', 'saldoHistory' => []],
            $service->getSaldoById($owner->id),
        );
        // --- step 2 - periksa respons detail kosong - end ---
    }

    /**
     * Memeriksa deskripsi riwayat melalui kedua jalur baca untuk fixture yang sama.
     *
     * @param  SaldoHistory  $history  Satu-satunya riwayat milik pengguna uji.
     * @param  string  $expectedDescription  Teks yang perlu dipertahankan persis.
     *
     * @return array<string, mixed> Baris terverifikasi untuk pemeriksaan lanjutan.
     */
    private function assertHistoryDescription(
        SaldoHistory $history,
        string $expectedDescription,
    ): array {
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

        return $row;
    }
}
