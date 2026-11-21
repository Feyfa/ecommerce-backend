<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaldoService;
use App\Services\XenditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     * Hanya user lokal yang masih terdaftar dapat membaca saldo income dan refund miliknya.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Ringkasan saldo atau respons error.
     */
    public function getSaldo(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        // Resolver middleware membawa model user. Cek database tetap diperlukan
        // agar identitas yang sudah terhapus tidak dapat membaca saldo.
        $user = $request->user();

        if (! $user instanceof User || ! User::where('id', $user->id)->exists()) {
            return response()->json(
                ['status' => 'error', 'message' => 'Unauthorized'],
                JsonResponse::HTTP_UNAUTHORIZED,
            );
        }

        $user_id = $user->id;
        // --- step 1 - validasi user - end ---

        // --- step 2 - ambil saldo - start ---
        $getSaldo = $this->saldoService->getSaldo($user_id);

        $status = $getSaldo['status'];
        $saldoIncome = $getSaldo['saldoIncome'] ?? 0;
        $saldoRefund = $getSaldo['saldoRefund'] ?? 0;
        $saldoTotal = $getSaldo['saldoTotal'] ?? 0;

        if ($status == 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $getSaldo['message'] ?? 'Sepertinya Ada Yang Salah',
            ]);
        }
        // --- step 2 - ambil saldo - end ---

        return response()->json([
            'status' => 'success',
            'saldoIncome' => $saldoIncome,
            'saldoRefund' => $saldoRefund,
            'saldoTotal' => $saldoTotal,
        ]);
    }

    /**
     * Menampilkan riwayat mutasi saldo pengguna.
     *
     * Identitas diverifikasi sebelum filter diperiksa dan query riwayat dijalankan.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Riwayat saldo milik user atau respons error autentikasi atau filter.
     */
    public function getSaldoHistory(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        // Verifikasi identitas sebelum memproses filter untuk mempertahankan
        // urutan 401 bagi user yang tidak terautentikasi atau sudah terhapus.
        $user = $request->user();

        if (! $user instanceof User || ! User::where('id', $user->id)->exists()) {
            return response()->json(
                ['status' => 'error', 'message' => 'Unauthorized'],
                JsonResponse::HTTP_UNAUTHORIZED,
            );
        }

        $user_id = $user->id;
        // --- step 1 - validasi user - end ---

        // --- step 2 - validasi tipe filter tanggal - start ---
        $startDate = $request->input('startDate') ?? '';
        $endDate = $request->input('endDate') ?? '';

        if (! is_string($startDate) || ! is_string($endDate)) {
            return response()->json([
                'status' => 'error',
                'message' => 'startDate dan endDate harus berupa teks.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        // --- step 2 - validasi tipe filter tanggal - end ---

        // --- step 3 - validasi daftar ID riwayat - start ---
        $saldoHistoryCurrentIds = $this->parseSaldoHistoryIds(
            $request->input('saldo_history_current_ids'),
        );

        if ($saldoHistoryCurrentIds === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'saldo_history_current_ids harus berupa daftar UUID dalam JSON.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        // --- step 3 - validasi daftar ID riwayat - end ---

        // --- step 4 - ambil riwayat saldo - start ---
        $getSaldoHistory = $this->saldoService->getSaldoHistory(
            $user_id,
            $startDate,
            $endDate,
            $saldoHistoryCurrentIds,
        );

        $status = $getSaldoHistory['status'];
        $saldoHistory = $getSaldoHistory['saldoHistory'] ?? [];

        if ($status == 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $getSaldoHistory['message'] ?? 'Sepertinya Ada Yang Salah',
            ]);
        }
        // --- step 4 - ambil riwayat saldo - end ---

        return response()->json(['status' => 'success', 'saldoHistory' => $saldoHistory]);
    }

    /**
     * Membaca ID riwayat yang sudah ditampilkan oleh client.
     *
     * @param  mixed  $input  Filter opsional dari request sebelum validasi.
     *
     * @return list<string>|null Daftar UUID atau null jika bentuk atau nilainya tidak valid.
     */
    private function parseSaldoHistoryIds(mixed $input): ?array
    {
        // Filter opsional. Client yang belum memuat riwayat bisa mengosongkannya.
        if ($input === null || $input === '') {
            return [];
        }

        // --- step 1 - dekode JSON riwayat - start ---
        // Dekode tanpa mode asosiatif agar objek JSON tidak dianggap daftar ID.
        if (! is_string($input)) {
            return null;
        }

        $decodedIds = json_decode($input);

        if (! is_array($decodedIds)) {
            return null;
        }
        // --- step 1 - dekode JSON riwayat - end ---

        // --- step 2 - validasi UUID riwayat - start ---
        // PostgreSQL membandingkan ID dengan kolom UUID. Tolak string yang
        // tidak valid di sini agar filter dari client tidak menggagalkan query.
        $ids = [];

        foreach ($decodedIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                return null;
            }

            $ids[] = $id;
        }
        // --- step 2 - validasi UUID riwayat - end ---

        return $ids;
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

        // Penolakan provider harus keluar sebelum saldo atau riwayat diubah.
        if ($disbursement['status'] === 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $disbursement['message'],
            ], 400);
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
