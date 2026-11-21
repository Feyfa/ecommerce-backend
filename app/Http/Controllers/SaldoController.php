<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaldoService;
use App\Services\XenditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaldoController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan saldo dan pembayaran.
     *
     * @param  SaldoService  $saldoService  Service saldo yang digunakan oleh class ini.
     * @param  XenditService  $xenditService  Service xendit yang digunakan oleh class ini.
     * @param  PaymentService  $paymentService  Service payment yang digunakan oleh class ini.
     *
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected SaldoService $saldoService,
        protected XenditService $xenditService,
        protected PaymentService $paymentService,
    ) {}

    /**
     * Menampilkan saldo pengguna yang terautentikasi.
     *
     * Identitas user diverifikasi sebelum service membaca saldo income dan refund. Kegagalan pembacaan
     * diterjemahkan menjadi response error tanpa mengekspos detail internal service.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getSaldo(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - validasi user - end ---

        // --- step 2 - ambil saldo - start ---
        $getSaldo = $this->saldoService->getSaldo($user_id);
        $status = $getSaldo['status'] ?? '';
        $saldoIncome = $getSaldo['saldoIncome'] ?? 0;
        $saldoRefund = $getSaldo['saldoRefund'] ?? 0;
        $saldoTotal = $getSaldo['saldoTotal'] ?? 0;
        if ($status == 'error') {
            return response()->json(['status' => 'error', 'message' => ($getSaldo['message'] ?? 'Sepertinya Ada Yang Salah')]);
        }
        // --- step 2 - ambil saldo - end ---

        return response()->json(['status' => 'success', 'saldoIncome' => $saldoIncome, 'saldoRefund' => $saldoRefund, 'saldoTotal' => $saldoTotal]);
    }

    /**
     * Menampilkan riwayat mutasi saldo pengguna.
     *
     * Filter riwayat diteruskan hanya setelah user terautentikasi teridentifikasi. Service menyusun
     * mutasi saldo milik user dan controller menormalisasi hasilnya menjadi kontrak JSON.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getSaldoHistory(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - validasi user - end ---

        // --- step 2 - siapkan variabel proses - start ---
        $startDate = $request->startDate ?? '';
        $endDate = $request->endDate ?? '';
        $saldo_history_current_ids = ! empty($request->saldo_history_current_ids) ? json_decode($request->saldo_history_current_ids, true) : [];
        // --- step 2 - siapkan variabel proses - end ---

        // --- step 3 - ambil riwayat saldo - start ---
        $getSaldoHistory = $this->saldoService->getSaldoHistory($user_id, $startDate, $endDate, $saldo_history_current_ids);
        $status = $getSaldoHistory['status'] ?? '';
        $saldoHistory = $getSaldoHistory['saldoHistory'] ?? [];
        if ($status == 'error') {
            return response()->json(['status' => 'error', 'message' => ($getSaldoHistory['message'] ?? 'Sepertinya Ada Yang Salah')]);
        }
        // --- step 3 - ambil riwayat saldo - end ---

        return response()->json(['status' => 'success', 'saldoHistory' => $saldoHistory]);
    }

    /**
     * Memvalidasi dan memproses penarikan saldo pengguna.
     *
     * Function memvalidasi identitas user, rekening tujuan, nominal minimum serta maksimum, dan
     * kecukupan saldo sebelum meminta disbursement ke Xendit. Saldo dan riwayatnya baru diperbarui
     * setelah provider mengonfirmasi pencairan, sehingga kegagalan eksternal tidak mengurangi saldo
     * pengguna.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function withdrawSaldo(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - validasi user - end ---

        // --- step 2 - validasi request - start ---
        $wihtdrawMaximum = 1000000;
        $wihtdrawMaximumString = number_format($wihtdrawMaximum, 0, ',', '.');

        $paymentAccount = $request->paymentAccount ?? '';
        $wihtdrawPrice = $request->wihtdrawPrice ?? '';
        if (empty($paymentAccount) || trim($paymentAccount) == '') {
            return response()->json(['status' => 'error', 'message' => 'Rekening Bank Tidak Boleh Kosong'], 400);
        }
        if (empty($wihtdrawPrice) || trim($wihtdrawPrice) == '') {
            return response()->json(['status' => 'error', 'message' => 'Nominal Tidak Boleh Kosong'], 400);
        }
        if (! is_numeric($wihtdrawPrice)) {
            return response()->json(['status' => 'error', 'message' => 'Format Nominal Tidak Valid'], 400);
        }
        if ($wihtdrawPrice > $wihtdrawMaximum) {
            return response()->json(['status' => 'error', 'message' => "Nominal Tidak Boleh Lebih Dari Rp$wihtdrawMaximumString"], 400);
        }

        $wihtdrawPriceString = number_format($wihtdrawPrice, 0, ',', '.');
        // --- step 2 - validasi request - end ---

        // --- step 3 - ambil data payment - start ---
        $getPayment = $this->paymentService->getWithdrawalPayment($user_id, $paymentAccount);
        $status = $getPayment['status'];
        $message = $getPayment['message'] ?? '';
        $paymentUserId = isset($getPayment['payment']['id']) ? $getPayment['payment']['id'] : '';
        $userName = isset($getPayment['payment']['user_name']) ? $getPayment['payment']['user_name'] : '';
        $paymentSlug = isset($getPayment['payment']['payment_slug']) ? $getPayment['payment']['payment_slug'] : '';
        $paymentSlugUpper = strtoupper($paymentSlug);
        if ($status == 'error') {
            return response()->json(['status' => 'error', 'message' => $message], 400);
        }
        // --- step 3 - ambil data payment - end ---

        // --- step 4 - ambil saldo user - start ---
        $getSaldo = $this->saldoService->getSaldo($user_id);
        $saldoTotal = $getSaldo['saldoTotal'] ?? 0;
        $saldoTotalString = number_format($saldoTotal, 0, ',', '.');
        if ($saldoTotal == 0 || empty($saldoTotal)) {
            return response()->json(['status' => 'error', 'message' => 'Saldo Anda Rp0 Anda Tidak Bisa Tarik Saldo'], 400);
        }
        if ($saldoTotal < $wihtdrawPrice) {
            return response()->json(['status' => 'error', 'message' => "Saldo Anda Hanya Rp$saldoTotalString, Tidak Bisa Tarik Saldo Sebesar $wihtdrawPriceString"], 400);
        }
        // --- step 4 - ambil saldo user - end ---

        // --- step 5 - proses penarikan saldo - start ---
        $disbursement = $this->xenditService->disbursement(
            external_id: "dis-$paymentSlug-".uniqid(),
            amount: $wihtdrawPrice,
            bank_code: $paymentSlugUpper,
            account_holder_name: $userName,
            account_number: $paymentAccount,
            description: "Transfer Rekening $paymentSlugUpper Sebesar Rp$wihtdrawPriceString"
        );
        $status = $disbursement['status'] ?? '';
        $message = $disbursement['message'] ?? '';
        if ($status == 'error') {
            return response()->json(['status' => 'error', 'message' => $message], 400);
        }
        // --- step 5 - proses penarikan saldo - end ---

        // --- step 6 - simpan saldo setelah disbursement - start ---
        $saveSaldoAfterDisbursement = $this->saldoService->saveSaldoAfterDisbursement($user_id, $paymentUserId, $wihtdrawPrice);
        $saldoHistoryId = $saveSaldoAfterDisbursement['saldoHistoryId'] ?? null;
        // --- step 6 - simpan saldo setelah disbursement - end ---

        // --- step 7 - ambil satu riwayat saldo - start ---
        $getSaldoById = $this->saldoService->getSaldoById($saldoHistoryId);
        $saldoHistory = $getSaldoById['saldoHistory'] ?? [];
        // --- step 7 - ambil satu riwayat saldo - end ---

        return response()->json(['status' => 'success', 'saldoHistory' => $saldoHistory]);
    }
}
