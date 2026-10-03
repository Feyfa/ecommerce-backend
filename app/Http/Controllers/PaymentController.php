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
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
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
     * Pencarian non-string ditolak dengan 422 sebelum query rekening; missing/null berarti tanpa filter.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getPayment(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        $user_id = $user->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - validasi pencarian sebelum membaca rekening
        $validator = Validator::make($request->all(), [
            'searchPayment' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        /** @var array{searchPayment?: string|null} $validated */
        $validated = $validator->validated();
        $searchPayment = $validated['searchPayment'] ?? '';
        // --- step 2 - end - validasi pencarian sebelum membaca rekening

        // --- step 3 - start - proses pengambilan payment
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        // --- step 3 - end - proses pengambilan payment

        return response()->json(['status' => 'success', 'payments' => $payments]);
    }

    /**
     * Menampilkan daftar metode pembayaran yang tersedia.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
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
     * Identitas user diperiksa sebelum tipe rekening dan slug divalidasi. Input non-string ditolak
     * dengan 422; nilai kosong, slug tidak tersedia, serta rekening duplikat mempertahankan error 400.
     * Nama pemilik sintetis dikembalikan tanpa panggilan provider atau perubahan rekening pengguna.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function validatePaymentAccount(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        $user_id = $user->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - validasi akun payment
        $validator = Validator::make($request->all(), [
            'paymentAccount' => ['nullable', 'string'],
            'paymentSlug' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        /** @var array{paymentAccount?: string|null, paymentSlug?: string|null} $validated */
        $validated = $validator->validated();
        $paymentAccount = $validated['paymentAccount'] ?? '';
        $paymentSlug = $validated['paymentSlug'] ?? '';
        if (empty($paymentAccount) || trim($paymentAccount) == '') {
            return response()->json(['status' => 'error', 'message' => 'Nomor Rekening Tidak Boleh Kosong'], 400);
        }
        // --- step 2 - end - validasi akun payment

        // --- step 3 - start - validasi slug payment
        $slugs = PaymentList::distinct()
            ->pluck('slug')
            ->toArray();

        if (empty($paymentSlug) || trim($paymentSlug) == '') {
            return response()->json(['status' => 'error', 'message' => 'Payment Slug Empty'], 400);
        } elseif (! in_array($paymentSlug, $slugs)) {
            return response()->json(['status' => 'error', 'message' => "Nama Bank {$paymentSlug} Tidak Tersedia"], 400);
        }
        // --- step 3 - end - validasi slug payment

        // --- step 4 - start - periksa duplikasi akun payment
        $paymentExists = PaymentUser::join('payment_lists', 'payment_lists.id', '=', 'payment_users.payment_id')
            ->where('payment_users.user_id', $user_id)
            ->where('payment_users.account', $paymentAccount)
            ->where('payment_lists.slug', $paymentSlug)
            ->exists();
        if ($paymentExists) {
            return response()->json(['status' => 'error', 'message' => 'Nomor Rekening Sudah Digunakan'], 400);
        }
        // --- step 4 - end - periksa duplikasi akun payment

        // --- step 5 - start - buat nama payment sementara
        $generateFakeUser = $this->paymentService->generateFakeUser();
        $name = $generateFakeUser['user']['name'];
        // --- step 5 - end - buat nama payment sementara

        return response()->json(['status' => 'success', 'username' => $name]);
    }

    /**
     * Menambahkan metode pembayaran pengguna.
     *
     * Payload rekening, metode, serta user pemilik divalidasi dan dibatasi ke session aktif. Rekening
     * baru hanya disimpan setelah field wajib bertipe string, pencarian opsional valid, dan katalog
     * bank sesuai. Error tipe 422 terjadi sebelum insert; batas sepuluh rekening tetap berlaku.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function addPayment(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi id user
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        $user_id = $user->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi id user

        // --- step 2 - start - validasi request
        $validator = Validator::make($request->all(), [
            'paymentName' => ['required', 'string'],
            'paymentSlug' => ['required', 'string'],
            'paymentAccount' => ['required', 'string'],
            'paymentUsername' => ['required', 'string'],
            'searchPayment' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        /** @var array{paymentName: string, paymentSlug: string, paymentAccount: string, paymentUsername: string, searchPayment?: string|null} $validated */
        $validated = $validator->validated();
        $searchPayment = $validated['searchPayment'] ?? '';
        // --- step 2 - end - validasi request

        // --- step 3 - start - validasi batas maksimal sepuluh payment
        $totalPayment = PaymentUser::where('user_id', $user_id)
            ->count();

        if ($totalPayment >= 10) {
            return response()->json(['status' => 'error', 'message' => 'Rekening Tidak Boleh Lebih Dari 10'], 400);
        }
        // --- step 3 - end - validasi batas maksimal sepuluh payment

        // --- step 4 - start - validasi nama dan slug payment
        $paymentList = PaymentList::where('type', 'withdrawal')
            ->where('slug', $validated['paymentSlug'])
            ->where('name', $validated['paymentName'])
            ->first();

        if (! $paymentList) {
            return response()->json(['status' => 'error', 'message' => 'Tipe Rekening Bank Tidak Tersedia'], 400);
        }
        // --- step 4 - end - validasi nama dan slug payment

        // --- step 5 - start - buat akun payment user
        PaymentUser::create([
            'user_id' => $user_id,
            'payment_id' => ($paymentList->id ?? null),
            'name' => $validated['paymentUsername'],
            'account' => $validated['paymentAccount'],
        ]);
        // --- step 5 - end - buat akun payment user

        // --- step 6 - start - proses pengambilan payment
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $searchPayment,
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
     * Pencarian non-string ditolak dengan 422 sebelum penghapusan agar mutasi tidak mendahului error.
     *
     * @param  string  $id  Identifier record yang menjadi target operasi.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function deletePayment(string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi id user
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        $user_id = $user->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi id user

        // --- step 2 - start - validasi pencarian sebelum menghapus rekening
        $validator = Validator::make($request->all(), [
            'searchPayment' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        /** @var array{searchPayment?: string|null} $validated */
        $validated = $validator->validated();
        $searchPayment = $validated['searchPayment'] ?? '';
        // --- step 2 - end - validasi pencarian sebelum menghapus rekening

        // --- step 3 - start - validasi lalu hapus payment
        $paymentUser = PaymentUser::where('id', $id)
            ->where('user_id', $user_id)
            ->first();

        if (! $paymentUser) {
            return response()->json(['status' => 'error', 'message' => 'Data Rekening Tidak Ditemukan'], 400);
        }

        $paymentUser->delete();
        // --- step 3 - end - validasi lalu hapus payment

        // --- step 4 - start - proses pengambilan payment
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        // --- step 4 - end - proses pengambilan payment

        return response()->json(['status' => 'success', 'payments' => $payments, 'message' => 'Rekening Berhasil Dihapus']);
    }

    /**
     * Menjalankan simulasi pembayaran virtual account untuk kebutuhan pengujian.
     *
     * Identitas user dan tipe string input diperiksa sebelum ownership, status pending, serta expiry
     * invoice. Input non-string menghasilkan 422; kegagalan bisnis/provider mempertahankan 400.
     * Provider fixed VA dipanggil dan invoice baru ditandai done setelah simulasi berhasil.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function simulateChargeVirtualAccount(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi id user
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        $user_id = $user->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi id user

        // --- step 2 - start - validasi request
        $validator = Validator::make($request->all(), [
            'payment_slug' => ['nullable', 'string'],
            'payment_account' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        /** @var array{payment_slug?: string|null, payment_account?: string|null} $validated */
        $validated = $validator->validated();
        $paymentSlug = $validated['payment_slug'] ?? '';
        $paymentAccount = $validated['payment_account'] ?? '';
        if (empty($paymentSlug) || trim($paymentSlug) == '') {
            return response()->json(['status' => 'error', 'message' => 'Nama Bank Harus Dipilih'], 400);
        }
        if (empty($paymentAccount) || trim($paymentAccount) == '') {
            return response()->json(['status' => 'error', 'message' => 'Nomor Virtual Account Harus Dipilih'], 400);
        }
        // --- step 2 - end - validasi request

        // --- step 3 - start - validasi kepemilikan virtual account
        $now = Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s');
        $transactionInvoice = TransactionInvoice::where('user_id_buyer', $user_id)
            ->where('payment_account', $paymentAccount)
            ->where('payment_slug', $paymentSlug)
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
        // Preserve the existing weak int-parameter conversion, including truncation of legacy fractions.
        $amount = (int) ($transactionInvoice->price ?? 0);
        $simulateVirtualAccountFixed = $this->xenditService->simulateVirtualAccountFixed(
            external_id: $transactionInvoice->payment_reference ?? '',
            amount: $amount
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
