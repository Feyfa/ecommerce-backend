<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class BelanjaController extends Controller
{
    public function index(string $user_id_seller, Request $request)
    {  
        /* VALIDATOR AND GET */
        $validator = Validator::make(
            [
                'user_id_seller' => $user_id_seller,
                'products_current_id' => $request->products_current_id,
                'stock_filter' => $request->stock_filter,
                'sort_product' => $request->sort_product,
            ],
            [
                'user_id_seller' => ['required', 'uuid'],
                'products_current_id' => ['required'],
                'stock_filter' => ['nullable', Rule::in(['all', 'available', 'empty'])],
                'sort_product' => ['nullable', Rule::in(['latest', 'price_highest', 'price_lowest', 'name_asc', 'name_desc'])],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */
        
        /* GET PRODUCT EXCEPT MY PRODUCT */
        $products_current_id = json_decode($request->products_current_id, true);
        $search_product = (isset($request->search_product)) ? trim($request->search_product) : '';
        $stock_filter = $request->stock_filter ?? 'all';
        $sort_product = $request->sort_product ?? 'latest';

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
                                $query->where('products.name', 'ILIKE', "%$search_product%")
                                      ->orWhere('users.name', 'ILIKE', "%$search_product%");
                           });

        /* FILTER PRODUCT BY BUYER STOCK CONDITION */
        if($stock_filter == 'available') {
            $products->where('products.stock', '>', 0);
        } else if($stock_filter == 'empty') {
            $products->where('products.stock', '<=', 0);
        }
        /* FILTER PRODUCT BY BUYER STOCK CONDITION */

        /* SORT PRODUCT BY BUYER SELECTED OPTION */
        if($sort_product == 'price_highest') {
            $products->orderBy('products.price', 'DESC');
        } else if($sort_product == 'price_lowest') {
            $products->orderBy('products.price', 'ASC');
        } else if($sort_product == 'name_asc') {
            $products->orderBy('products.name', 'ASC');
        } else if($sort_product == 'name_desc') {
            $products->orderBy('products.name', 'DESC');
        } else {
            $products->orderBy('products.updated_at', 'DESC');
        }
        /* SORT PRODUCT BY BUYER SELECTED OPTION */

        $products = $products->limit(200)->get();
        /* GET PRODUCT EXCEPT MY PRODUCT */

        return response()->json(['status' => 200, 'products' => $products], 200);
    }
}
