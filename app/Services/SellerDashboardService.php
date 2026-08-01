<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SellerDashboardService
{
    /**
     * Mengambil ringkasan utama dashboard penjual dari data produk dan transaksi.
     *
     * Service menggabungkan summary, performa 30 hari, transaksi terbaru, dan snapshot produk untuk
     * seller yang sama. Waktu awal serta akhir bulan dihitung sekali agar seluruh metrik memakai
     * periode konsisten.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getDashboard(string $user_id): array
    {
        // --- step 1 - start - tentukan rentang tanggal
        $now = Carbon::now('Asia/Jakarta');
        $startOfMonth = $now->copy()->startOfMonth()->timezone('UTC');
        $endOfMonth = $now->copy()->endOfMonth()->timezone('UTC');
        // --- step 1 - end - tentukan rentang tanggal

        // --- step 2 - start - ambil seluruh data dashboard
        $dashboard = [
            'summary' => $this->getSummary($user_id, $startOfMonth, $endOfMonth),
            'performance' => $this->getPerformance($user_id),
            'recent_transactions' => $this->getRecentTransactions($user_id),
            'product_snapshot' => $this->getProductSnapshot($user_id),
        ];
        // --- step 2 - end - ambil seluruh data dashboard

        return $dashboard;
    }

    /**
     * Mengambil metrik utama toko seperti total produk, pesanan baru, total terjual, dan pendapatan bulanan.
     *
     * Query agregat menghitung jumlah produk, order baru, unit terjual, dan pendapatan seller pada
     * bulan berjalan. Hanya transaksi dengan status bisnis yang sesuai yang ikut pada masing-masing
     * metrik.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  Carbon  $startOfMonth  Batas awal bulan untuk perhitungan metrik.
     * @param  Carbon  $endOfMonth  Batas akhir bulan untuk perhitungan metrik.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function getSummary(string $user_id, Carbon $startOfMonth, Carbon $endOfMonth): array
    {
        // --- step 1 - start - siapkan query dasar
        $doneTransactions = $this->doneTransactionQuery($user_id);
        // --- step 1 - end - siapkan query dasar

        // --- step 2 - start - ambil ringkasan dashboard
        $summary = [
            'total_products' => Product::where('user_id_seller', $user_id)->count(),
            'new_orders' => $this->newOrderQuery($user_id)->count(),
            'total_sold' => (int) (clone $doneTransactions)
                ->join('transaction_products', 'transaction_products.transaction_user_id', '=', 'transaction_users.id')
                ->sum('transaction_products.total'),
            'monthly_revenue' => (float) (clone $doneTransactions)
                ->whereBetween('transaction_users.created_at', [$startOfMonth, $endOfMonth])
                ->sum('transaction_users.product_price'),
        ];
        // --- step 2 - end - ambil ringkasan dashboard

        return $summary;
    }

    /**
     * Mengambil data grafik penjualan 30 hari terakhir dari transaksi yang sudah selesai.
     *
     * Transaksi selesai dikelompokkan per hari untuk rentang 30 hari. Hari tanpa transaksi tetap diisi
     * nol agar frontend menerima rangkaian tanggal kontinu untuk grafik.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function getPerformance(string $user_id): array
    {
        // --- step 1 - start - tentukan rentang tanggal
        $startDate = Carbon::now('Asia/Jakarta')->subDays(29)->startOfDay();
        $endDate = Carbon::now('Asia/Jakarta')->endOfDay();
        // --- step 1 - end - tentukan rentang tanggal

        // --- step 2 - start - ambil data performa
        $rows = $this->doneTransactionQuery($user_id)
            ->whereBetween('transaction_users.created_at', [
                $startDate->copy()->timezone('UTC'),
                $endDate->copy()->timezone('UTC'),
            ])
            ->selectRaw('DATE(transaction_users.created_at) as transaction_date')
            ->selectRaw('SUM(transaction_products.price * transaction_products.total) as revenue')
            ->selectRaw('SUM(transaction_products.total) as sales')
            ->join('transaction_products', 'transaction_products.transaction_user_id', '=', 'transaction_users.id')
            ->groupBy(DB::raw('DATE(transaction_users.created_at)'))
            ->orderBy('transaction_date')
            ->get()
            ->keyBy('transaction_date');
        // --- step 2 - end - ambil data performa

        // --- step 3 - start - format data performa
        $labels = [];
        $sales = [];
        $revenue = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateKey = $date->toDateString();
            $row = $rows->get($dateKey);

            $labels[] = $date->translatedFormat('d M');
            $sales[] = (int) ($row->sales ?? 0);
            $revenue[] = (float) ($row->revenue ?? 0);
        }
        // --- step 3 - end - format data performa

        // --- step 4 - start - bentuk response
        $performance = [
            'period' => '30_days',
            'labels' => $labels,
            'sales' => $sales,
            'revenue' => $revenue,
            'total_sold' => array_sum($sales),
            'total_revenue' => array_sum($revenue),
        ];
        // --- step 4 - end - bentuk response

        return $performance;
    }

    /**
     * Mengambil transaksi terbaru seller untuk ditampilkan di dashboard.
     *
     * Transaksi seller terbaru dimuat bersama buyer, invoice, dan produk yang diperlukan tampilan.
     * Jumlah hasil dibatasi agar dashboard tidak menjalankan query history penuh.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function getRecentTransactions(string $user_id): array
    {
        // --- step 1 - start - ambil transaksi terbaru
        $recentTransactions = TransactionUser::query()
            ->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
            ->join('users as buyer_users', 'buyer_users.id', '=', 'transaction_users.user_id_buyer')
            ->where('transaction_users.user_id_seller', $user_id)
            ->select(
                'transaction_users.id',
                'transaction_users.transaction_number',
                'transaction_users.status',
                'transaction_users.product_price',
                'transaction_users.created_at',
                'transaction_invoices.status as invoice_status',
                'buyer_users.name as buyer_name'
            )
            ->orderBy('transaction_users.created_at', 'DESC')
            ->limit(5)
            ->get()
            ->map(function ($transaction) {
                // --- step 2 - start - ambil nama produk
                $products = TransactionProduct::query()
                    ->join('products', 'products.id', '=', 'transaction_products.product_id')
                    ->where('transaction_products.transaction_user_id', $transaction->id)
                    ->orderBy('products.name')
                    ->pluck('products.name');
                // --- step 2 - end - ambil nama produk

                return [
                    'id' => $transaction->id,
                    'transaction_number' => $transaction->transaction_number,
                    'buyer_name' => $transaction->buyer_name,
                    'product_names' => $products->join(', '),
                    'total_price' => (float) $transaction->product_price,
                    'status' => $transaction->status,
                    'invoice_status' => $transaction->invoice_status,
                    'transaction_date' => Carbon::parse($transaction->created_at)
                        ->setTimezone('Asia/Jakarta')
                        ->translatedFormat('d F Y H:i'),
                ];
            })
            ->toArray();
        // --- step 1 - end - ambil transaksi terbaru

        return $recentTransactions;
    }

    /**
     * Mengambil ringkasan kondisi produk seller berdasarkan stok dan tanggal pembuatan.
     *
     * Produk dikelompokkan menjadi stok sehat, rendah, kosong, dan produk baru berdasarkan invariant
     * yang sama dengan filter seller. Hasil agregat digunakan untuk kartu ringkasan tanpa memuat
     * seluruh model produk.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function getProductSnapshot(string $user_id): array
    {
        // --- step 1 - start - tentukan rentang tanggal
        $newProductStart = Carbon::now('Asia/Jakarta')->subDays(30)->startOfDay()->timezone('UTC');
        // --- step 1 - end - tentukan rentang tanggal

        // --- step 2 - start - ambil snapshot produk
        $snapshot = [
            'active_products' => Product::where('user_id_seller', $user_id)->where('stock', '>', 0)->count(),
            'low_stock_products' => Product::where('user_id_seller', $user_id)->whereBetween('stock', [1, 5])->count(),
            'empty_stock_products' => Product::where('user_id_seller', $user_id)->where('stock', '<=', 0)->count(),
            'new_products' => Product::where('user_id_seller', $user_id)->where('created_at', '>=', $newProductStart)->count(),
        ];
        // --- step 2 - end - ambil snapshot produk

        return $snapshot;
    }

    /**
     * Membuat base query transaksi seller yang sudah selesai dan sudah dibayar.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
     */
    private function doneTransactionQuery(string $user_id): Builder
    {
        return TransactionUser::query()
            ->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
            ->where('transaction_users.user_id_seller', $user_id)
            ->where('transaction_users.status', 'done')
            ->where('transaction_invoices.status', 'done');
    }

    /**
     * Membuat base query pesanan baru yang sudah dibayar dan masih perlu diproses seller.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
     */
    private function newOrderQuery(string $user_id): Builder
    {
        return TransactionUser::query()
            ->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
            ->where('transaction_users.user_id_seller', $user_id)
            ->where('transaction_users.status', 'approved_seller')
            ->where('transaction_invoices.status', 'done');
    }
}
