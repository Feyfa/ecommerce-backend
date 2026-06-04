<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SellerDashboardService
{
    /**
     * Mengambil ringkasan utama dashboard penjual dari data produk dan transaksi.
     */
    public function getDashboard(string $user_id): array
    {
        /* GET DATE RANGE */
        $now = Carbon::now('Asia/Jakarta');
        $startOfMonth = $now->copy()->startOfMonth()->timezone('UTC');
        $endOfMonth = $now->copy()->endOfMonth()->timezone('UTC');
        /* GET DATE RANGE */

        /* GET DASHBOARD DATA */
        return [
            'summary' => $this->getSummary($user_id, $startOfMonth, $endOfMonth),
            'performance' => $this->getPerformance($user_id),
            'recent_transactions' => $this->getRecentTransactions($user_id),
            'product_snapshot' => $this->getProductSnapshot($user_id),
        ];
        /* GET DASHBOARD DATA */
    }

    /**
     * Mengambil metrik utama toko seperti total produk, pesanan baru, total terjual, dan pendapatan bulanan.
     */
    private function getSummary(string $user_id, Carbon $startOfMonth, Carbon $endOfMonth): array
    {
        /* GET BASE QUERY */
        $doneTransactions = $this->doneTransactionQuery($user_id);
        /* GET BASE QUERY */

        /* GET SUMMARY */
        return [
            'total_products' => Product::where('user_id_seller', $user_id)->count(),
            'new_orders' => $this->newOrderQuery($user_id)->count(),
            'total_sold' => (int) (clone $doneTransactions)
                ->join('transaction_products', 'transaction_products.transaction_user_id', '=', 'transaction_users.id')
                ->sum('transaction_products.total'),
            'monthly_revenue' => (float) (clone $doneTransactions)
                ->whereBetween('transaction_users.created_at', [$startOfMonth, $endOfMonth])
                ->sum('transaction_users.product_price'),
        ];
        /* GET SUMMARY */
    }

    /**
     * Mengambil data grafik penjualan 30 hari terakhir dari transaksi yang sudah selesai.
     */
    private function getPerformance(string $user_id): array
    {
        /* GET DATE RANGE */
        $startDate = Carbon::now('Asia/Jakarta')->subDays(29)->startOfDay();
        $endDate = Carbon::now('Asia/Jakarta')->endOfDay();
        /* GET DATE RANGE */

        /* GET PERFORMANCE ROWS */
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
        /* GET PERFORMANCE ROWS */

        /* FORMAT PERFORMANCE */
        $labels = [];
        $sales = [];
        $revenue = [];

        for($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $dateKey = $date->toDateString();
            $row = $rows->get($dateKey);

            $labels[] = $date->translatedFormat('d M');
            $sales[] = (int) ($row->sales ?? 0);
            $revenue[] = (float) ($row->revenue ?? 0);
        }
        /* FORMAT PERFORMANCE */

        /* RESPONSE */
        return [
            'period' => '30_days',
            'labels' => $labels,
            'sales' => $sales,
            'revenue' => $revenue,
            'total_sold' => array_sum($sales),
            'total_revenue' => array_sum($revenue),
        ];
        /* RESPONSE */
    }

    /**
     * Mengambil transaksi terbaru seller untuk ditampilkan di dashboard.
     */
    private function getRecentTransactions(string $user_id): array
    {
        /* GET RECENT TRANSACTIONS */
        return TransactionUser::query()
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
                /* GET PRODUCT NAMES */
                $products = TransactionProduct::query()
                    ->join('products', 'products.id', '=', 'transaction_products.product_id')
                    ->where('transaction_products.transaction_user_id', $transaction->id)
                    ->orderBy('products.name')
                    ->pluck('products.name');
                /* GET PRODUCT NAMES */

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
        /* GET RECENT TRANSACTIONS */
    }

    /**
     * Mengambil ringkasan kondisi produk seller berdasarkan stok dan tanggal pembuatan.
     */
    private function getProductSnapshot(string $user_id): array
    {
        /* GET DATE RANGE */
        $newProductStart = Carbon::now('Asia/Jakarta')->subDays(30)->startOfDay()->timezone('UTC');
        /* GET DATE RANGE */

        /* GET PRODUCT SNAPSHOT */
        return [
            'active_products' => Product::where('user_id_seller', $user_id)->where('stock', '>', 0)->count(),
            'low_stock_products' => Product::where('user_id_seller', $user_id)->whereBetween('stock', [1, 5])->count(),
            'empty_stock_products' => Product::where('user_id_seller', $user_id)->where('stock', '<=', 0)->count(),
            'new_products' => Product::where('user_id_seller', $user_id)->where('created_at', '>=', $newProductStart)->count(),
        ];
        /* GET PRODUCT SNAPSHOT */
    }

    /**
     * Membuat base query transaksi seller yang sudah selesai dan sudah dibayar.
     */
    private function doneTransactionQuery(string $user_id)
    {
        return TransactionUser::query()
            ->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
            ->where('transaction_users.user_id_seller', $user_id)
            ->where('transaction_users.status', 'done')
            ->where('transaction_invoices.status', 'done');
    }

    /**
     * Membuat base query pesanan baru yang sudah dibayar dan masih perlu diproses seller.
     */
    private function newOrderQuery(string $user_id)
    {
        return TransactionUser::query()
            ->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
            ->where('transaction_users.user_id_seller', $user_id)
            ->where('transaction_users.status', 'approved_seller')
            ->where('transaction_invoices.status', 'done');
    }
}
