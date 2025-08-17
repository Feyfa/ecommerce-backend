<?php 

namespace App\Services;

use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use Carbon\Carbon;

class TransactionService
{
    /**
     * user_type disini itu maksudnya ingin mengambil history transaksi namun user tersebut sedang login di user type apa
     */
    public function getTransaction(string $user_id, string $user_type)
    {
        $transactions = [];

        /* VALIDATION */
        if(empty($user_id) || trim($user_id) == '')
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        if(empty($user_type) || !in_array($user_type, ['seller','buyer']))
            return ['status' => 'error', 'message' => 'user type cannot be empty and must be seller or buyer'];
        /* VALIDATION */

        /* GET TRANSACTIONS USER */
        $transactions = TransactionUser::select(
                                            'transaction_users.id',
                                            'transaction_invoices.status as invoice_status',
                                            'transaction_users.status as transaction_status',
                                            'transaction_users.transaction_number',
                                            'users.name as buyer_name',
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

        $transactions = $transactions->join('transaction_invoices', 'transaction_invoices.id', '=', 'transaction_users.transaction_invoice_id')
                                    ->join('users', 'users.id', '=', 'transaction_users.user_id_buyer');

        if($user_type == 'seller')
            $transactions->where('transaction_users.user_id_seller', $user_id);
        else
            $transactions->where('transaction_users.user_id_buyer', $user_id);

        $transactions = $transactions->orderBy('transaction_users.created_at', 'desc')
                                     ->get()
                                     ->map(function ($item) {
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
                                     });
        /* GET TRANSACTIONS USER */

        return ['status' => 'success', 'transactions' => $transactions];
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