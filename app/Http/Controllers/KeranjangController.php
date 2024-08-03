<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class KeranjangController extends Controller
{
    public function index(string $user_id_buyer)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make(
            [
                'user_id_buyer' => $user_id_buyer
            ],
            [
                'user_id_buyer' => ['required', 'integer'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */
        
        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function store(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(),
            [
                'user_id_seller' => ['required', 'integer'],
                'user_id_buyer' => ['required', 'integer'],
                'product_id' => ['required', 'integer'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        $validate['checked'] = 0;
        $validate['total'] = 1;

        /* GET KERANJANG */
        $keranjang = Keranjang::where('user_id_seller', $validate['user_id_seller'])
                              ->where('user_id_buyer', $validate['user_id_buyer'])
                              ->where('product_id', $validate['product_id'])
                              ->first();
        /* GET KERANJANG */

        /* WHEN KERANJANG ALREADY EXISTS */
        if(!empty($keranjang)) 
        {
            $product = Product::select('stock')
                              ->where('id', $keranjang->product_id)
                              ->first();

            /* VALIDATES IF TOTAL KERANJANG >= STOCK PRODUCT */
            if($keranjang->total >= $product->stock) {
                return response()->json(['status' => 422, 'message' => ['stock_maximum' => ["This product stock is a maximum of {$product->stock}"]]], 422);
            }  
            /* VALIDATES IF TOTAL KERANJANG >= STOCK PRODUCT */

            $keranjang->total += 1;
            $keranjang->save();
        }
        /* WHEN KERANJANG ALREADY EXISTS */

        /* WHEN KERANJANG NOT ALREADY EXISTS */
        else 
        {
            Keranjang::create($validate);
        }
        /* WHEN KERANJANG NOT ALREADY EXISTS */


        return response()->json(['status' => 200, 'message' => 'Item Has Been Added To Basket'], 200);
    }

    public function delete(string $user_id_buyer, string $product_id)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make(
            [
                'user_id_buyer' => $user_id_buyer,
                'product_id' => $product_id,
            ],
            [
                'user_id_buyer' => ['required', 'integer'],
                'product_id' => ['required', 'integer'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* DELETE KERANJANG */
        $keranjangs = Keranjang::where('user_id_buyer', $validate['user_id_buyer'])
                               ->where('product_id', $validate['product_id'])
                               ->delete();
        /* DELETE KERANJANG */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'message' => 'Item In Basket Has Been Delete', 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function checked(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'integer'],
                'product_id' => ['required', 'integer'],
                'checked' => ['required', 'boolean']
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* CHANGE CHECKED */
        $keranjang = Keranjang::where('product_id', $validate['product_id'])
                              ->where('user_id_buyer', $validate['user_id_buyer'])
                              ->first();
        $keranjang->checked = ($validate['checked']) ? true : false;
        $keranjang->save();
        /* CHANGE CHECKED */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function plusTotalKeranjang(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'integer'],
                'product_id' => ['required', 'integer'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        $keranjang = Keranjang::select('id', 'product_id', 'user_id_seller', 'user_id_buyer', 'product_id', 'checked', 'total')
                              ->where('user_id_buyer', $validate['user_id_buyer'])
                              ->where('product_id', $validate['product_id'])
                              ->first();

        $product = Product::select('stock')
                          ->where('id', $keranjang->product_id)
                          ->first();

        /* VALIDATES IF TOTAL KERANJANG >= STOCK PRODUCT */
        if($keranjang->total >= $product->stock) {
            return response()->json(['status' => 422, 'message' => ['stock_maximum' => ["This product stock is a maximum of {$product->stock}"]]], 422);
        }  
        /* VALIDATES IF TOTAL KERANJANG >= STOCK PRODUCT */

        /* ADD ONE TOTAL KERANJANG */
        $keranjang->total += 1;
        $keranjang->save();
        /* ADD ONE TOTAL KERANJANG */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function minusTotalKeranjang(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'integer'],
                'product_id' => ['required', 'integer'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* ADD ONE TOTAL KERANJANG */
        $keranjang = Keranjang::select('id', 'user_id_seller', 'user_id_buyer', 'product_id', 'checked', 'total')
                              ->where('user_id_buyer', $validate['user_id_buyer'])
                              ->where('product_id', $validate['product_id'])
                              ->first();

        $keranjang->total -= 1;
        $keranjang->save();
        /* ADD ONE TOTAL KERANJANG */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function changeTotalKeranjang(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'integer'],
                'product_id' => ['required', 'integer'],
                'total' => ['required', 'integer'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        $keranjang = Keranjang::select('id', 'product_id', 'user_id_seller', 'user_id_buyer', 'product_id', 'checked', 'total')
                              ->where('user_id_buyer', $validate['user_id_buyer'])
                              ->where('product_id', $validate['product_id'])
                              ->first();

        $product = Product::select('stock')
                          ->where('id', $keranjang->product_id)
                          ->first();

        /* VALIDATES IF TOTAL KERANJANG >= STOCK PRODUCT */
        if($validate['total'] >= $product->stock) {
            /* GET ITEM IN BASKET */
                $keranjangs = Keranjang::selectRaw('
                                            keranjangs.id as k_id,
                                            keranjangs.checked as k_checked,
                                            keranjangs.total as k_total,
                                            (keranjangs.total * products.price) as k_total_price,
                                            users.name as u_seller_name,
                                            products.id as p_id,
                                            products.name as p_name,
                                            products.price as p_price,
                                            products.stock as p_stock,
                                            products.img as p_img
                                        ')
                                        ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                        ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                        ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                        ->orderBy('k_id', 'DESC')
                                        ->get();
                                    /* GET ITEM IN BASKET */

            /* CALCULATION PRICE */
            $totalPrice = 0;
            foreach($keranjangs as $keranjang)
            {
                if($keranjang->k_checked == 1) {
                    $totalPrice += $keranjang->k_total_price;
                }
            }
            /* CALCULATION PRICE */

            return response()->json(['status' => 422, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => ['stock_maximum' => ["This product stock is a maximum of {$product->stock}"]]], 422);
        }  
        /* VALIDATES IF TOTAL KERANJANG >= STOCK PRODUCT */

        /* CHANGE TOTAL KERANJANG */
        $keranjang->total = $validate['total'];
        $keranjang->save();
        /* CHANGE TOTAL KERANJANG */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }
}
