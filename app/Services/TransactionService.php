<?php

namespace App\Services;

use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Menyusun daftar transaksi buyer/seller dan menerapkan pencatatan saldo transaksi seller.
 *
 * @phpstan-type TransactionFilters array{
 *     status?: string|null,
 *     search?: string|null,
 *     sort?: string|null,
 *     page?: int|float|string|null,
 *     per_page?: int|float|string|null,
 *     date_from?: string|null,
 *     date_to?: string|null
 * }
 * @phpstan-type TransactionResult array{
 *     status: 'error',
 *     message: string
 * }|array{
 *     status: 'success',
 *     transactions: Collection<int, TransactionUser|stdClass>,
 *     counts: array{all: int, paid: int, pending_payment: int, waiting_seller: int, done: int},
 *     pagination: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}
 * }
 */
class TransactionService
{
    /**
     * Hasil approve: transaksi sudah ditandai selesai dan income seller sudah dikredit.
     */
    public const APPROVAL_APPROVED = 'approved';

    /**
     * Hasil approve: transaksi tidak ada, milik seller lain, atau tidak lagi menunggu seller.
     */
    public const APPROVAL_NOT_FOUND = 'not_found';

    /**
     * Hasil approve: invoice transaksi belum dibayar buyer atau transaksi tidak punya invoice.
     */
    public const APPROVAL_UNPAID = 'unpaid';

