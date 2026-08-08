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

class TransactionService
{
    /**
     * user_type disini itu maksudnya ingin mengambil history transaksi namun user tersebut sedang login di user type apa
     *
     * Query transaksi dibangun dari perspektif role user, lalu filter pencarian, tanggal, dan status
     * diterapkan secara terpisah. Relasi yang dibutuhkan dimuat untuk menghasilkan data tampilan tanpa
     * membuka transaksi milik user lain.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $user_type  Perspektif buyer atau seller yang menentukan scope transaksi.
     * @param  array  $filters  Kumpulan filter transaksi yang telah divalidasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getTransaction(string $user_id, string $user_type, array $filters = []): array
    {
        // --- step 1 - start - validasi input
        if (empty($user_id) || trim($user_id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        if (empty($user_type) || ! in_array($user_type, ['seller', 'buyer'])) {
            return ['status' => 'error', 'message' => 'user type cannot be empty and must be seller or buyer'];
        }
        // --- step 1 - end - validasi input

        // --- step 2 - start - ambil transaksi user
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
        // --- step 2 - end - ambil transaksi user

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
     * @return Builder  Query builder dengan join invoice, buyer, seller, dan perusahaan seller.
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
     * @param  Builder  $query  Query transaksi dasar yang akan menerima kolom response.
     * @param  bool  $includePaymentAccount  Menentukan apakah nomor virtual account buyer disertakan.
     * @param  bool  $useInvoiceTotal  Menentukan apakah total invoice dipakai alih-alih total paket toko.
     *
     * @return Builder  Query transaksi dengan kolom response yang telah dipilih.
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
     * @param  Builder  $filteredTransactions  Query buyer yang sudah dibatasi ownership, search, dan tanggal.
     * @param  string  $userId  ID buyer pemilik invoice pending yang akan dimuat.
     * @param  string  $sortOrder  Arah urutan tanggal transaksi, asc atau desc.
     *
     * @return Collection  Kumpulan invoice pending yang setiap itemnya memiliki paket transaksi per toko.
     */
    private function getPendingBuyerInvoices(Builder $filteredTransactions, string $userId, string $sortOrder): Collection
    {
        // --- step 1 - start - tentukan invoice pending yang cocok dengan filter buyer
        $invoiceIds = $this->applyStatusFilter(clone $filteredTransactions, 'pending_payment')
            ->distinct()
            ->pluck('transaction_invoices.id');

        if ($invoiceIds->isEmpty()) {
            return collect();
        }
        // --- step 1 - end - tentukan invoice pending yang cocok dengan filter buyer

        // --- step 2 - start - muat seluruh paket dari invoice yang cocok
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
        // --- step 2 - end - muat seluruh paket dari invoice yang cocok

        // --- step 3 - start - bentuk satu item tampilan untuk setiap invoice
        return $this->prepareTransactionRows($packages)
            ->groupBy('invoice_id')
            ->map(function (Collection $invoicePackages) {
                $invoice = $invoicePackages->first();

                // Satu toko sudah memiliki satu transaksi dan satu VA, sehingga response lama tetap
                // dipertahankan. Pengelompokan hanya diperlukan ketika satu invoice mencakup beberapa toko.
                if ($invoicePackages->count() == 1) {
                    return $invoice;
                }

                return (object) [
                    'id' => $invoice->invoice_id,
                    'invoice_status' => $invoice->invoice_status,
                    'invoice_id' => $invoice->invoice_id,
                    'payment_name' => $invoice->payment_name,
                    'payment_account' => $invoice->payment_account,
                    'transaction_date' => $invoice->transaction_date,
                    'alamat_buyer' => $invoice->alamat_buyer,
                    'total_price' => $invoice->total_price,
                    'expired_at' => $invoice->expired_at,
                    'packages' => $invoicePackages->values(),
                ];
            })
            ->values();
        // --- step 3 - end - bentuk satu item tampilan untuk setiap invoice
    }

    /**
     * Memformat tanggal dan melampirkan produk untuk setiap transaksi seller secara batch.
     *
     * Produk dimuat dalam satu query berdasarkan seluruh ID transaksi yang sedang ditampilkan agar
     * invoice pending dengan beberapa toko tidak membuat query tambahan untuk setiap paket.
     *
     * @param  Collection  $transactions  Kumpulan transaksi seller yang akan disiapkan untuk response.
     *
     * @return Collection  Transaksi dengan tanggal lokal dan daftar produk terkait.
     */
    private function prepareTransactionRows(Collection $transactions): Collection
    {
        if ($transactions->isEmpty()) {
            return $transactions;
        }

        // --- step 1 - start - muat produk untuk seluruh transaksi yang sedang ditampilkan
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
        // --- step 1 - end - muat produk untuk seluruh transaksi yang sedang ditampilkan

        // --- step 2 - start - format data transaksi untuk tampilan buyer atau seller
        return $transactions->map(function ($item) use ($productsByTransaction) {
            $item->transaction_date = Carbon::parse($item->transaction_date)
                ->setTimezone('Asia/Jakarta')
                ->translatedFormat('d F Y H:i');
            $item->expired_at = Carbon::parse($item->expired_at)
                ->setTimezone('Asia/Jakarta')
                ->translatedFormat('d F Y H:i');
            $item->products = $productsByTransaction->get($item->id, collect())->values();

            return $item;
        });
        // --- step 2 - end - format data transaksi untuk tampilan buyer atau seller
    }

    /**
     * Membatasi query transaksi berdasarkan peran buyer atau seller.
     *
     * @param  Builder  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $user_type  Perspektif buyer atau seller yang menentukan scope transaksi.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
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
     * @param  Builder  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $search  Kata kunci pencarian yang akan diterapkan.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
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
     * @param  Builder  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $date_from  Tanggal awal filter transaksi.
     * @param  string  $date_to  Tanggal akhir filter transaksi.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
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
     * @return bool  True ketika kondisi is valid date terpenuhi; false jika tidak.
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
     * @param  Builder  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $status  Status bisnis yang akan diterapkan atau difilter.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
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
     * Memindahkan nilai transaksi ke saldo seller secara atomik.
     *
     * Row transaksi dan saldo dikunci dalam transaksi database sebelum nominal dipindahkan. Function
     * mencegah pemrosesan ganda dan mencatat saldo sebelum serta sesudah agar riwayat tetap dapat
     * diaudit.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $transaction_user_id  ID transaksi seller yang menjadi sumber mutasi.
     * @param  float  $price  Nominal uang yang digunakan oleh operasi.
     * @param  string  $type  Nilai type yang diperlukan untuk menjalankan proses ini.
     *
     * @return void  Tidak mengembalikan nilai; proses dinyatakan berhasil ketika selesai tanpa exception.
     */
    public function transferSaldo(string $user_id = '', string $transaction_user_id = '', float $price = 0, string $type = ''): void
    {
        // --- step 1 - start - siapkan variabel proses
        $saldo_before = 0;
        $saldo_after = 0;
        // --- step 1 - end - siapkan variabel proses

        // --- step 2 - start - perbarui saldo user
        $saldoUser = SaldoUser::where('user_id', $user_id)
            ->first();
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
        // --- step 2 - end - perbarui saldo user

        // --- step 3 - start - simpan riwayat saldo
        SaldoHistory::create([
            'user_id' => $user_id,
            'transaction_user_id' => $transaction_user_id,
            'payment_user_id' => null,
            'type' => $type,
            'price' => $price,
            'saldo_before' => $saldo_before,
            'saldo_after' => $saldo_after,
        ]);
        // --- step 3 - end - simpan riwayat saldo
    }
}
