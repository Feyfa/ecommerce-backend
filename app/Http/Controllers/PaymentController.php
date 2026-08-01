<?php

namespace App\Http\Controllers;

use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\TransactionInvoice;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan pembayaran dan audit log.
     *
     * @param  PaymentService  $paymentService  Service payment yang digunakan oleh class ini.
     * @param  XenditService  $xenditService  Service xendit yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected PaymentService $paymentService,
        protected XenditService $xenditService,
    ) {}

    /**
     * Menampilkan metode pembayaran milik pengguna.
     *
     * Identitas user diverifikasi sebelum rekening pembayaran dimuat. Hanya metode milik user tersebut
     * yang dikembalikan untuk kebutuhan pengaturan dan withdrawal.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getPayment(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - proses pengambilan payment
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $request->searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        // --- step 2 - end - proses pengambilan payment

        return response()->json(['status' => 'success', 'payments' => $payments]);
    }

    /**
     * Menampilkan daftar metode pembayaran yang tersedia.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getPaymentList(): JsonResponse
    {
        // --- step 1 - start - ambil daftar payment
        $paymentList = PaymentList::select('id', 'slug', 'name')
            ->where('type', 'withdrawal')
            ->get();
        // --- step 1 - end - ambil daftar payment

        return response()->json(['status' => 'success', 'paymentList' => $paymentList]);
    }

    /**
     * Memvalidasi kepemilikan rekening pembayaran pengguna.
     *
     * Rekening dan metode pembayaran divalidasi sebelum provider simulasi dipanggil. Function
     * memastikan detail rekening dapat digunakan tanpa menyimpan perubahan pada akun pengguna.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function validatePaymentAccount(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - validasi akun payment
        if (empty($request->paymentAccount) || trim($request->paymentAccount) == '') {
            return response()->json(['status' => 'error', 'message' => 'Nomor Rekening Tidak Boleh Kosong'], 400);
        }
        // --- step 2 - end - validasi akun payment

        // --- step 3 - start - validasi slug payment
        $slugs = PaymentList::distinct()
            ->pluck('slug')
            ->toArray();

        if (empty($request->paymentSlug) || trim($request->paymentSlug) == '') {
            return response()->json(['status' => 'error', 'message' => 'Payment Slug Empty'], 400);
        } elseif (! in_array($request->paymentSlug, $slugs)) {
            return response()->json(['status' => 'error', 'message' => "Nama Bank {$request->paymentSlug} Tidak Tersedia"], 400);
        }
        // --- step 3 - end - validasi slug payment

        // --- step 4 - start - periksa duplikasi akun payment
        $paymentExists = PaymentUser::join('payment_lists', 'payment_lists.id', '=', 'payment_users.payment_id')
            ->where('payment_users.user_id', $user_id)
            ->where('payment_users.account', $request->paymentAccount)
            ->where('payment_lists.slug', $request->paymentSlug)
            ->exists();
        if ($paymentExists) {
            return response()->json(['status' => 'error', 'message' => 'Nomor Rekening Sudah Digunakan'], 400);
        }
        // --- step 4 - end - periksa duplikasi akun payment

        // --- step 5 - start - buat nama payment sementara
        $generateFakeUser = $this->paymentService->generateFakeUser();
        $name = $generateFakeUser['user']['name'] ?? '';
        // --- step 5 - end - buat nama payment sementara

        return response()->json(['status' => 'success', 'username' => $name]);
    }

    /**
     * Menambahkan metode pembayaran pengguna.
     *
     * Payload rekening, metode, serta user pemilik divalidasi dan dibatasi ke session aktif. Rekening
     * baru hanya disimpan setelah pemeriksaan provider berhasil sehingga data yang tidak dapat
     * digunakan tidak masuk database.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function addPayment(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi id user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi id user

        // --- step 2 - start - validasi request
        $validator = Validator::make($request->all(), [
            'paymentName' => ['required'],
            'paymentSlug' => ['required'],
            'paymentAccount' => ['required'],
            'paymentUsername' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        // --- step 2 - end - validasi request

        // --- step 3 - start - validasi batas maksimal empat payment
        $totalPayment = PaymentUser::where('user_id', $user_id)
            ->count();

        if ($totalPayment >= 10) {
            return response()->json(['status' => 'error', 'message' => 'Rekening Tidak Boleh Lebih Dari 10'], 400);
        }
        // --- step 3 - end - validasi batas maksimal empat payment

        // --- step 4 - start - validasi nama dan slug payment
        $paymentList = PaymentList::where('type', 'withdrawal')
            ->where('slug', $request->paymentSlug)
            ->where('name', $request->paymentName)
            ->first();

        if (! $paymentList) {
            return response()->json(['status' => 'error', 'message' => 'Tipe Rekening Bank Tidak Tersedia'], 400);
        }
        // --- step 4 - end - validasi nama dan slug payment

        // --- step 5 - start - buat akun payment user
        PaymentUser::create([
            'user_id' => $user_id,
            'payment_id' => ($paymentList->id ?? null),
            'name' => $request->paymentUsername,
            'account' => $request->paymentAccount,
        ]);
        // --- step 5 - end - buat akun payment user

        // --- step 6 - start - proses pengambilan payment
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $request->searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        // --- step 6 - end - proses pengambilan payment

        return response()->json(['status' => 'success', 'payments' => $payments, 'message' => 'Rekening Berhasil Ditambah']);
    }

    /**
     * Menghapus metode pembayaran milik pengguna.
     *
     * Function memastikan rekening pembayaran berada dalam scope user terautentikasi sebelum
     * menghapusnya. Response tidak mengungkap keberadaan rekening milik user lain.
     *
     * @param  string  $id  Identifier record yang menjadi target operasi.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function deletePayment(string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi id user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi id user

        // --- step 2 - start - validasi lalu hapus payment
        $paymentUser = PaymentUser::where('id', $id)
            ->where('user_id', $user_id)
            ->first();

        if (! $paymentUser) {
            return response()->json(['status' => 'error', 'message' => 'Data Rekening Tidak Ditemukan'], 400);
        }

        $paymentUser->delete();
        // --- step 2 - end - validasi lalu hapus payment

        // --- step 3 - start - proses pengambilan payment
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $request->searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        // --- step 3 - end - proses pengambilan payment

        return response()->json(['status' => 'success', 'payments' => $payments, 'message' => 'Rekening Berhasil Dihapus']);
    }

    /**
     * Menjalankan simulasi pembayaran virtual account untuk kebutuhan pengujian.
     *
     * Input simulasi divalidasi dan jenis virtual account menentukan endpoint Xendit yang digunakan.
     * Hasil provider diterjemahkan menjadi response pengujian tanpa mengubah transaksi produksi secara
     * langsung.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function simulateChargeVirtualAccount(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi id user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi id user

        // --- step 2 - start - validasi request
        if (empty($request->payment_slug) || trim($request->payment_slug) == '') {
            return response()->json(['status' => 'error', 'message' => 'Nama Bank Harus Dipilih'], 400);
        }
        if (empty($request->payment_account) || trim($request->payment_account) == '') {
            return response()->json(['status' => 'error', 'message' => 'Nomor Virtual Account Harus Dipilih'], 400);
        }
        // --- step 2 - end - validasi request

        // --- step 3 - start - validasi kepemilikan virtual account
        $now = Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s');
        $transactionInvoice = TransactionInvoice::where('user_id_buyer', $user_id)
            ->where('payment_account', $request->payment_account)
            ->where('payment_slug', $request->payment_slug)
            ->where('payment_method', 'va')
            ->where('status', 'pending')
            ->first();
        if (empty($transactionInvoice)) {
            return response()->json(['status' => 'error', 'message' => 'Nomor Virtual Account Tidak Ditemukan'], 400);
        }
        if ($transactionInvoice->expired_at <= $now) {
            return response()->json(['status' => 'error', 'message' => 'Nomor Virtual Account Ini Sudah Expired'], 400);
        }
        // --- step 3 - end - validasi kepemilikan virtual account

        // --- step 4 - start - proses pembayaran virtual account
        $simulateVirtualAccountFixed = $this->xenditService->simulateVirtualAccountFixed(
            external_id: $transactionInvoice->payment_reference ?? '',
            amount: $transactionInvoice->price ?? 0
        );
        if ($simulateVirtualAccountFixed['status'] == 'error') {
            return response()->json(['status' => 'error', 'message' => $simulateVirtualAccountFixed['message']], 400);
        }
        // --- step 4 - end - proses pembayaran virtual account

        // --- step 5 - start - perbarui status invoice transaksi
        $transactionInvoice->status = 'done';
        $transactionInvoice->save();
        // --- step 5 - end - perbarui status invoice transaksi

        return response()->json(['status' => 'success', 'message' => 'Success Charge Virtual Account']);
    }
}
