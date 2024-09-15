<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Midtrans\Config as MidtransConfig;
use Midtrans\Snap as MidtransSnap;

class PaymentController extends Controller
{
    public function createTokenMidtrans(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(), [
            'user_id_buyer' => ['required', 'integer'],
            'user_name_buyer' => ['required', 'string'],
        ]);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* GET KERANJANG WHEN CHEKED TRUE */
        $keranjang = Keranjang::where('user_id_buyer', $validate['user_id_buyer'])
                              ->where('checked', true)
                              ->get();
        /* GET KERANJANG WHEN CHEKED TRUE */

        /* IF KERANJANGF EMPTY */
        if(empty($keranjang))
            return response()->json(['status' => 404, 'message' => ['keranjang_checked_empty' => ["Your Basket Empty"]]], 404);
        /* IF KERANJANGF EMPTY */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    keranjangs.user_id_seller as k_user_id_seller,
                                    keranjangs.product_id as k_product_id,
                                    keranjangs.total as k_total,
                                    products.name as p_name,
                                    products.price as p_price
                                ')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->where('keranjangs.checked', true)
                                ->get();
        /* GET ITEM IN BASKET */
        
        $itemDetails = [];
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            /* CREATE ITEM DETAILS FOR MIDTRANS */
            $itemDetails[] = [
                'id' => $keranjang->k_id,
                'name' => $keranjang->p_name,
                'price' => $keranjang->p_price,
                'quantity' => $keranjang->k_total
            ];
            /* CREATE ITEM DETAILS FOR MIDTRANS */

            /* CALCULATION PRICE */
            $totalPrice += $keranjang->k_total_price;
            /* CALCULATION PRICE */
        }

        /* CREATE PARAMS FOR MIDTRANS */
        // format (user_id_buyer)_(now_epoch_time), example $order_id = 2_1725712679
        $order_id = $validate['user_id_buyer'] . "_" . Carbon::now()->timestamp;
        $params = [
            "item_details" => $itemDetails,
            'transaction_details' => [
                'order_id' => $order_id,
                'gross_amount' => $totalPrice
            ],
            'customer_details' => [
                'first_name' => $validate['user_name_buyer'],
            ]
        ];
        /* CREATE PARAMS FOR MIDTRANS */

        /* CHARGE MIDTRANS */
        try 
        {
            MidtransConfig::$serverKey = env('MIDTRANS_SERVER_KEY');
            MidtransConfig::$isProduction = env('MIDTRANS_IS_PRODUCTION');
            MidtransConfig::$isSanitized = env('MIDTRANS_IS_SANITIZED');
            MidtransConfig::$is3ds = env('MIDTRANS_IS_3DS');
            $token = MidtransSnap::getSnapToken($params);
        } 
        catch (\Exception $e) 
        {
            return response()->json(['status' => 500, 'message' => $e->getMessage()], 500);
        }
        /* CHARGE MIDTRANS */
    
        /* CREATE TRANSACTION */
        $transactions = [];
        foreach($keranjangs as $keranjang)
        {
            $transactions[] = [
                'order_id' => $order_id,
                'user_id_seller' => $keranjang->k_user_id_seller,
                'user_id_buyer' => $validate['user_id_buyer'],
                'product_id' => $keranjang->k_product_id,
                'price' => $keranjang->p_price,
                'total' => $keranjang->k_total,
            ];
        }
        Transaction::insert($transactions);
        /* CREATE TRANSACTION */

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'order_id' => $order_id,
            'user_id_buyer' => $validate['user_id_buyer']
        ]);
    }
}
