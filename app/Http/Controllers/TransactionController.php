<?php

namespace App\Http\Controllers;

use App\Models\TransactionUser;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    protected TransactionService $transactionService;

    public function __construct() 
    {
        $this->transactionService = new TransactionService();
    }

    public function getTransaction(Request $request)
    {
        /* VALIDATION USER */
        $user_type = $request->user_type ?? "";
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET TRANSACTION AS SELLER */
        $getTransaction = $this->transactionService->getTransaction($user_id, $user_type);
        $status = $getTransaction['status'] ?? '';
        $message = $getTransaction['message'] ?? '';
        $transactions = $getTransaction['transactions'] ?? [];
        if($status == 'error')
            return response()->json(['status' => $status, 'message' => $message], 400);
        /* GET TRANSACTION AS SELLER */

        return response()->json(['status' => 'success', 'transactions' => $transactions]);
    }

    public function approvedTransaction(Request $request)
    {
        // info(__FUNCTION__, ['all' => $request->all()]);
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION REQUETS */
        $user_type = $request->user_type ?? '';
        $transaction_user_id = $request->transaction_user_id ?? '';
        if($user_type != 'seller')
            return response()->json(['status' => 'error', 'message' => 'This Action Only For Seller'], 400);
        if(empty($transaction_user_id) || trim($transaction_user_id) == '')
            return response()->json(['status' => 'error', 'message' => 'Transaction User ID Cannot Be Empty'], 400);
        /* VALIDATION REQUETS */

        /* PROCESS APPROVED */
        $transactionUser = TransactionUser::where('id', $transaction_user_id)
                                          ->where('user_id_seller', $user_id)
                                          ->where('status', 'approved_seller')
                                          ->first();
        // info(__FUNCTION__, ['transaction_user_id' => $transaction_user_id, 'user_id' => $user_id]);
        if(empty($transactionUser))
            return response()->json(['status' => 'error', 'message' => 'Transaksi Tidak Ditemukan'], 404);
        
        $transactionUser->status = 'done';
        $transactionUser->save();
        /* PROCESS APPROVED */

        /* TRANSFER SALDO KE SELLER */
        $this->transactionService->transferSaldo(
            user_id: $user_id,
            transaction_user_id: $transactionUser->id,
            price: (float) $transactionUser->product_price,
            type: 'incoming'
        );
        /* TRANSFER SALDO KE SELLER */

        /* GET TRANSACTION */
        $getTransaction = $this->transactionService->getTransaction($user_id, $user_type);
        $transactions = $getTransaction['transactions'] ?? [];
        /* GET TRANSACTION */

        return response()->json(['status' => 'success', 'transactions' => $transactions]);
    }
}
