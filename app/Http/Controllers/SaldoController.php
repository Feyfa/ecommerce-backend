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
    // Nominal tarik saldo terkecil dalam rupiah, sama dengan frontend yang menolak `<= 0`.
    private const WITHDRAW_MINIMUM_AMOUNT = 1;

    // Nominal tarik saldo terbesar dalam rupiah untuk satu request.
    private const WITHDRAW_MAXIMUM_AMOUNT = 1000000;

    // Rupiah ditampilkan tanpa desimal, sama dengan format riwayat di SaldoService.
    private const RUPIAH_DECIMAL_PLACES = 0;

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
     * Identitas user diperiksa sebelum input. `paymentAccount` yang tidak kosong tetapi bukan teks,
     * serta `wihtdrawPrice` berbentuk boolean, array, atau objek, ditolak dengan 422. Nominal lalu
     * dibaca sekali sebagai rupiah bulat: nilai kosong, format bukan bilangan bulat, di bawah
     * minimum, atau di atas maksimum mendapat 400. Rekening dan kecukupan saldo diperiksa sebelum
     * disbursement Xendit. Saldo dan riwayatnya baru diperbarui setelah provider mengonfirmasi
     * pencairan, sehingga kegagalan eksternal tidak mengurangi saldo pengguna.
     *
     * @param  Request  $request  Rekening tujuan dan nominal penarikan dari user terautentikasi.
     *
     * @return JsonResponse Riwayat penarikan yang baru dibuat atau detail kegagalan untuk client.
     */
    public function withdrawSaldo(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        $user = $request->user();

        if (! $user instanceof User || ! User::where('id', $user->id)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $user_id = $user->id;
        // --- step 1 - validasi user - end ---

        // --- step 2 - validasi rekening tujuan - start ---
        // `?:` mengubah semua nilai kosong (null, false, 0, [], "0") menjadi '' sehingga tetap
        // mendapat 400 seperti sebelumnya. Nilai lain yang bukan teks ditolak dengan 422;
        // sebelumnya array membuat trim() melempar TypeError dan berakhir HTTP 500.
        $paymentAccount = $request->paymentAccount ?: '';

        if (! is_string($paymentAccount)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Rekening Bank Harus Berupa Teks'],
                422,
            );
        }

        if (empty($paymentAccount) || trim($paymentAccount) == '') {
            return response()->json(
                ['status' => 'error', 'message' => 'Rekening Bank Tidak Boleh Kosong'],
                400,
            );
        }
        // --- step 2 - validasi rekening tujuan - end ---

        // --- step 3 - validasi tipe nominal - start ---
        // Frontend mengirim angka JSON (nilai awal 1) atau teks digit setelah user mengetik,
        // jadi keduanya diterima. Nilai kosong diubah `?:` menjadi '' seperti rekening di atas.
        // Boolean, array, dan objek ditolak dengan 422; sebelumnya `true` mendapat 400 format
        // dan array membuat trim() melempar TypeError.
        $wihtdrawPriceInput = $request->wihtdrawPrice ?: '';

        if (! is_string($wihtdrawPriceInput) && ! is_numeric($wihtdrawPriceInput)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Nominal Harus Berupa Angka Atau Teks'],
                422,
            );
        }

        if (is_string($wihtdrawPriceInput) && trim($wihtdrawPriceInput) == '') {
            return response()->json(
                ['status' => 'error', 'message' => 'Nominal Tidak Boleh Kosong'],
                400,
            );
        }
        // --- step 3 - validasi tipe nominal - end ---

        // --- step 4 - baca nominal sebagai rupiah bulat - start ---
        // Xendit dan SaldoService hanya menerima `int`. Sebelumnya "150000.5" dipotong PHP menjadi
        // 150000 untuk transfer dan saldo, sedangkan pesan membulatkannya menjadi Rp150.001.
        $wihtdrawPrice = $this->parseWithdrawAmount($wihtdrawPriceInput);

        if ($wihtdrawPrice === null) {
            return response()->json(
                ['status' => 'error', 'message' => 'Format Nominal Tidak Valid'],
                400,
            );
        }
        // --- step 4 - baca nominal sebagai rupiah bulat - end ---

        // --- step 5 - validasi batas nominal - start ---
        // Nominal negatif sebelumnya lolos sampai Xendit. Jika provider menerimanya,
        // saveSaldoAfterDisbursement() justru menambah saldo karena mengurangi angka negatif.
        // Kelebihan saldo itu bisa ditarik lewat endpoint ini, sehingga perusahaan yang
        // menanggung. Batas ini harus dicek sebelum lookup rekening dan disbursement.
        if ($wihtdrawPrice < self::WITHDRAW_MINIMUM_AMOUNT) {
            $minimumString = $this->formatRupiahAmount(self::WITHDRAW_MINIMUM_AMOUNT);

            return response()->json(
                ['status' => 'error', 'message' => "Nominal Minimal Rp$minimumString"],
                400,
            );
        }

        if ($wihtdrawPrice > self::WITHDRAW_MAXIMUM_AMOUNT) {
            $maximumString = $this->formatRupiahAmount(self::WITHDRAW_MAXIMUM_AMOUNT);
            $maximumMessage = "Nominal Tidak Boleh Lebih Dari Rp$maximumString";

            return response()->json(['status' => 'error', 'message' => $maximumMessage], 400);
        }
        // --- step 5 - validasi batas nominal - end ---

        // --- step 6 - ambil data payment - start ---
        $getPayment = $this->paymentService->getWithdrawalPayment($user_id, $paymentAccount);
        $status = $getPayment['status'];
        $message = $getPayment['message'] ?? '';
        $paymentUserId = $getPayment['payment']['id'] ?? '';
        $userName = $getPayment['payment']['user_name'] ?? '';
        $paymentSlug = $getPayment['payment']['payment_slug'] ?? '';
        $paymentSlugUpper = strtoupper($paymentSlug);

        if ($status == 'error') {
            return response()->json(['status' => 'error', 'message' => $message], 400);
        }
        // --- step 6 - ambil data payment - end ---

        // --- step 7 - ambil saldo user - start ---
        $getSaldo = $this->saldoService->getSaldo($user_id);
        $saldoTotal = $getSaldo['saldoTotal'] ?? 0;
        $saldoTotalString = $this->formatRupiahAmount($saldoTotal);
        $wihtdrawPriceString = $this->formatRupiahAmount($wihtdrawPrice);

        if ($saldoTotal == 0 || empty($saldoTotal)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Saldo Anda Rp0 Anda Tidak Bisa Tarik Saldo'],
                400,
            );
        }

        if ($saldoTotal < $wihtdrawPrice) {
            $insufficientMessage = "Saldo Anda Hanya Rp$saldoTotalString, "
                ."Tidak Bisa Tarik Saldo Sebesar $wihtdrawPriceString";

            return response()->json(['status' => 'error', 'message' => $insufficientMessage], 400);
        }
        // --- step 7 - ambil saldo user - end ---

        // --- step 8 - proses penarikan saldo - start ---
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
        // --- step 8 - proses penarikan saldo - end ---

        // --- step 9 - simpan saldo setelah disbursement - start ---
        $saveSaldoAfterDisbursement = $this->saldoService->saveSaldoAfterDisbursement(
            $user_id,
            $paymentUserId,
            $wihtdrawPrice,
        );
        $saldoHistoryId = $saveSaldoAfterDisbursement['saldoHistoryId'] ?? null;
        // --- step 9 - simpan saldo setelah disbursement - end ---

        // --- step 10 - ambil satu riwayat saldo - start ---
        $getSaldoById = $this->saldoService->getSaldoById($saldoHistoryId);
        $saldoHistory = $getSaldoById['saldoHistory'] ?? [];
        // --- step 10 - ambil satu riwayat saldo - end ---

        return response()->json(['status' => 'success', 'saldoHistory' => $saldoHistory]);
    }

    /**
     * Membaca nominal tarik saldo yang sudah lolos cek tipe sebagai rupiah bulat.
     *
     * Angka JSON bulat dipakai apa adanya. Teks hanya diterima jika berisi digit dengan tanda minus
     * opsional, sehingga nominal negatif tetap sampai ke cek minimum dengan pesan yang tepat.
     * Desimal, eksponen, tanda plus, dan angka JSON pecahan dianggap format tidak valid.
     *
     * @param  int|float|string  $input  Nominal mentah yang tidak kosong dari request.
     *
     * @return int|null Nominal dalam rupiah, atau null jika bukan bilangan bulat.
     */
    private function parseWithdrawAmount(int|float|string $input): ?int
    {
        // Teks digit yang melebihi PHP_INT_MAX dibatasi oleh cast (int) ke PHP_INT_MAX atau
        // PHP_INT_MIN, sehingga tetap ditolak oleh cek batas nominal di pemanggil.
        if (is_int($input)) {
            return $input;
        }

        if (is_string($input) && preg_match('/\A-?\d+\z/', $input) === 1) {
            return (int) $input;
        }

        return null;
    }

    /**
     * Memformat nominal untuk pesan dan deskripsi transfer tanpa awalan `Rp`.
     *
     * @param  int|float  $amount  Nominal rupiah yang ditampilkan ke user atau dikirim ke Xendit.
     *
     * @return string Nominal dengan pemisah ribuan titik, misalnya `1.000.000`.
     */
    private function formatRupiahAmount(int|float $amount): string
    {
        return number_format($amount, self::RUPIAH_DECIMAL_PLACES, ',', '.');
    }
}
