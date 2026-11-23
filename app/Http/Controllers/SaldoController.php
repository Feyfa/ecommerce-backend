<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaldoService;
use App\Services\XenditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Melayani ringkasan saldo, riwayat mutasi, dan tarik saldo milik user terautentikasi.
 *
 * @phpstan-type WithdrawalResult array{status: 'error', httpCode: int, message: string}|array{
 *     status: 'success',
 *     saldoHistoryId: string
 * }
 */
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
     * Identitas user diperiksa sebelum input. `paymentAccount` yang tidak kosong tetapi bukan
     * teks, serta `wihtdrawPrice` berbentuk boolean, array, atau objek, ditolak dengan 422.
     * Nominal lalu dibaca sekali sebagai rupiah bulat: nilai kosong, format bukan bilangan bulat,
     * di bawah minimum, atau di atas maksimum mendapat 400. Setelah rekening ditemukan, cek saldo,
     * disbursement Xendit, dan pengurangan saldo berjalan dalam satu transaksi yang mengunci row
     * saldo, sehingga penarikan bersamaan milik user yang sama diproses satu per satu. Saldo
     * kurang atau penolakan provider mendapat 400 tanpa perubahan saldo. Pengurangan yang gagal
     * setelah provider menerima pencairan dicatat sebagai log critical dan berakhir HTTP 500.
     *
     * @param  Request  $request  Rekening tujuan dan nominal penarikan dari user terautentikasi.
     *
     * @return JsonResponse Riwayat penarikan yang baru dibuat atau detail kegagalan untuk client.
     *
     * @throws Throwable Jika query saldo gagal; perubahan saldo dan riwayat di-rollback.
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

        if ($status == 'error') {
            return response()->json(['status' => 'error', 'message' => $message], 400);
        }
        // --- step 6 - ambil data payment - end ---

        // --- step 7 - tarik saldo dengan row saldo terkunci - start ---
        // Sebelumnya cek saldo, disbursement, dan pengurangan berjalan tanpa transaksi. Dua
        // request bersamaan bisa sama-sama lolos cek saldo sebelum salah satunya mengurangi,
        // lalu Xendit mengirim keduanya dan perusahaan menanggung kelebihannya. Sekarang request
        // kedua menunggu lock row `saldo_users` sampai transaksi pertama selesai, lalu membaca
        // saldo yang sudah berkurang.
        //
        // Lock sengaja ditahan selama request HTTP ke Xendit. Client Guzzle di XenditService
        // belum memakai timeout, jadi request yang menggantung ikut menahan penarikan lain milik
        // user yang sama. Rekening dicari sebelum transaksi agar lock tidak ditahan lebih lama.
        // Generic DB::transaction melebarkan literal status menjadi string biasa.
        /** @var WithdrawalResult $withdrawal */
        $withdrawal = DB::transaction(
            fn (): array => $this->withdrawWithLockedSaldo(
                user_id: $user_id,
                amount: $wihtdrawPrice,
                paymentAccount: $paymentAccount,
                paymentUserId: $paymentUserId,
                holderName: $userName,
                paymentSlug: $paymentSlug,
            )
        );
        // --- step 7 - tarik saldo dengan row saldo terkunci - end ---

        // --- step 8 - kirim respons penarikan yang gagal - start ---
        if ($withdrawal['status'] === 'error') {
            return response()->json(
                ['status' => 'error', 'message' => $withdrawal['message']],
                $withdrawal['httpCode'],
            );
        }
        // --- step 8 - kirim respons penarikan yang gagal - end ---

        // --- step 9 - ambil satu riwayat saldo - start ---
        $getSaldoById = $this->saldoService->getSaldoById($withdrawal['saldoHistoryId']);
        $saldoHistory = $getSaldoById['saldoHistory'] ?? [];
        // --- step 9 - ambil satu riwayat saldo - end ---

        return response()->json(['status' => 'success', 'saldoHistory' => $saldoHistory]);
    }

    /**
     * Memeriksa saldo, mengirim disbursement, lalu mengurangi saldo pada row yang terkunci.
     *
     * Hanya dipanggil dari DB::transaction milik withdrawSaldo(). Row saldo dikunci sebelum cek
     * kecukupan dan tetap terkunci sampai pengurangan tersimpan, sehingga penarikan lain milik
     * user yang sama tidak bisa membaca saldo lama di antaranya. Hasil error dikembalikan tanpa
     * exception agar transaksi tetap commit tanpa perubahan dan lock segera dilepas.
     *
     * @param  string  $user_id  ID pemilik saldo yang menarik dana.
     * @param  int  $amount  Nominal penarikan dalam rupiah bulat yang sudah lolos batas.
     * @param  string  $paymentAccount  Nomor rekening tujuan, termasuk nol di awal.
     * @param  string  $paymentUserId  ID rekening tujuan yang dicatat di riwayat.
     * @param  string  $holderName  Nama pemilik rekening yang dikirim ke Xendit.
     * @param  string  $paymentSlug  Slug bank tujuan, misalnya `bca`.
     *
     * @return WithdrawalResult ID riwayat penarikan, atau status HTTP dan pesan kegagalan.
     *
     * @throws Throwable Jika query saldo gagal; kegagalan setelah disbursement sudah dicatat.
     */
    private function withdrawWithLockedSaldo(
        string $user_id,
        int $amount,
        string $paymentAccount,
        string $paymentUserId,
        string $holderName,
        string $paymentSlug,
    ): array {
        // --- step 1 - cek saldo user yang terkunci - start ---
        $saldoTotal = $this->saldoService->lockSaldoTotal($user_id);
        $shortageMessage = $this->findSaldoShortageMessage($saldoTotal, $amount);

        if ($shortageMessage !== null) {
            return ['status' => 'error', 'httpCode' => 400, 'message' => $shortageMessage];
        }
        // --- step 1 - cek saldo user yang terkunci - end ---

        // --- step 2 - siapkan data disbursement - start ---
        // External ID dipakai ulang di log critical jika pengurangan gagal setelah transfer.
        $paymentSlugUpper = strtoupper($paymentSlug);
        $amountString = $this->formatRupiahAmount($amount);
        $externalId = "dis-$paymentSlug-".uniqid();
        // --- step 2 - siapkan data disbursement - end ---

        // --- step 3 - kirim disbursement ke Xendit - start ---
        $disbursement = $this->xenditService->disbursement(
            external_id: $externalId,
            amount: $amount,
            bank_code: $paymentSlugUpper,
            account_holder_name: $holderName,
            account_number: $paymentAccount,
            description: "Transfer Rekening $paymentSlugUpper Sebesar Rp$amountString"
        );

        // Penolakan provider harus keluar sebelum saldo atau riwayat diubah.
        if ($disbursement['status'] === 'error') {
            return [
                'status' => 'error',
                'httpCode' => 400,
                'message' => $disbursement['message'],
            ];
        }
        // --- step 3 - kirim disbursement ke Xendit - end ---

        // --- step 4 - kurangi saldo setelah disbursement - start ---
        $saved = $this->saveDisbursedWithdrawal($user_id, $paymentUserId, $amount, $externalId);
        // --- step 4 - kurangi saldo setelah disbursement - end ---

        return $saved;
    }

    /**
     * Menyusun pesan penolakan ketika saldo yang terkunci tidak cukup untuk penarikan.
     *
     * @param  int  $saldoTotal  Total saldo yang dibaca dengan lock.
     * @param  int  $amount  Nominal penarikan dalam rupiah bulat.
     *
     * @return string|null Pesan saldo nol atau saldo kurang, atau null jika saldo cukup.
     */
    private function findSaldoShortageMessage(int $saldoTotal, int $amount): ?string
    {
        // Pesan dipertahankan sama dengan sebelum lock ditambahkan.
        if ($saldoTotal === 0) {
            return 'Saldo Anda Rp0 Anda Tidak Bisa Tarik Saldo';
        }

        if ($saldoTotal < $amount) {
            $saldoTotalString = $this->formatRupiahAmount($saldoTotal);
            $amountString = $this->formatRupiahAmount($amount);

            return "Saldo Anda Hanya Rp$saldoTotalString, "
                ."Tidak Bisa Tarik Saldo Sebesar $amountString";
        }

        return null;
    }

    /**
     * Menyimpan pengurangan saldo untuk disbursement yang sudah diterima Xendit.
     *
     * Saat method ini berjalan, provider sudah menerima pencairan. Kegagalan di sini berarti dana
     * keluar tanpa saldo berkurang, dan perusahaan menanggung selisihnya sampai direkonsiliasi.
     * Karena itu kegagalan dicatat sebagai log critical bersama external ID Xendit. Log hanya
     * memuat ID user, nominal, dan external ID, tanpa nomor rekening atau nama pemilik. Hasil
     * error dari service menjadi HTTP 500, sedangkan exception dilempar ulang agar transaksi luar
     * di-rollback. Rekonsiliasi otomatis berada di luar cakupan method ini.
     *
     * @param  string  $user_id  ID pemilik saldo yang menarik dana.
     * @param  string  $paymentUserId  ID rekening tujuan yang dicatat di riwayat.
     * @param  int  $amount  Nominal yang sudah diterima provider.
     * @param  string  $externalId  External ID disbursement untuk mencari transaksi di Xendit.
     *
     * @return WithdrawalResult ID riwayat penarikan, atau HTTP 500 jika service menolak.
     *
     * @throws Throwable Jika query saldo atau riwayat gagal; exception sudah dicatat.
     */
    private function saveDisbursedWithdrawal(
        string $user_id,
        string $paymentUserId,
        int $amount,
        string $externalId,
    ): array {
        // --- step 1 - simpan pengurangan saldo - start ---
        $logContext = ['user_id' => $user_id, 'amount' => $amount, 'external_id' => $externalId];

        try {
            $saved = $this->saldoService->saveSaldoAfterDisbursement(
                $user_id,
                $paymentUserId,
                $amount,
            );
        } catch (Throwable $exception) {
            Log::critical('Withdrawal was disbursed but the balance deduction failed.', [
                ...$logContext,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
        // --- step 1 - simpan pengurangan saldo - end ---

        // --- step 2 - laporkan pengurangan yang ditolak - start ---
        // Cek saldo di bawah lock yang sama membuat penolakan ini tidak diharapkan terjadi.
        if ($saved['status'] === 'error') {
            Log::critical('Withdrawal was disbursed but the balance deduction was rejected.', [
                ...$logContext,
                'reason' => $saved['message'],
            ]);

            // Dana mungkin sudah keluar. User diminta tidak mengulang agar tidak dikirim dua kali.
            return [
                'status' => 'error',
                'httpCode' => 500,
                'message' => 'Penarikan Sudah Diproses Tetapi Saldo Gagal Diperbarui, '
                    .'Jangan Ulangi Penarikan',
            ];
        }
        // --- step 2 - laporkan pengurangan yang ditolak - end ---

        return ['status' => 'success', 'saldoHistoryId' => $saved['saldoHistoryId']];
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
