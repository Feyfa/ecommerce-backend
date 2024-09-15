<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function deleteTransaction(string $user_id_buyer, string $order_id)
    {
        /* DELETE ALL TRANSACTION BASED ON ORDER_ID, USER_ID_BUYER */
        Transaction::where('user_id_buyer', $user_id_buyer)
                   ->where('order_id', $order_id)
                   ->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Transaction Cancelled'
        ]);
        /* DELETE ALL TRANSACTION BASED ON ORDER_ID, USER_ID_BUYER */
    }
}
