<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BelanjaController extends Controller
{
    public function index(string $user_id_seller, Request $request)
    {  
        /* VALIDATOR AND GET */
        $validator = Validator::make(
            [
                'user_id_seller' => $user_id_seller,
                'products_current_id' => $request->products_current_id
            ],
            [
                'user_id_seller' => ['required', 'integer'],
                'products_current_id' => ['required']
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */
        
        /* GET PRODUCT EXCEPT MY PRODUCT */
        $products_current_id = json_decode($request->products_current_id, true);
        $search_product = (isset($request->search_product)) ? trim($request->search_product) : '';

        $products = Product::select(
                                'products.id as p_id', 
                                'products.img as p_img', 
                                'products.name as p_name', 
                                'products.price as p_price', 
                                'products.stock as p_stock', 
                                'users.id as u_id',
                                'users.name as u_name'
                            )
                           ->join('users', 'products.user_id_seller', '=', 'users.id')
                           ->where('products.user_id_seller', '<>', $user_id_seller)
                           ->whereNotIn('products.id', $products_current_id)
                           ->where(function ($query) use ($search_product) {
                                $query->where('products.name', 'LIKE', "%$search_product%")
                                      ->orWhere('users.name', 'LIKE', "%$search_product%");
                           })
                           ->orderBy('products.updated_at', 'DESC')
                           ->limit(200)
                           ->get();
        /* GET PRODUCT EXCEPT MY PRODUCT */

        return response()->json(['status' => 200, 'products' => $products], 200);
    }
}
