<?php

namespace App\Http\Controllers;

use App\Models\TransactionInvoice;
use App\Models\TransactionProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    public function show(Request $request)
    {
        /* VALIDATOR */
        $Validator = Validator::make($request->all(), [
            'user_id_buyer' => ['required']
        ]);

        if($Validator->fails())
        {
            return response()->json(['status' => 'error', 'message' => $Validator->messages()], 422);
        }
        /* VALIDATOR */

        /* GET INVOICE AND CUSTOM FORMAT */
        $invoices = TransactionInvoice::where('user_id_buyer', $request->user_id_buyer);
        
        if($request->filter == '' || $request->filter == null) 
        {
            // NOT FILTER
            $invoices->orderBy('created_at', 'DESC');
        } 
        else if($request->filter == 'done') 
        {
            // FILTER DONE
            $invoices->where('transaction_status', 'settlement');
            $invoices->orderBy('created_at', 'DESC');
        }
        else if($request->filter == 'pending') 
        {
            // FILTER PENDING
            $invoices->where('transaction_status', 'pending');
            $invoices->orderBy('created_at', 'DESC');
        }
        else if($request->filter == 'expired') 
        {
            // FILTER EXPIRED
            $invoices->where('transaction_status', 'expire');
            $invoices->orderBy('created_at', 'DESC');
        }
        else if($request->filter == 'latest') 
        {
            // FILTER LATEST
            $invoices->orderBy('created_at', 'DESC');
        }
        else if($request->filter == 'oldest') 
        {
            // FILTER OLDEST
            $invoices->orderBy('created_at', 'ASC');
        }

        $invoices = $invoices->get();
                           
        $invoiceFormat = [];
        foreach($invoices as $transactionInvoice)
        {
            $transactionProducts = TransactionProduct::select('users.name as u_name', 'products.name as p_name', 'products.price as p_price', 'transactions.total as t_total', 'products.img as p_img')
                                       ->join('users', 'users.id', '=', 'transactions.user_id_seller')
                                       ->join('products', 'products.id', '=', 'transactions.product_id')
                                       ->where('order_id', $transactionInvoice->order_id)
                                       ->get();

            /* FORMAT PAYMENT TYPE */
            $payment_type = "";
            if(!empty($transactionInvoice->va_number) && !empty($transactionInvoice->va_bank))
            {
                $payment_type = "Virtual Account " . strtoupper($transactionInvoice->va_bank);
            }
            /* FORMAT PAYMENT TYPE */

            /* FORMAT TRANSACTION STATUS */
            $transactionStatus = $transactionInvoice->transaction_status;
            if($transactionInvoice->transaction_status == 'settlement') 
            {
                $transactionStatus = "done";
            }
            /* FORMAT TRANSACTION STATUS */

            $invoiceFormat[] = [
                'payment_type' => $payment_type,
                'transaction_time' => $transactionInvoice->transaction_time,
                'transaction_status' => $transactionStatus,
                'expiry_time' => $transactionInvoice->expiry_time,
                'va_biller_code' => $transactionInvoice->va_biller_code,
                'va_number' => $transactionInvoice->va_number,
                'gross_amount' => $transactionInvoice->gross_amount,
                'transactions_products' => $transactionProducts
            ];
        }
        /* GET INVOICE AND CUSTOM FORMAT */

        return response()->json(['status' => 'success', 'invoices' => $invoiceFormat]);
    }
}