    /**
     * Mengambil daftar transaksi dari perspektif buyer atau seller beserta counts dan pagination.
     *
     * Query transaksi dibangun dari perspektif role user, lalu filter pencarian, tanggal, dan status
     * diterapkan secara terpisah. Relasi yang dibutuhkan dimuat untuk menghasilkan data tampilan tanpa
     * membuka transaksi milik user lain.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $user_type  Perspektif buyer atau seller yang menentukan scope transaksi.
     * @param  TransactionFilters  $filters  Filter daftar transaksi; key yang tidak diberikan memakai default existing.
     *
     * @return TransactionResult Hasil daftar transaksi atau pesan kegagalan validasi ID dan perspektif user.
     */
    public function getTransaction(string $user_id, string $user_type, array $filters = []): array
    {
        // --- step 1 - validasi input - start ---
        if (empty($user_id) || trim($user_id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        if (empty($user_type) || ! in_array($user_type, ['seller', 'buyer'])) {
            return ['status' => 'error', 'message' => 'user type cannot be empty and must be seller or buyer'];
        }
        // --- step 1 - validasi input - end ---

        // --- step 2 - ambil transaksi user - start ---
        $perPage = min(max((int) ($filters['per_page'] ?? 5), 1), 20);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $sortOrder = ($filters['sort'] ?? 'newest') == 'oldest' ? 'asc' : 'desc';

        $baseTransactions = $this->createBaseTransactionQuery();

        $this->applyUserTypeFilter($baseTransactions, $user_id, $user_type);
        $this->applySearchFilter($baseTransactions, $filters['search'] ?? '');
        $this->applyDateFilter($baseTransactions, $filters['date_from'] ?? '', $filters['date_to'] ?? '');

        $counts = [
            'all' => (clone $baseTransactions)->count('transaction_users.id'),
            'paid' => $this->applyStatusFilter(clone $baseTransactions, 'paid')->count('transaction_users.id'),
            'pending_payment' => $user_type == 'buyer'
                ? $this->applyStatusFilter(clone $baseTransactions, 'pending_payment')
                    ->distinct()
                    ->count('transaction_invoices.id')
                : $this->applyStatusFilter(clone $baseTransactions, 'pending_payment')->count('transaction_users.id'),
            'waiting_seller' => $this->applyStatusFilter(clone $baseTransactions, 'waiting_seller')->count('transaction_users.id'),
            'done' => $this->applyStatusFilter(clone $baseTransactions, 'done')->count('transaction_users.id'),
        ];

        if ($user_type == 'buyer' && ($filters['status'] ?? 'all') == 'pending_payment') {
            $transactions = $this->getPendingBuyerInvoices($baseTransactions, $user_id, $sortOrder);
            $pendingInvoiceCount = $transactions->count();

            // Buyer membayar satu invoice satu kali; pagination tidak dipakai agar badge dan action queue
            // selalu merepresentasikan kumpulan invoice yang sama.
            $transactionItems = $transactions;
            $pagination = [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $pendingInvoiceCount,
                'total' => $pendingInvoiceCount,
                'from' => $pendingInvoiceCount > 0 ? 1 : 0,
                'to' => $pendingInvoiceCount,
            ];
        } else {
            $transactions = $this->selectTransactionFields(
                clone $baseTransactions,
                $user_type == 'buyer'
            );

            $transactions = $this->applyStatusFilter($transactions, $filters['status'] ?? 'all')
                ->orderBy('transaction_users.created_at', $sortOrder)
                ->paginate($perPage, ['*'], 'page', $page);

            $transactions->setCollection($this->prepareTransactionRows($transactions->getCollection()));
            // The paginator holds the indexed query rows; its framework stub loses the key type.
            /** @var Collection<int, TransactionUser|stdClass> $transactionItems */
            $transactionItems = $transactions->getCollection();
            $pagination = [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'from' => $transactions->firstItem(),
                'to' => $transactions->lastItem(),
            ];
        }
        // --- step 2 - ambil transaksi user - end ---

        return [
            'status' => 'success',
            'transactions' => $transactionItems,
            'counts' => $counts,
            'pagination' => $pagination,
        ];
    }

    /**
     * Membuat query dasar transaksi seller yang menjadi sumber daftar buyer dan seller.
     *
     * Query selalu berangkat dari transaksi per seller karena data pengiriman dan produk berada pada
     * level tersebut. Daftar pending buyer dapat mengelompokkan hasilnya kembali per invoice tanpa
     * mengubah sumber data seller atau transaksi yang sudah dibayar.
     *
     * @return Builder<TransactionUser> Query transaksi dengan join invoice, buyer, seller, dan perusahaan seller.
     */
    private function createBaseTransactionQuery(): Builder
    {
        return TransactionUser::query()
            ->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
            ->join('users as buyer_users', 'buyer_users.id', '=', 'transaction_users.user_id_buyer')
            ->join('users as seller_users', 'seller_users.id', '=', 'transaction_users.user_id_seller')
            ->leftJoin('companies as seller_companies', 'seller_companies.user_id', '=', 'transaction_users.user_id_seller');
    }

    /**
     * Memilih kolom transaksi standar untuk daftar transaksi dan paket invoice pending.
     *
     * Daftar kolom dipusatkan agar response transaksi reguler dan paket toko pada invoice pending
     * tidak berkembang dengan field yang berbeda. Nomor virtual account hanya ditambahkan untuk
     * response buyer karena seller tidak boleh menerima data pembayaran buyer tersebut. Total invoice
     * hanya dipakai pada item pending yang dikelompokkan; row reguler memakai total paket toko.
     *
     * @param  Builder<TransactionUser>  $query  Query transaksi dasar yang akan menerima kolom response.
     * @param  bool  $includePaymentAccount  Menentukan apakah nomor virtual account buyer disertakan.
     * @param  bool  $useInvoiceTotal  Menentukan apakah total invoice dipakai alih-alih total paket toko.
     *
     * @return Builder<TransactionUser> Query transaksi dengan kolom response yang telah dipilih.
     */
    private function selectTransactionFields(Builder $query, bool $includePaymentAccount = false, bool $useInvoiceTotal = false): Builder
    {
        $totalPrice = $useInvoiceTotal
            ? 'transaction_invoices.price as total_price'
            : 'COALESCE(transaction_users.product_price, 0) + COALESCE(transaction_users.kurir_price, 0) as total_price';

        $query->select(
            'transaction_users.id',
            'transaction_invoices.status as invoice_status',
            'transaction_invoices.id as invoice_id',
            'transaction_users.status as transaction_status',
            'transaction_users.transaction_number',
            'buyer_users.name as buyer_name',
            DB::raw("COALESCE(NULLIF(seller_companies.name, ''), seller_users.name) as seller_name"),
            'transaction_invoices.payment_name',
            'transaction_users.created_at as transaction_date',
            'transaction_users.kurir_type',
            'transaction_users.kurir_estimate',
            'transaction_users.kurir_price',
            'transaction_users.product_price',
            'transaction_users.noted',
            'transaction_invoices.alamat_buyer',
            DB::raw($totalPrice),
            'transaction_invoices.expired_at'
        );

        if ($includePaymentAccount) {
            $query->addSelect('transaction_invoices.payment_account');
        }

        return $query;
    }

    /**
     * Menghasilkan daftar invoice pending buyer beserta seluruh paket toko di dalamnya.
     *
     * Search dan tanggal pada query awal menentukan invoice mana yang cocok. Setelah invoice cocok,
     * seluruh paket pada invoice ikut dimuat agar modal buyer tidak kehilangan toko lain hanya karena
     * keyword hanya cocok dengan satu produk atau seller.
     *
     * @param  Builder<TransactionUser>  $filteredTransactions  Query buyer yang sudah dibatasi ownership, search, dan tanggal.
     * @param  string  $userId  ID buyer pemilik invoice pending yang akan dimuat.
     * @param  string  $sortOrder  Arah urutan tanggal transaksi, asc atau desc.
     *
     * @return Collection<int, TransactionUser|stdClass> Model transaksi satu toko atau objek invoice gabungan dengan seluruh paket tokonya.
     */
    private function getPendingBuyerInvoices(Builder $filteredTransactions, string $userId, string $sortOrder): Collection
    {
        // --- step 1 - tentukan invoice pending yang cocok dengan filter buyer - start ---
        $invoiceIds = $this->applyStatusFilter(clone $filteredTransactions, 'pending_payment')
            ->distinct()
            ->pluck('transaction_invoices.id');

        if ($invoiceIds->isEmpty()) {
            return collect();
        }
        // --- step 1 - tentukan invoice pending yang cocok dengan filter buyer - end ---

        // --- step 2 - muat seluruh paket dari invoice yang cocok - start ---
        $packages = $this->selectTransactionFields(
            $this->createBaseTransactionQuery()
                ->where('transaction_users.user_id_buyer', $userId)
                ->whereIn('transaction_invoices.id', $invoiceIds)
                ->where('transaction_invoices.status', 'pending'),
            true,
            true
        )
            ->orderBy('transaction_users.created_at', $sortOrder)
            ->get();
        // --- step 2 - muat seluruh paket dari invoice yang cocok - end ---

        // --- step 3 - bentuk satu item tampilan untuk setiap invoice - start ---
        // Declare the shared model/object contract: PHPStan 1 treats Collection values as invariant.
        /** @var Collection<int, TransactionUser|stdClass> $invoices */
        $invoices = $this->prepareTransactionRows($packages)
            ->groupBy('invoice_id')
            ->map(function (Collection $invoicePackages): TransactionUser|stdClass {
                /** @var Collection<int, TransactionUser> $invoicePackages */

                // groupBy() creates each group from existing rows, so this group is non-empty.
                /** @var TransactionUser $invoice */
                $invoice = $invoicePackages->first();

                // Satu toko sudah memiliki satu transaksi dan satu VA, sehingga response lama tetap
                // dipertahankan. Pengelompokan hanya diperlukan ketika satu invoice mencakup beberapa toko.
                if ($invoicePackages->count() == 1) {
                    return $invoice;
                }

                return (object) [
                    'id' => $invoice->getAttribute('invoice_id'),
                    'invoice_status' => $invoice->getAttribute('invoice_status'),
                    'invoice_id' => $invoice->getAttribute('invoice_id'),
                    'payment_name' => $invoice->getAttribute('payment_name'),
                    'payment_account' => $invoice->getAttribute('payment_account'),
                    'transaction_date' => $invoice->getAttribute('transaction_date'),
                    'alamat_buyer' => $invoice->getAttribute('alamat_buyer'),
                    'total_price' => $invoice->getAttribute('total_price'),
                    'expired_at' => $invoice->getAttribute('expired_at'),
                    'packages' => $invoicePackages->values(),
                ];
            })
            ->values();

        return $invoices;
        // --- step 3 - bentuk satu item tampilan untuk setiap invoice - end ---
    }

    /**
     * Memformat tanggal dan melampirkan produk untuk setiap transaksi seller secara batch.
     *
     * Produk dimuat dalam satu query berdasarkan seluruh ID transaksi yang sedang ditampilkan agar
     * invoice pending dengan beberapa toko tidak membuat query tambahan untuk setiap paket.
     *
     * @param  Collection<int, TransactionUser>  $transactions  Model transaksi hasil query daftar atau paket invoice pending.
     *
     * @return Collection<int, TransactionUser> Model transaksi yang sama dengan tanggal lokal dan daftar produk terkait.
     */
    private function prepareTransactionRows(Collection $transactions): Collection
    {
        if ($transactions->isEmpty()) {
            return $transactions;
        }

        // --- step 1 - muat produk untuk seluruh transaksi yang sedang ditampilkan - start ---
        $productsByTransaction = TransactionProduct::query()
            ->select(
                'transaction_products.transaction_user_id',
                'products.name',
                'products.img',
                'transaction_products.price',
                'transaction_products.total'
            )
            ->join('products', 'products.id', '=', 'transaction_products.product_id')
            ->whereIn('transaction_products.transaction_user_id', $transactions->pluck('id'))
            ->get()
            ->groupBy('transaction_user_id');
        // --- step 1 - muat produk untuk seluruh transaksi yang sedang ditampilkan - end ---

        // --- step 2 - format data transaksi untuk tampilan buyer atau seller - start ---
        return $transactions->map(function (TransactionUser $item) use ($productsByTransaction): TransactionUser {
            // These timestamps belong to the joined projection; keep nullable Carbon parsing unchanged.
            /** @var string|null $transactionDate */
            $transactionDate = $item->getAttribute('transaction_date');
            /** @var string|null $expiredAt */
            $expiredAt = $item->getAttribute('expired_at');

            $item->setAttribute(
                'transaction_date',
                Carbon::parse($transactionDate)
                    ->setTimezone('Asia/Jakarta')
                    ->translatedFormat('d F Y H:i')
            );
            $item->setAttribute(
                'expired_at',
                Carbon::parse($expiredAt)
                    ->setTimezone('Asia/Jakarta')
                    ->translatedFormat('d F Y H:i')
            );
            $item->setAttribute('products', $productsByTransaction->get($item->id, collect())->values());

            return $item;
        });
        // --- step 2 - format data transaksi untuk tampilan buyer atau seller - end ---
    }

    /**
     * Membatasi query transaksi berdasarkan peran buyer atau seller.
     *
     * @param  Builder<TransactionUser>  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $user_type  Perspektif buyer atau seller yang menentukan scope transaksi.
     *
     * @return Builder<TransactionUser> Query builder yang telah ditambahkan scope atau kondisi terkait.
     */
    private function applyUserTypeFilter($query, string $user_id, string $user_type): Builder
    {
        if ($user_type == 'seller') {
            return $query->where('transaction_users.user_id_seller', $user_id);
        }

        return $query->where('transaction_users.user_id_buyer', $user_id);
    }

    /**
     * Menerapkan pencarian invoice, transaksi, user, pembayaran, dan produk.
     *
     * Keyword diterapkan pada nomor invoice, nomor transaksi, nama user, metode pembayaran, dan nama
     * produk melalui relasi terkait. Seluruh kondisi dikelompokkan agar filter lain tetap digabungkan
     * menggunakan logika AND.
     *
     * @param  Builder<TransactionUser>  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $search  Kata kunci pencarian yang akan diterapkan.
     *
     * @return Builder<TransactionUser> Query builder yang telah ditambahkan scope atau kondisi terkait.
     */
    private function applySearchFilter($query, string $search): Builder
    {
        $search = trim($search);
        if (empty($search)) {
            return $query;
        }

        $invoiceIdExpression = DB::getDriverName() == 'pgsql'
            ? 'transaction_invoices.id::text'
            : 'CAST(transaction_invoices.id AS TEXT)';

        return $query->where(function ($query) use ($search, $invoiceIdExpression) {
            $query->whereRaw("{$invoiceIdExpression} like ?", ["%{$search}%"])
                ->orWhere('transaction_users.transaction_number', 'like', "%{$search}%")
                ->orWhere('buyer_users.name', 'like', "%{$search}%")
                ->orWhere('seller_users.name', 'like', "%{$search}%")
                ->orWhere('seller_companies.name', 'like', "%{$search}%")
                ->orWhere('transaction_invoices.payment_name', 'like', "%{$search}%")
                ->orWhereExists(function ($query) use ($search) {
                    $query->selectRaw(1)
                        ->from('transaction_products')
                        ->join('products', 'products.id', '=', 'transaction_products.product_id')
                        ->whereColumn('transaction_products.transaction_user_id', 'transaction_users.id')
                        ->where('products.name', 'like', "%{$search}%");
                });
        });
    }

    /**
     * Membatasi transaksi menggunakan rentang tanggal yang valid.
     *
     * Tanggal awal dan akhir dikonversi menjadi batas hari yang sesuai sebelum kondisi query
     * ditambahkan. Filter hanya diterapkan ketika kedua nilai telah lolos validasi format.
     *
     * @param  Builder<TransactionUser>  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $date_from  Tanggal awal filter transaksi.
     * @param  string  $date_to  Tanggal akhir filter transaksi.
     *
     * @return Builder<TransactionUser> Query builder yang telah ditambahkan scope atau kondisi terkait.
     */
    private function applyDateFilter($query, string $date_from, string $date_to): Builder
    {
        if ($this->isValidDate($date_from)) {
            $query->whereDate('transaction_users.created_at', '>=', $date_from);
        }

        if ($this->isValidDate($date_to)) {
            $query->whereDate('transaction_users.created_at', '<=', $date_to);
        }

        return $query;
    }

    /**
     * Memeriksa apakah tanggal menggunakan format ISO YYYY-MM-DD.
     *
     * @param  string  $date  Tanggal kalender yang akan dikonversi menjadi batas waktu.
     *
     * @return bool True ketika kondisi is valid date terpenuhi; false jika tidak.
     */
    private function isValidDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) == 1;
    }

