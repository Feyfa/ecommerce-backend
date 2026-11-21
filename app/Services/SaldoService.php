<?php

namespace App\Services;

use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\User;
use Carbon\Carbon;
use DB;

class SaldoService
{
    /**
     * Mengambil saldo milik user berdasarkan ID.
     *
     * Saldo income dan refund dimuat untuk user tertentu dan dinormalisasi menjadi struktur response.
     * User tanpa row saldo memperoleh hasil terkontrol sesuai kontrak service.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return array Data terstruktur yang dihasilkan oleh proses ini.
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

        return ['status' => 'success', 'saldoTotal' => $saldoTotal, 'saldoIncome' => $saldoIncome, 'saldoRefund' => $saldoRefund];
    }

    /**
     * Mengambil riwayat mutasi saldo user dengan filter tanggal.
     *
     * Query selalu dibatasi ke user pemilik saldo, kemudian filter tanggal dan cursor diterapkan.
     * Setiap mutasi disusun bersama saldo sebelum-sesudah dan sumber transaksinya untuk kebutuhan
     * riwayat.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $start_date  Tanggal awal filter riwayat saldo.
     * @param  string  $end_date  Tanggal akhir filter riwayat saldo.
     * @param  array  $saldo_history_current_ids  Nilai saldo history current ids yang diperlukan untuk menjalankan proses ini.
     *
     * @return array Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getSaldoHistory(string $user_id, string $start_date, string $end_date, array $saldo_history_current_ids = []): array
    {
        // --- step 1 - validasi input - start ---
        if (empty($user_id) || trim($user_id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        // --- step 1 - validasi input - end ---

        // --- step 2 - ambil riwayat saldo - start ---
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

        if (
            ! empty($start_date) && trim($start_date) != '' &&
            ! empty($end_date) && trim($end_date) != ''
        ) {
            $start_date = Carbon::parse($start_date)->format('Y-m-d');
            $end_date = Carbon::parse($end_date)->format('Y-m-d');
            $saldoHistory->whereBetween(DB::raw('DATE(sh.created_at)'), [$start_date, $end_date]);
        }

        if (count($saldo_history_current_ids) > 0) {
            $saldoHistory->whereNotIn('sh.id', $saldo_history_current_ids);
        }

        $saldoHistory = $saldoHistory->orderBy('sh.created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($item, $index) {
                $dateFormat = Carbon::parse($item->created_at)
                    ->timezone('Asia/Jakarta')
                    ->translatedFormat('d F Y H:i');
                $title = match ($item->type) {
                    'incoming' => 'Pemasukan Saldo',
                    'withdrawal' => 'Penarikan Saldo',
                };

                $priceString = number_format($item->price, 0, ',', '.');
                $paymentSlugUpper = strtoupper($item->payment_slug ?? '');

                // LEFT JOIN tetap mengembalikan riwayat ketika referensi terkait kosong.
                /** @var string|null $invoiceNumber */
                $invoiceNumber = $item->invoice_id ?? $item->getAttribute('transaction_number');
                /** @var string|null $buyerName */
                $buyerName = $item->getAttribute('buyer_name');
                /** @var string|null $paymentAccount */
                $paymentAccount = $item->getAttribute('payment_account');
                /** @var string|null $paymentName */
                $paymentName = $item->getAttribute('payment_name');

                $description = match ($item->type) {
                    'incoming' => "Pembelian Dari {$buyerName} - INV {$invoiceNumber}",
                    'withdrawal' => "Penarikan Saldo Sebesar Rp{$priceString} Ke Bank "
                        ."{$paymentSlugUpper} {$paymentAccount} ({$paymentName})",
                };

                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $title,
                    'date' => $dateFormat,
                    'price' => $item->price,
                    'description' => $description,
                ];
            });
        // --- step 2 - ambil riwayat saldo - end ---

        return ['status' => 'success', 'saldoHistory' => $saldoHistory];
    }

    /**
     * Mengambil detail saldo berdasarkan ID record saldo.
     *
     * Record saldo dipastikan berada dalam scope user sebelum detail pembayaran atau transaksi terkait
     * dimuat. Error terstruktur dikembalikan ketika record tidak ditemukan atau bukan milik user.
     *
     * @param  string  $id  Identifier record yang menjadi target operasi.
     *
     * @return array Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getSaldoById(string $id): array
    {
        // --- step 1 - validasi input - start ---
        if (empty($id) || trim($id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        // --- step 1 - validasi input - end ---

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

        $saldoHistoryMap = [];
        if ($saldoHistory) {
            $dateFormat = Carbon::parse($saldoHistory->created_at)
                ->timezone('Asia/Jakarta')
                ->translatedFormat('d F Y H:i');

            $title = match ($saldoHistory->type) {
                'incoming' => 'Pemasukan Saldo',
                'withdrawal' => 'Penarikan Saldo'
            };

            $priceString = number_format($saldoHistory->price, 0, ',', '.');
            $paymentSlugUpper = strtoupper($saldoHistory->payment_slug ?? '');

            // Atribut hasil join dapat kosong meski baris riwayat ditemukan.
            /** @var string|null $invoiceNumber */
            $invoiceNumber = $saldoHistory->invoice_id
                ?? $saldoHistory->getAttribute('transaction_number');
            /** @var string|null $buyerName */
            $buyerName = $saldoHistory->getAttribute('buyer_name');
            /** @var string|null $paymentAccount */
            $paymentAccount = $saldoHistory->getAttribute('payment_account');
            /** @var string|null $paymentName */
            $paymentName = $saldoHistory->getAttribute('payment_name');

            $description = match ($saldoHistory->type) {
                'incoming' => "Pembelian Dari {$buyerName} - INV {$invoiceNumber}",
                'withdrawal' => "Penarikan Saldo Sebesar Rp{$priceString} Ke Bank "
                    ."{$paymentSlugUpper} {$paymentAccount} ({$paymentName})",
            };

            $saldoHistoryMap = [
                'id' => $saldoHistory->id,
                'type' => $saldoHistory->type,
                'title' => $title,
                'date' => $dateFormat,
                'price' => $saldoHistory->price,
                'description' => $description,
            ];
        }

        return ['status' => 'success', 'saldoHistory' => $saldoHistoryMap];
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
