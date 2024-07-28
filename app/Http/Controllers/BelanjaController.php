<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class BelanjaController extends Controller
{
    public function index(string $user_id_seller)
    {  
        /* GET PRODUCT EXCEPT MY PRODUCT */
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
                           ->get();
        /* GET PRODUCT EXCEPT MY PRODUCT */

        return response()->json(['status' => 200, 'products' => $products], 200);
    }
}