    /**
     * Membatasi query transaksi berdasarkan status bisnis yang dipilih.
     *
     * Status pilihan dipetakan ke kolom status transaksi yang diizinkan. Nilai di luar kontrak tidak
     * membentuk kondisi query arbitrer.
     *
     * @param  Builder<TransactionUser>  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $status  Status bisnis yang akan diterapkan atau difilter.
     *
     * @return Builder<TransactionUser> Query builder yang telah ditambahkan scope atau kondisi terkait.
     */
    private function applyStatusFilter($query, string $status): Builder
    {
        if ($status == 'pending_payment') {
            return $query->where('transaction_invoices.status', 'pending');
        }

        if ($status == 'paid') {
            return $query->where('transaction_invoices.status', 'done');
        }

        if ($status == 'waiting_seller') {
            return $query->where('transaction_invoices.status', 'done')
                ->where('transaction_users.status', 'approved_seller');
        }

        if ($status == 'done') {
            return $query->where('transaction_invoices.status', 'done')
                ->where('transaction_users.status', 'done');
        }

        return $query;
    }

    /**
     * Menyelesaikan transaksi seller dan mengkredit income-nya dalam satu DB transaction.
     *
     * Dipakai oleh endpoint approve seller. Perubahan status ke `done` dan kredit income tersimpan
     * bersama atau di-rollback bersama. Sebelumnya status disimpan lebih dulu di luar transaksi
     * kredit, sehingga kredit yang gagal meninggalkan transaksi `done` tanpa income dan approve
     * ulang mendapat 404; seller kehilangan income itu. Kepemilikan, status, dan pembayaran
     * invoice diperiksa pada row yang sudah dikunci, lihat approveLockedTransaction().
     *
     * @param  string  $user_id  ID seller terautentikasi; hanya transaksi miliknya yang diproses.
     * @param  string  $transaction_user_id  ID transaksi seller yang akan diselesaikan.
     *
     * @return self::APPROVAL_* Hasil approve yang dipetakan controller ke respons HTTP.
     *
     * @throws Throwable Jika query gagal; status, saldo, dan riwayat di-rollback bersama.
     */
    public function approveSellerTransaction(string $user_id, string $transaction_user_id): string
    {
        // Generic DB::transaction melebarkan literal hasil menjadi string biasa.
        /** @var self::APPROVAL_* $approval */
        $approval = DB::transaction(
            fn (): string => $this->approveLockedTransaction($user_id, $transaction_user_id)
        );

        return $approval;
    }

