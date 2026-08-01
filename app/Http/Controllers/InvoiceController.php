<?php

namespace App\Http\Controllers;

use App\Models\TransactionInvoice;
use App\Models\TransactionProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    /**
     * Menampilkan detail invoice yang dapat diakses pengguna.
     *
     * Parameter invoice dan identitas requester divalidasi sebelum data transaksi dimuat. Akses
     * dibatasi kepada buyer atau seller yang terlibat, lalu detail produk dan pihak transaksi disusun
     * menjadi response invoice.
     *
     * @param  Request  $request  Identitas dan parameter invoice.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function show(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi input
        $Validator = Validator::make($request->all(), [
            'user_id_buyer' => ['required', 'uuid'],
        ]);

        if ($Validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $Validator->messages()], 422);
        }
        // --- step 1 - end - validasi input

        // --- step 2 - start - ambil dan format daftar invoice
        $invoices = TransactionInvoice::where('user_id_buyer', $request->user_id_buyer);

        $status = match ($request->filter) {
            'done' => 'settlement',
            'pending' => 'pending',
            'expired' => 'expire',
            default => null,
        };

        if ($status !== null) {
            $invoices->where('transaction_status', $status);
        }

        $invoices->orderBy(
            'created_at',
            $request->filter === 'oldest' ? 'ASC' : 'DESC',
        );

        $invoices = $invoices->get();

        $invoiceFormat = [];
        foreach ($invoices as $transactionInvoice) {
            $transactionProducts = TransactionProduct::select('users.name as u_name', 'products.name as p_name', 'products.price as p_price', 'transactions.total as t_total', 'products.img as p_img')
                ->join('users', 'users.id', '=', 'transactions.user_id_seller')
                ->join('products', 'products.id', '=', 'transactions.product_id')
                ->where('order_id', $transactionInvoice->order_id)
                ->get();

            // --- step 3 - start - format tipe payment
            $payment_type = '';
            if (! empty($transactionInvoice->va_number) && ! empty($transactionInvoice->va_bank)) {
                $payment_type = 'Virtual Account '.strtoupper($transactionInvoice->va_bank);
            }
            // --- step 3 - end - format tipe payment

            // --- step 4 - start - format status transaksi
            $transactionStatus = $transactionInvoice->transaction_status;
            if ($transactionInvoice->transaction_status == 'settlement') {
                $transactionStatus = 'done';
            }
            // --- step 4 - end - format status transaksi

            $invoiceFormat[] = [
                'payment_type' => $payment_type,
                'transaction_time' => $transactionInvoice->transaction_time,
                'transaction_status' => $transactionStatus,
                'expiry_time' => $transactionInvoice->expiry_time,
                'va_biller_code' => $transactionInvoice->va_biller_code,
                'va_number' => $transactionInvoice->va_number,
                'gross_amount' => $transactionInvoice->gross_amount,
                'transactions_products' => $transactionProducts,
            ];
        }
        // --- step 2 - end - ambil dan format daftar invoice

        return response()->json(['status' => 'success', 'invoices' => $invoiceFormat]);
    }
}
