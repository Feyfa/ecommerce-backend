<?php

namespace App\Http\Controllers;

use App\Models\TransactionUser;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan transaksi.
     *
     * @param  TransactionService  $transactionService  Layanan pengelolaan transaksi.
     *
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(protected TransactionService $transactionService) {}

    /**
     * Menampilkan transaksi sesuai peran dan filter pengguna.
     *
     * Identitas user diperiksa sebelum tipe filter divalidasi. Filter salah tipe menghasilkan 422;
     * role string yang kosong atau tidak dikenal tetap ditolak oleh service dengan respons 400.
     * Default, batas pagination, serta fallback status, urutan, dan tanggal tetap mengikuti service.
     * Data selalu dibatasi ke perspektif buyer atau seller milik user yang terautentikasi.
     *
     * @param  Request  $request  Filter dan identitas pengguna.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getTransaction(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $user_id = $user->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - validasi user - end ---

        // --- step 2 - validasi tipe filter sebelum membentuk query transaksi - start ---
        $validator = Validator::make($request->all(), [
            'user_type' => ['nullable', 'string'],
            'status_filter' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
            'page' => ['nullable', 'numeric'],
            'per_page' => ['nullable', 'numeric'],
            'date_from' => ['nullable', 'string'],
            'date_to' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }

        /**
         * @var array{
         *     user_type?: string|null,
         *     status_filter?: string|null,
         *     search?: string|null,
         *     sort?: string|null,
         *     page?: int|float|numeric-string|null,
         *     per_page?: int|float|numeric-string|null,
         *     date_from?: string|null,
         *     date_to?: string|null
         * } $validated
         */
        $validated = $validator->validated();
        $user_type = $validated['user_type'] ?? '';

        // Only validate types here; the service retains its existing fallback and pagination rules.
        $filters = [
            'status' => $validated['status_filter'] ?? 'all',
            'search' => $validated['search'] ?? '',
            'sort' => $validated['sort'] ?? 'newest',
            'page' => $validated['page'] ?? 1,
            'per_page' => $validated['per_page'] ?? 5,
            'date_from' => $validated['date_from'] ?? '',
            'date_to' => $validated['date_to'] ?? '',
        ];
        // --- step 2 - validasi tipe filter sebelum membentuk query transaksi - end ---

        // --- step 3 - ambil transaksi sesuai perspektif pengguna - start ---
        $getTransaction = $this->transactionService->getTransaction($user_id, $user_type, $filters);
        $status = $getTransaction['status'];
        $message = $getTransaction['message'] ?? '';
        $transactions = $getTransaction['transactions'] ?? [];
        $counts = $getTransaction['counts'] ?? [];
        $pagination = $getTransaction['pagination'] ?? [];
        if ($status == 'error') {
            return response()->json(['status' => $status, 'message' => $message], 400);
        }
        // --- step 3 - ambil transaksi sesuai perspektif pengguna - end ---

        return response()->json([
            'status' => 'success',
            'transactions' => $transactions,
            'counts' => $counts,
            'pagination' => $pagination,
        ]);
    }

    /**
     * Menyetujui transaksi dan menerapkan perubahan status terkait.
     *
     * Identitas user diperiksa sebelum input. `user_type` harus persis string `seller`, dan
     * `transaction_user_id` yang tidak kosong tetapi bukan string ditolak dengan 422 sebelum query
     * transaksi dijalankan. Service menerapkan perpindahan status serta saldo yang relevan,
     * kemudian controller mengembalikan hasil terbaru atau error bisnis tanpa mutasi tambahan.
     *
     * @param  Request  $request  Data transaksi yang disetujui.
     *
     * @return JsonResponse Daftar transaksi seller terbaru atau detail kegagalan untuk client.
     */
    public function approvedTransaction(Request $request): JsonResponse
    {
        // --- step 1 - validasi user - start ---
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $user_id = $user->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - validasi user - end ---

        // --- step 2 - validasi perspektif seller - start ---
        // Perbandingan ketat: dengan `!=`, JSON `true` dianggap sama dengan 'seller'
        // dan transaksi ikut disetujui.
        $user_type = $request->user_type ?? '';

        if ($user_type !== 'seller') {
            return response()->json(
                ['status' => 'error', 'message' => 'This Action Only For Seller'],
                400,
            );
        }
        // --- step 2 - validasi perspektif seller - end ---

        // --- step 3 - validasi ID transaksi - start ---
        // `?:` mengubah semua nilai kosong (null, false, 0, [], "0") menjadi '' sehingga tetap
        // mendapat 400 seperti sebelumnya. Nilai non-string lain sebelumnya membuat trim()
        // melempar TypeError, atau ditolak PostgreSQL sebagai UUID tidak valid (SQLSTATE 22P02).
        // Keduanya berakhir HTTP 500.
        $transaction_user_id = $request->transaction_user_id ?: '';

        if (! is_string($transaction_user_id)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Transaction User ID Must Be A String'],
                422,
            );
        }

        if (empty($transaction_user_id) || trim($transaction_user_id) == '') {
            return response()->json(
                ['status' => 'error', 'message' => 'Transaction User ID Cannot Be Empty'],
                400,
            );
        }
        // --- step 3 - validasi ID transaksi - end ---

        // --- step 4 - cari transaksi yang menunggu persetujuan - start ---
        // Filter `approved_seller` adalah satu-satunya penjaga agar satu transaksi tidak
        // dikredit dua kali: transaksi yang sudah `done` mendapat 404 sebelum transfer saldo.
        // Pembacaan ini tidak memakai DB transaction atau lock baris, sehingga dua request
        // bersamaan masih bisa sama-sama membaca `approved_seller`. Saldo seller lalu
        // bertambah dua kali, dan kelebihannya bisa ditarik lewat /api/saldo-withdraw.
        $transactionUser = TransactionUser::where('id', $transaction_user_id)
            ->where('user_id_seller', $user_id)
            ->where('status', 'approved_seller')
            ->first();

        if (empty($transactionUser)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Transaksi Tidak Ditemukan'],
                404,
            );
        }
        // --- step 4 - cari transaksi yang menunggu persetujuan - end ---

        // --- step 5 - tandai transaksi selesai - start ---
        $transactionUser->status = 'done';
        $transactionUser->save();
        // --- step 5 - tandai transaksi selesai - end ---

        // --- step 6 - transfer saldo ke seller - start ---
        // Status disimpan lebih dulu agar request berikutnya yang datang setelah save()
        // mendapat 404 dan tidak mengkredit saldo lagi.
        $this->transactionService->transferSaldo(
            user_id: $user_id,
            transaction_user_id: $transactionUser->id,
            price: (float) $transactionUser->product_price,
            type: 'incoming'
        );
        // --- step 6 - transfer saldo ke seller - end ---

        // --- step 7 - ambil transaksi - start ---
        $getTransaction = $this->transactionService->getTransaction($user_id, $user_type);

        $transactions = $getTransaction['transactions'] ?? [];
        $counts = $getTransaction['counts'] ?? [];
        $pagination = $getTransaction['pagination'] ?? [];
        // --- step 7 - ambil transaksi - end ---

        return response()->json([
            'status' => 'success',
            'transactions' => $transactions,
            'counts' => $counts,
            'pagination' => $pagination,
        ]);
    }
}
