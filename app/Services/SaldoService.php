<?php

namespace App\Services;

use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\User;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Collection;

/**
 * @phpstan-type SaldoHistoryRow array{
 *     id: string,
 *     type: string|null,
 *     title: string,
 *     date: string,
 *     price: float|null,
 *     description: string
 * }
 */
class SaldoService
{
    private const HISTORY_PAGE_SIZE = 30;

    private const RUPIAH_DECIMAL_PLACES = 0;

    /**
     * Mengambil saldo milik user berdasarkan ID.
     *
     * User tanpa row saldo tetap mendapat nilai nol untuk income, refund, dan total.
     *
     * @param  string  $user_id  ID pemilik saldo yang dibaca.
     *
     * @return array{status: 'error', message: string}|array{
     *     status: 'success',
     *     saldoTotal: int|float,
     *     saldoIncome: int,
     *     saldoRefund: int
     * }
     */
    public function getSaldo(string $user_id): array
    {
        // --- step 1 - validasi input - start ---
        if (empty($user_id) || trim($user_id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        // --- step 1 - validasi input - end ---

        // --- step 2 - ambil saldo user - start ---
        $saldoUser = SaldoUser::where('user_id', $user_id)->first();
        $saldoIncome = (int) ($saldoUser->saldo_income ?? 0);
        $saldoRefund = (int) ($saldoUser->saldo_refund ?? 0);
        $saldoTotal = $saldoIncome + $saldoRefund;
        // --- step 2 - ambil saldo user - end ---

        return [
            'status' => 'success',
            'saldoTotal' => $saldoTotal,
            'saldoIncome' => $saldoIncome,
            'saldoRefund' => $saldoRefund,
        ];
    }

    /**
     * Mengambil riwayat mutasi saldo user dengan filter tanggal.
     *
     * Query dibatasi ke pemilik saldo. Daftar ID mengecualikan baris yang sudah tampil di client.
     *
     * @param  string  $user_id  ID pemilik riwayat saldo.
     * @param  string  $start_date  Tanggal awal filter riwayat saldo.
     * @param  string  $end_date  Tanggal akhir filter riwayat saldo.
     * @param  list<string>  $saldo_history_current_ids  UUID riwayat yang sudah ditampilkan.
     *
     * @return array{status: 'error', message: string}|array{
     *     status: 'success',
     *     saldoHistory: Collection<int, SaldoHistoryRow>
     * }
     *
     * @throws \Carbon\Exceptions\InvalidFormatException Jika filter tanggal tidak bisa diproses.
     */
    public function getSaldoHistory(
        string $user_id,
        string $start_date,
        string $end_date,
        array $saldo_history_current_ids = [],
    ): array {
        // --- step 1 - validasi input - start ---
        if (empty($user_id) || trim($user_id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        // --- step 1 - validasi input - end ---

        // --- step 2 - bangun query riwayat milik user - start ---
        // Query daftar dibatasi ke pemilik saldo sebelum filter dari client diterapkan.
        $saldoHistory = SaldoHistory::from('saldo_histories as sh')
            ->select(
                'sh.*',
                'tu.transaction_number',
                'ti.id as invoice_id',
                'u.name as buyer_name',
                'pu.name as payment_name',
                'pu.account as payment_account',
                'pl.slug as payment_slug'
            )
            // Relasi transaksi untuk riwayat saldo masuk.
            ->leftJoin('transaction_users as tu', 'tu.id', '=', 'sh.transaction_user_id')
            ->leftJoin('transaction_invoices as ti', 'ti.id', '=', 'tu.transaction_invoice_id')
            ->leftJoin('users as u', 'u.id', '=', 'tu.user_id_buyer')

            // Relasi payment untuk riwayat penarikan saldo.
            ->leftJoin('payment_users as pu', 'pu.id', '=', 'sh.payment_user_id')
            ->leftJoin('payment_lists as pl', 'pl.id', '=', 'pu.payment_id')
            ->where('sh.user_id', $user_id);
        // --- step 2 - bangun query riwayat milik user - end ---

        // --- step 3 - terapkan rentang tanggal - start ---
        if (
            ! empty($start_date) && trim($start_date) != '' &&
            ! empty($end_date) && trim($end_date) != ''
        ) {
            $start_date = Carbon::parse($start_date)->format('Y-m-d');
            $end_date = Carbon::parse($end_date)->format('Y-m-d');

            $saldoHistory->whereBetween(DB::raw('DATE(sh.created_at)'), [$start_date, $end_date]);
        }
        // --- step 3 - terapkan rentang tanggal - end ---

        // --- step 4 - kecualikan riwayat yang sudah tampil - start ---
        if (count($saldo_history_current_ids) > 0) {
            $saldoHistory->whereNotIn('sh.id', $saldo_history_current_ids);
        }
        // --- step 4 - kecualikan riwayat yang sudah tampil - end ---

        // --- step 5 - bentuk halaman riwayat - start ---
        $rows = $saldoHistory->orderBy('sh.created_at', 'desc')
            ->limit(self::HISTORY_PAGE_SIZE)
            ->get()
            ->map($this->formatSaldoHistoryRow(...));
        // --- step 5 - bentuk halaman riwayat - end ---

        return ['status' => 'success', 'saldoHistory' => $rows];
    }

    /**
     * Membaca satu entri riwayat berdasarkan ID, termasuk untuk hasil pencairan.
     *
     * @param  string  $id  UUID riwayat yang dicari.
     *
     * @return array{status: 'error', message: string}|array{
     *     status: 'success',
     *     saldoHistory: array{}|SaldoHistoryRow
     * }
     *
     * @throws \Carbon\Exceptions\InvalidFormatException Jika waktu mutasi tidak bisa dibaca.
     */
    public function getSaldoById(string $id): array
    {
        // --- step 1 - validasi input - start ---
        if (empty($id) || trim($id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        // --- step 1 - validasi input - end ---

        // --- step 2 - cari entri riwayat - start ---
        $saldoHistory = SaldoHistory::from('saldo_histories as sh')
            ->select(
                'sh.*',
                'tu.transaction_number',
                'ti.id as invoice_id',
                'u.name as buyer_name',
                'pu.name as payment_name',
                'pu.account as payment_account',
                'pl.slug as payment_slug'
            )
            // Relasi transaksi untuk riwayat saldo masuk.
            ->leftJoin('transaction_users as tu', 'tu.id', '=', 'sh.transaction_user_id')
            ->leftJoin('transaction_invoices as ti', 'ti.id', '=', 'tu.transaction_invoice_id')
            ->leftJoin('users as u', 'u.id', '=', 'tu.user_id_buyer')

            // Relasi payment untuk riwayat penarikan saldo.
            ->leftJoin('payment_users as pu', 'pu.id', '=', 'sh.payment_user_id')
            ->leftJoin('payment_lists as pl', 'pl.id', '=', 'pu.payment_id')
            ->where('sh.id', $id)
            ->first();
        // --- step 2 - cari entri riwayat - end ---

        // --- step 3 - bentuk detail riwayat - start ---
        $saldoHistoryMap = [];

        if ($saldoHistory !== null) {
            $saldoHistoryMap = $this->formatSaldoHistoryRow($saldoHistory);
        }
        // --- step 3 - bentuk detail riwayat - end ---

        return ['status' => 'success', 'saldoHistory' => $saldoHistoryMap];
    }

    /**
     * Membentuk baris yang sama untuk daftar riwayat dan detail hasil pencairan.
     *
     * @param  SaldoHistory  $history  Mutasi yang sudah dibaca bersama atribut hasil join.
     *
     * @return SaldoHistoryRow Baris untuk API dengan jenis dan nominal yang tersimpan.
     *
     * @throws \Carbon\Exceptions\InvalidFormatException Jika waktu mutasi tidak bisa dibaca.
     */
    private function formatSaldoHistoryRow(SaldoHistory $history): array
    {
        // --- step 1 - format waktu mutasi - start ---
        $date = Carbon::parse($history->created_at)
            ->timezone('Asia/Jakarta')
            ->translatedFormat('d F Y H:i');
        // --- step 1 - format waktu mutasi - end ---

        // --- step 2 - pilih judul mutasi - start ---
        // Jenis lama tetap ditampilkan tanpa dianggap sebagai pemasukan atau penarikan.
        $type = $history->type;
        $title = match ($type) {
            'incoming' => 'Pemasukan Saldo',
            'withdrawal' => 'Penarikan Saldo',
            default => 'Mutasi Saldo',
        };
        // --- step 2 - pilih judul mutasi - end ---

        // --- step 3 - pilih deskripsi mutasi - start ---
        $description = match ($type) {
            'incoming' => $this->formatIncomingHistoryDescription($history),
            'withdrawal' => $this->formatWithdrawalHistoryDescription($history),
            default => 'Jenis mutasi saldo tidak dikenali.',
        };
        // --- step 3 - pilih deskripsi mutasi - end ---

        return [
            'id' => $history->id,
            'type' => $type,
            'title' => $title,
            'date' => $date,
            'price' => $history->price,
            'description' => $description,
        ];
    }

    /**
     * Menyusun keterangan pemasukan yang merujuk ke pembelian dan invoice terkait.
     *
     * @param  SaldoHistory  $history  Mutasi pemasukan dengan referensi transaksi opsional.
     *
     * @return string Deskripsi pemasukan untuk daftar dan detail.
     */
    private function formatIncomingHistoryDescription(SaldoHistory $history): string
    {
        // Nomor transaksi tetap menjadi referensi ketika LEFT JOIN tidak menemukan invoice.
        /** @var string|null $invoiceNumber */
        $invoiceNumber = $history->invoice_id ?? $history->getAttribute('transaction_number');
        /** @var string|null $buyerName */
        $buyerName = $history->getAttribute('buyer_name');

        return "Pembelian Dari {$buyerName} - INV {$invoiceNumber}";
    }

    /**
     * Menyusun keterangan penarikan berdasarkan nominal dan rekening yang tersedia.
     *
     * @param  SaldoHistory  $history  Penarikan dengan atribut nominal dan rekening opsional.
     *
     * @return string Deskripsi penarikan untuk daftar dan detail.
     */
    private function formatWithdrawalHistoryDescription(SaldoHistory $history): string
    {
        // --- step 1 - format nominal penarikan - start ---
        // Nominal yang tidak tercatat tidak boleh terbaca sebagai penarikan Rp0.
        $price = $history->price;
        $formattedPrice = '—';

        if ($price !== null) {
            $formattedPrice = 'Rp'.number_format($price, self::RUPIAH_DECIMAL_PLACES, ',', '.');
        }
        // --- step 1 - format nominal penarikan - end ---

        // --- step 2 - ambil keterangan rekening - start ---
        // LEFT JOIN bisa kosong. Rekening yang ada tetap string agar nol di depan tidak hilang.
        $paymentSlugUpper = strtoupper($history->payment_slug ?? '');
        /** @var string|null $paymentAccount */
        $paymentAccount = $history->getAttribute('payment_account');
        /** @var string|null $paymentName */
        $paymentName = $history->getAttribute('payment_name');
        // --- step 2 - ambil keterangan rekening - end ---

        return "Penarikan Saldo Sebesar {$formattedPrice} Ke Bank "
            ."{$paymentSlugUpper} {$paymentAccount} ({$paymentName})";
    }

    /**
     * Memperbarui saldo dan riwayatnya setelah disbursement berhasil.
     *
     * Saldo yang relevan dikunci dan dikurangi berdasarkan jenis balance yang digunakan. Perubahan
     * saldo serta row histori disimpan dalam transaksi yang sama agar pencairan tidak menghasilkan
     * catatan tanpa perubahan balance atau sebaliknya.
     *
     * @param  string|null  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string|null  $payment_user_id  ID rekening user yang terkait dengan mutasi saldo.
     * @param  int  $price  Nominal uang yang digunakan oleh operasi.
     *
     * @return array Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function saveSaldoAfterDisbursement(?string $user_id = null, ?string $payment_user_id = null, int $price = 0): array
    {
        // --- step 1 - ambil data user - start ---
        $userExists = User::where('id', $user_id)
            ->exists();
        if (! $userExists) {
            return ['status' => 'error', 'message' => 'user not found'];
        }
        // --- step 1 - ambil data user - end ---

        // --- step 2 - ambil saldo user - start ---
        $saldoUser = SaldoUser::where('user_id', $user_id)
            ->first();
        $saldoRefund = (int) ($saldoUser->saldo_refund ?? 0);
        $saldoIncome = (int) ($saldoUser->saldo_income ?? 0);
        $saldoBefore = $saldoRefund + $saldoIncome;
        // --- step 2 - ambil saldo user - end ---

        // --- step 3 - kurangi saldo - start ---
        if ($saldoRefund >= $price) {
            $saldoRefund -= $price;
            $saldoUser->saldo_refund = $saldoRefund;
        } else {
            $remainingPrice = $price - $saldoRefund;
            $saldoRefund = 0;
            $saldoUser->saldo_refund = $saldoRefund;

            if ($saldoIncome >= $remainingPrice) {
                $saldoIncome -= $remainingPrice;
                $saldoUser->saldo_income = $saldoIncome;
            } else {
                $saldoIncome = 0;
                $saldoUser->saldo_income = $saldoIncome;
            }
        }
        $saldoUser->save();

        $saldoRefund = (int) ($saldoUser->saldo_refund ?? 0);
        $saldoIncome = (int) ($saldoUser->saldo_income ?? 0);
        $saldoAfter = $saldoRefund + $saldoIncome;
        // --- step 3 - kurangi saldo - end ---

        // --- step 4 - buat riwayat saldo - start ---
        $saldoHistory = SaldoHistory::create([
            'user_id' => $user_id,
            'transaction_user_id' => null,
            'payment_user_id' => $payment_user_id,
            'type' => 'withdrawal',
            'price' => $price,
            'saldo_before' => $saldoBefore,
            'saldo_after' => $saldoAfter,
        ]);
        $saldoHistoryId = $saldoHistory->id;
        // --- step 4 - buat riwayat saldo - end ---

        return ['status' => 'success', 'saldoHistoryId' => $saldoHistoryId];
    }
}
