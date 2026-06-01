<?php 

namespace App\Services;

use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * user_type disini itu maksudnya ingin mengambil history transaksi namun user tersebut sedang login di user type apa
     */
    public function getTransaction(string $user_id, string $user_type, array $filters = [])
    {
        /* VALIDATION */
        if(empty($user_id) || trim($user_id) == '')
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        if(empty($user_type) || !in_array($user_type, ['seller','buyer']))
            return ['status' => 'error', 'message' => 'user type cannot be empty and must be seller or buyer'];
        /* VALIDATION */

        /* GET TRANSACTIONS USER */
        $perPage = min(max((int) ($filters['per_page'] ?? 5), 1), 20);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $sortOrder = ($filters['sort'] ?? 'newest') == 'oldest' ? 'asc' : 'desc';

        $baseTransactions = TransactionUser::query()
                                           ->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
                                           ->join('users as buyer_users', 'buyer_users.id', '=', 'transaction_users.user_id_buyer')
                                           ->join('users as seller_users', 'seller_users.id', '=', 'transaction_users.user_id_seller')
                                           ->leftJoin('companies as seller_companies', 'seller_companies.user_id', '=', 'transaction_users.user_id_seller');

        $this->applyUserTypeFilter($baseTransactions, $user_id, $user_type);
        $this->applySearchFilter($baseTransactions, $filters['search'] ?? '');
        $this->applyDateFilter($baseTransactions, $filters['date_from'] ?? '', $filters['date_to'] ?? '');

        $counts = [
            'all' => (clone $baseTransactions)->count('transaction_users.id'),
            'paid' => $this->applyStatusFilter(clone $baseTransactions, 'paid')->count('transaction_users.id'),
            'pending_payment' => $this->applyStatusFilter(clone $baseTransactions, 'pending_payment')->count('transaction_users.id'),
            'waiting_seller' => $this->applyStatusFilter(clone $baseTransactions, 'waiting_seller')->count('transaction_users.id'),
            'done' => $this->applyStatusFilter(clone $baseTransactions, 'done')->count('transaction_users.id'),
        ];

        $transactions = (clone $baseTransactions)->select(
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
                                                    'transaction_invoices.price as total_price',
                                                    'transaction_invoices.expired_at'
                                                );

        if($user_type == 'buyer')
            $transactions->addSelect('transaction_invoices.payment_account');

        $transactions = $this->applyStatusFilter($transactions, $filters['status'] ?? 'all')
                             ->orderBy('transaction_users.created_at', $sortOrder)
                             ->paginate($perPage, ['*'], 'page', $page);

        $transactions->setCollection($transactions->getCollection()->map(function ($item) {
            $item->transaction_date = Carbon::parse($item->transaction_date)
                                            ->setTimezone('Asia/Jakarta')
                                            ->translatedFormat('d F Y H:i');
            $item->expired_at = Carbon::parse($item->expired_at)
                                      ->setTimezone('Asia/Jakarta')
                                      ->translatedFormat('d F Y H:i');
            $products = TransactionProduct::select(
                                        'products.name',
                                        'products.img',
                                        'transaction_products.price',
                                        'transaction_products.total'
                                        )
                                        ->join('products', 'products.id', '=', 'transaction_products.product_id')
                                        ->where('transaction_products.transaction_user_id', $item->id)
                                        ->get();
            $item->products = $products;
            return $item;
        }));
        /* GET TRANSACTIONS USER */

        return [
            'status' => 'success',
            'transactions' => $transactions->getCollection(),
            'counts' => $counts,
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'from' => $transactions->firstItem(),
                'to' => $transactions->lastItem(),
            ]
        ];
    }

    private function applyUserTypeFilter($query, string $user_id, string $user_type)
    {
        if($user_type == 'seller')
            return $query->where('transaction_users.user_id_seller', $user_id);

        return $query->where('transaction_users.user_id_buyer', $user_id);
    }

    private function applySearchFilter($query, string $search)
    {
        $search = trim($search);
        if(empty($search))
            return $query;

        return $query->where(function ($query) use ($search) {
            $query->whereRaw('transaction_invoices.id::text like ?', ["%{$search}%"])
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

    private function applyDateFilter($query, string $date_from, string $date_to)
    {
        if($this->isValidDate($date_from))
            $query->whereDate('transaction_users.created_at', '>=', $date_from);

        if($this->isValidDate($date_to))
            $query->whereDate('transaction_users.created_at', '<=', $date_to);

        return $query;
    }

    private function isValidDate(string $date)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) == 1;
    }

    private function applyStatusFilter($query, string $status)
    {
        if($status == 'pending_payment')
            return $query->where('transaction_invoices.status', 'pending');

        if($status == 'paid')
            return $query->where('transaction_invoices.status', 'done');

        if($status == 'waiting_seller')
            return $query->where('transaction_invoices.status', 'done')
                         ->where('transaction_users.status', 'approved_seller');

        if($status == 'done')
            return $query->where('transaction_invoices.status', 'done')
                         ->where('transaction_users.status', 'done');

        return $query;
    }

    public function transferSaldo(string $user_id = "", string $transaction_user_id = "", float $price = 0, string $type = "")
    {
        // info(__FUNCTION__, ['param' => get_defined_vars()]);
        /* VARIABLE */
        $saldo_before = 0;
        $saldo_after = 0;
        /* VARIABLE */

        /* SALDO USER */
        $saldoUser = SaldoUser::where('user_id', $user_id)
                              ->first();
        if(empty($saldoUser))
        {
            $saldo_after = $price;
            SaldoUser::create([
                'user_id' => $user_id,
                'balance' => $price,
                'saldo_income' => $saldo_after,
                'saldo_refund' => 0,
            ]);
        }
        else 
        {
            $saldo_before = (int) $saldoUser->saldo_income + (int) $saldoUser->saldo_refund;

            $saldoUser->saldo_income += (int) $price;
            $saldoUser->save();

            $saldo_after = (int) $saldoUser->saldo_income + (int) $saldoUser->saldo_refund;
        }
        /* SALDO USER */

        /* SALDO HISTORY */
        SaldoHistory::create([
            'user_id' => $user_id,
            'transaction_user_id' => $transaction_user_id,
            'payment_user_id' => null,
            'type' => $type,
            'price' => $price,
            'saldo_before' => $saldo_before,
            'saldo_after' => $saldo_after
        ]);
        /* SALDO HISTORY */
    }
}