    /**
     * Mengunci transaksi yang menunggu seller, menandainya selesai, lalu mengkredit income.
     *
     * Hanya dipanggil dari DB::transaction milik approveSellerTransaction(). Row
     * `transaction_users` tetap terkunci sampai status dan kredit commit, sehingga approve lain
     * untuk row yang sama menunggu lalu melihat status `done`. Transaksi hanya diselesaikan bila
     * invoice-nya sudah `done`. Hasil not found dan unpaid dikembalikan tanpa exception agar
     * transaksi commit tanpa perubahan dan lock segera dilepas.
     *
     * @param  string  $user_id  ID seller terautentikasi pemilik transaksi.
     * @param  string  $transaction_user_id  ID transaksi seller yang akan diselesaikan.
     *
     * @return self::APPROVAL_* Approved setelah kredit tersimpan; not found/unpaid tanpa mutasi.
     */
    private function approveLockedTransaction(string $user_id, string $transaction_user_id): string
    {
        // --- step 1 - kunci transaksi yang menunggu persetujuan seller - start ---
        // Lock ini satu-satunya penjaga agar satu transaksi tidak dikredit dua kali. Tanpa
        // `FOR UPDATE`, dua approve bersamaan sama-sama membaca `approved_seller`, income seller
        // bertambah dua kali, dan kelebihannya bisa ditarik lewat /api/saldo-withdraw atas biaya
        // perusahaan. Lock saldo di creditLockedSaldo() tidak mencegahnya karena hanya membuat dua
        // kredit berjalan bergantian. Dengan lock ini, approve kedua menunggu; setelah approve
        // pertama commit, PostgreSQL (READ COMMITTED, default yang tidak diubah aplikasi)
        // mengevaluasi ulang filter status pada versi row terbaru sehingga approve kedua mendapat
        // null tanpa kredit.
        // Urutan lock selalu `transaction_users` lalu `saldo_users`. Penarikan saldo hanya
        // mengunci `saldo_users` dan tidak ada kode lain yang mengunci `transaction_users`, jadi
        // tidak ada siklus deadlock. SQLite mengabaikan klausa lock ini.
        $transactionUser = TransactionUser::where('id', $transaction_user_id)
            ->where('user_id_seller', $user_id)
            ->where('status', 'approved_seller')
            ->lockForUpdate()
            ->first();

        if ($transactionUser === null) {
            return self::APPROVAL_NOT_FOUND;
        }
        // --- step 1 - kunci transaksi yang menunggu persetujuan seller - end ---

        // --- step 2 - pastikan invoice sudah dibayar - start ---
        // Checkout membuat row transaksi dengan status default `approved_seller` saat invoice
        // masih `pending`. Sebelumnya endpoint hanya memeriksa status itu, sehingga seller bisa
        // memanggil API langsung untuk mengkredit income dari pesanan yang belum dibayar lalu
        // menariknya; perusahaan menanggung dana itu. Tombol approve di frontend hanya
        // disembunyikan dan bukan pengaman. Transaksi tanpa invoice juga ditolak karena tidak ada
        // bukti pembayaran.
        // Invoice cukup dibaca tanpa lock: satu-satunya penulis statusnya (PaymentController)
        // hanya mengubah `pending` menjadi `done`, jadi invoice `done` tidak bisa kembali belum
        // dibayar selama transaksi ini berjalan.
        $invoiceStatus = $transactionUser->invoice()->value('status');

        if ($invoiceStatus !== 'done') {
            return self::APPROVAL_UNPAID;
        }
        // --- step 2 - pastikan invoice sudah dibayar - end ---

        // --- step 3 - tandai transaksi selesai - start ---
        $transactionUser->status = 'done';
        $transactionUser->save();
        // --- step 3 - tandai transaksi selesai - end ---

        // --- step 4 - kredit income seller - start ---
        // transferSaldo() membuka transaksi bersarang (savepoint). Exception dari kredit naik ke
        // transaksi luar, sehingga status `done` di step 3 ikut di-rollback dan seller bisa
        // approve ulang.
        $this->transferSaldo(
            user_id: $user_id,
            transaction_user_id: $transactionUser->id,
            price: (float) $transactionUser->product_price,
            type: 'incoming'
        );
        // --- step 4 - kredit income seller - end ---

        return self::APPROVAL_APPROVED;
    }

