<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
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
                                    COUNT(keranjangs.product_id) as k_total,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    SUM(products.price) as p_price_total,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->groupBy('keranjangs.product_id')
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->p_price_total; 
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

        Keranjang::create($validate);

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
                               ->get();
 
        foreach($keranjangs as $keranjang)
        {
            $keranjang->delete();
        }
        /* DELETE KERANJANG */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    COUNT(keranjangs.product_id) as k_total,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    SUM(products.price) as p_price_total,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->groupBy('keranjangs.product_id')
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */

        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->p_price_total; 
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
        $keranjangs = Keranjang::where('product_id', $validate['product_id'])
                               ->where('user_id_buyer', $validate['user_id_buyer'])
                               ->get();
                    
        foreach($keranjangs as $keranjang)
        {
            $keranjang->checked = ($validate['checked']) ? true : false;
            $keranjang->save();
        }
        /* CHANGE CHECKED */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    COUNT(keranjangs.product_id) as k_total,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    SUM(products.price) as p_price_total,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->groupBy('keranjangs.product_id')
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */

        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->p_price_total; 
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function plusKeranjang(Request $request)
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

        /* GET FIRST KERANJANG */
        $keranjang = Keranjang::select('user_id_seller', 'user_id_buyer', 'product_id', 'checked')
                              ->where('user_id_buyer', $validate['user_id_buyer'])
                              ->where('product_id', $validate['product_id'])
                              ->first();
        /* GET FIRST KERANJANG */

        /* DUPLICATE KERANJANG */
        Keranjang::create([
            'user_id_seller' => $keranjang->user_id_seller,
            'user_id_buyer' => $keranjang->user_id_buyer,
            'product_id' => $keranjang->product_id,
            'checked' => $keranjang->checked,
        ]);
        /* DUPLICATE KERANJANG */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    COUNT(keranjangs.product_id) as k_total,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    SUM(products.price) as p_price_total,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->groupBy('keranjangs.product_id')
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */

        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->p_price_total; 
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function minusKeranjang(Request $request)
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

        /* GET FIRST KERANJANG AND DELETE */
        $keranjang = Keranjang::where('user_id_buyer', $validate['user_id_buyer'])
                              ->where('product_id', $validate['product_id'])
                              ->orderBy('id', 'DESC')
                              ->first()
                              ->delete();
        /* GET FIRST KERANJANG AND DELETE */

        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.checked as k_checked,
                                    COUNT(keranjangs.product_id) as k_total,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    SUM(products.price) as p_price_total,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $validate['user_id_buyer'])
                                ->groupBy('keranjangs.product_id')
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */

        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->p_price_total; 
            }
        }
        /* CALCULATION PRICE */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }
}
