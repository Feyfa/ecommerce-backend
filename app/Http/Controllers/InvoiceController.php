<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Keranjang;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $invoices = Invoice::where('user_id_buyer', $request->user_id_buyer)
                           ->get();
                           
        $invoiceFormat = [];
        foreach($invoices as $invoice)
        {
            $transactions = Transaction::select('users.name as u_name', 'products.name as p_name', 'products.price as p_price', 'transactions.total as t_total', 'products.img as p_img')
                                       ->join('users', 'users.id', '=', 'transactions.user_id_seller')
                                       ->join('products', 'products.id', '=', 'transactions.product_id')
                                       ->where('order_id', $invoice->order_id)
                                       ->get();

            // Log::info([
            //     'transactions' => $transactions->toArray()
            // ]);

            /* FORMAT PAYMENT TYPE */
            $payment_type = "";
            if(!empty($invoice->va_number) && !empty($invoice->va_bank))
            {
                $payment_type = "Virtual Account " . strtoupper($invoice->va_bank);
            }
            /* FORMAT PAYMENT TYPE */

            /* FORMAT TRANSACTION STATUS */
            $transactionStatus = $invoice->transaction_status;
            if($invoice->transaction_status == 'settlement') 
            {
                $transactionStatus = "done";
            }
            /* FORMAT TRANSACTION STATUS */

            $invoiceFormat[] = [
                'payment_type' => $payment_type,
                'transaction_time' => $invoice->transaction_time,
                'transaction_status' => $transactionStatus,
                'expiry_time' => $invoice->expiry_time,
                'va_biller_code' => $invoice->va_biller_code,
                'va_number' => $invoice->va_number,
                'gross_amount' => $invoice->gross_amount,
                'transactions' => $transactions
            ];
        }
        /* GET INVOICE AND CUSTOM FORMAT */

        return response()->json([
            'invoices' => $invoiceFormat
        ]);
    }

    public function createInvoice(Request $request)
    {   
        /* VALIDATE */
        $Validator = Validator::make($request->all(), [
            'order_id' => [
                'required',
                function($attribute, $value, $fail) {
                    $transactionExists = Transaction::where('order_id', $value)
                                                    ->exists();
                    if(!$transactionExists) 
                        $fail("The $attribute does not exist");
                }
            ]
        ]);

        if($Validator->fails())
        {
            // Log::info("", ['message' => $Validator->messages()]);
            return response()->json(['status' => 'error', 'message' => $Validator->messages()], 422);
        }
        /* VALIDATE */

        if($request->transaction_status != 'deny') 
        {
            /* DELETE DATA KERANJANG YANG ADA DI TRANSACTION AND INVOICE */
            $transactions = Transaction::where('order_id', $request->order_id)
                                       ->get();

            $product_ids = $transactions->pluck('product_id')->toArray();

            Keranjang::where('user_id_buyer', $transactions[0]->user_id_buyer)
                     ->whereIn('product_id', $product_ids)
                     ->delete();
            /* DELETE DATA KERANJANG YANG ADA DI TRANSACTION AND INVOICE */

            /* IF HAS BEEN UPDATE, IF HAS NOT BEEN CREATE */
            $invoiceExists = Invoice::where('order_id', $request->order_id)
                                    ->exists();
            if($invoiceExists)
            {
                Invoice::where('order_id', $request->order_id)
                       ->update(['transaction_status' => $request->transaction_status]);
            }
            else 
            {
                /* VALIDATE FORMAT MIDTRANS */
                $va_biller_code = "";
                $va_number = "";
                $va_bank = "";

                // untuk mandiri
                if(isset($request->biller_code) && $request->biller_code == '70012')
                {
                    $va_biller_code = $request->biller_code ?? "";
                    $va_number = $request->bill_key ?? "";
                    $va_bank = "MANDIRI";
                }
                // untuk permata
                else if(isset($request->permata_va_number) && $request->permata_va_number != "") 
                {
                    $va_number = $request->permata_va_number ?? "";
                    $va_bank = "PERMATA";
                }
                // selain mandiri dan permata
                else 
                {
                    $va_number = $request->va_numbers[0]['va_number'] ?? "";
                    $va_bank = $request->va_numbers[0]['bank'] ?? "";
                }
                /* VALIDATE FORMAT MIDTRANS */

                Invoice::create([
                    'user_id_buyer' => $transactions[0]->user_id_buyer,
                    'order_id' => $request->order_id ?? "",
                    'payment_type' => $request->payment_type ?? "",
                    'gross_amount' => $request->gross_amount ?? "",
                    'currency' => $request->currency ?? "",
                    'va_biller_code' => $va_biller_code,
                    'va_number' => $va_number,
                    'va_bank' => $va_bank,
                    'transaction_status' => $request->transaction_status ?? "",
                    'transaction_time' => $request->transaction_time ?? "",
                    'settlement_time' => $request->settlement_time ?? "",
                    'expiry_time' => $request->expiry_time ?? "",
                ]);
            }
            /* IF HAS BEEN UPDATE, IF HAS NOT BEEN CREATE */
        }
        
        // Log::info(['all' => $request->all()]);
    }
}