    /**
     * Menambahkan nilai transaksi ke saldo income seller dan mencatat riwayatnya.
     *
     * Pembacaan saldo, penambahan income, dan row riwayat berjalan dalam satu transaksi database,
     * sehingga tersimpan bersama atau di-rollback bersama. Row saldo dikunci agar kredit ini dan
     * penarikan saldo milik user yang sama diproses bergantian. Status transaksi tidak dikunci di
     * sini. Approve seller memanggil method ini dari dalam transaksi approveSellerTransaction(),
     * yang sudah mengunci row transaksi agar transaksi yang sama tidak dikredit dua kali.
     *
     * @param  string  $user_id  ID seller pemilik saldo yang dikredit.
     * @param  string  $transaction_user_id  ID transaksi seller yang menjadi sumber mutasi.
     * @param  float  $price  Nominal yang ditambahkan; income menyimpan bagian bulatnya.
     * @param  string  $type  Jenis mutasi yang dicatat di riwayat, misalnya `incoming`.
     *
     * @return void Tidak mengembalikan nilai; proses berhasil ketika selesai tanpa exception.
     *
     * @throws Throwable Jika query gagal; perubahan saldo dan riwayat di-rollback bersama.
     */
    public function transferSaldo(
        string $user_id = '',
        string $transaction_user_id = '',
        float $price = 0,
        string $type = '',
    ): void {
        // Sebelumnya saldo dan riwayat ditulis tanpa transaksi, sehingga riwayat yang gagal
        // meninggalkan income yang sudah bertambah tanpa catatan.
        DB::transaction(function () use ($user_id, $transaction_user_id, $price, $type): void {
            $this->creditLockedSaldo($user_id, $transaction_user_id, $price, $type);
        });
    }

    /**
     * Mengunci row saldo seller, menambah income, lalu mencatat riwayat di transaksi aktif.
     *
     * Hanya dipanggil dari dalam DB::transaction milik transferSaldo(). Seller tanpa row saldo
     * mendapat row baru tanpa lock karena belum ada row yang bisa dikunci; dua kredit pertama
     * yang bersamaan masih bisa bertabrakan pada unique `user_id` dan berada di luar cakupan ini.
     *
     * @param  string  $user_id  ID seller pemilik saldo yang dikredit.
     * @param  string  $transaction_user_id  ID transaksi seller yang menjadi sumber mutasi.
     * @param  float  $price  Nominal yang ditambahkan; income menyimpan bagian bulatnya.
     * @param  string  $type  Jenis mutasi yang dicatat di riwayat, misalnya `incoming`.
     *
     * @return void Tidak mengembalikan nilai; saldo dan riwayat ditulis di transaksi pemanggil.
     */
    private function creditLockedSaldo(
        string $user_id,
        string $transaction_user_id,
        float $price,
        string $type,
    ): void {
        // --- step 1 - siapkan variabel proses - start ---
        $saldo_before = 0;
        $saldo_after = 0;
        // --- step 1 - siapkan variabel proses - end ---

        // --- step 2 - kunci row saldo seller - start ---
        // Tanpa `FOR UPDATE`, PostgreSQL membaca income lama tanpa menunggu penarikan yang sedang
        // mengunci row. Setelah penarikan commit, UPDATE di step 3 menulis nilai absolut dari
        // bacaan lama, sehingga pengurangan penarikan hilang dan seller bisa menarik dana yang
        // sama lagi. Perusahaan menanggung kelebihan itu. Dengan lock, kredit menunggu lalu
        // membaca saldo yang baru.
        $saldoUser = SaldoUser::where('user_id', $user_id)
            ->lockForUpdate()
            ->first();
        // --- step 2 - kunci row saldo seller - end ---

        // --- step 3 - tambah income seller - start ---
        if (empty($saldoUser)) {
            $saldo_after = $price;

            SaldoUser::create([
                'user_id' => $user_id,
                'balance' => $price,
                'saldo_income' => $saldo_after,
                'saldo_refund' => 0,
            ]);
        } else {
            $saldo_before = (int) $saldoUser->saldo_income + (int) $saldoUser->saldo_refund;

            $saldoUser->saldo_income += (int) $price;
            $saldoUser->save();

            $saldo_after = (int) $saldoUser->saldo_income + (int) $saldoUser->saldo_refund;
        }
        // --- step 3 - tambah income seller - end ---

        // --- step 4 - simpan riwayat saldo - start ---
        SaldoHistory::create([
            'user_id' => $user_id,
            'transaction_user_id' => $transaction_user_id,
            'payment_user_id' => null,
            'type' => $type,
            'price' => $price,
            'saldo_before' => $saldo_before,
            'saldo_after' => $saldo_after,
        ]);
        // --- step 4 - simpan riwayat saldo - end ---
    }
}
