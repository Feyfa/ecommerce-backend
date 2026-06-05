<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index(string $user_id_seller, Request $request, PaymentController $paymentController)
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
                'stock_filter' => ['nullable', Rule::in(['all', 'available', 'low', 'empty'])],
                'sort_product' => ['nullable', Rule::in(['latest', 'oldest', 'price_highest', 'price_lowest', 'stock_highest', 'stock_lowest', 'name_asc', 'name_desc'])],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* GET PRODUCT */
        $products_current_id = json_decode($request->products_current_id, true);
        $search_product = (isset($request->search_product)) ? trim($request->search_product) : '';
        $stock_filter = $request->stock_filter ?? 'all';
        $sort_product = $request->sort_product ?? 'latest';

        $products = Product::where('user_id_seller', $validate['user_id_seller'])
                           ->whereNotIn('id', $products_current_id)
                           ->where('name', 'ILIKE', "%$search_product%");

        /* FILTER PRODUCT BY STOCK CONDITION */
        if($stock_filter == 'available') {
            $products->where('stock', '>', 0);
        } else if($stock_filter == 'low') {
            $products->whereBetween('stock', [1, 5]);
        } else if($stock_filter == 'empty') {
            $products->where('stock', '<=', 0);
        }
        /* FILTER PRODUCT BY STOCK CONDITION */

        /* SORT PRODUCT BY SELECTED OPTION */
        if($sort_product == 'oldest') {
            $products->orderBy('updated_at', 'ASC');
        } else if($sort_product == 'price_highest') {
            $products->orderBy('price', 'DESC');
        } else if($sort_product == 'price_lowest') {
            $products->orderBy('price', 'ASC');
        } else if($sort_product == 'stock_highest') {
            $products->orderBy('stock', 'DESC');
        } else if($sort_product == 'stock_lowest') {
            $products->orderBy('stock', 'ASC');
        } else if($sort_product == 'name_asc') {
            $products->orderBy('name', 'ASC');
        } else if($sort_product == 'name_desc') {
            $products->orderBy('name', 'DESC');
        } else {
            $products->orderBy('updated_at', 'DESC');
        }
        /* SORT PRODUCT BY SELECTED OPTION */

        $products = $products->limit(50)->get();
        /* GET PRODUCT */

        return response()->json(['status' => 200, 'products' => $products], 200);
    }

    public function show(string $user_id_seller, string $id)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make(
            [
                'user_id_seller' => $user_id_seller,
                'id' => $id
            ],
            [
                'user_id_seller' => ['required', 'uuid'],
                'id' => ['required', 'uuid'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 402, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* GET ONE PRODUCT */
        $product = Product::where('user_id_seller', $validate['user_id_seller'])
                           ->where('id', $validate['id'])
                           ->first();
        /* GET ONE PRODUCT */
        
        return response()->json(['status' => 200, 'product' => $product]);
    }

    public function store(Request $request)
    {
        /* VALIDATE AND GET */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required', 'uuid'],
            'img' => ['image', 'file', 'max:1024', 'required'],
            'name' => ['required', 'min:3'],
            'price' => ['required', 'integer', 'min:1'],
            'stock' => ['required', 'integer', 'min:1']
        ]);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATE AND GET */

        /* CREATE PRODUCT AND STORE IMG */
        $validate['img'] = $request->file('img')->store('product-imgs');

        $product = Product::create($validate);
        /* CREATE PRODUCT AND STORE IMG */

        return response()->json(['status' => 200, 'message' => 'Add Product Success', 'product' => $product], 200);
    }

    public function update(string $id, Request $request)
    {
        /* VALIDATOR AND GET */
        $property = [
            'id' => $id,
            'oldImg' => $request->oldImg,
            'name' => $request->name,
            'price' => $request->price,
            'stock' => $request->stock
        ];
        $rule = [
            'id' => ['required', 'uuid'],
            'oldImg' => ['required'],
            'name' => ['required', 'min:3'],
            'price' => ['required', 'integer', 'min:1'],
            'stock' => ['required', 'integer']
        ];

        if($request->file('img')) {
            // Tambahkan img ke properti untuk validasi
            $property['img'] = $request->file('img');

            // Tambahkan aturan validasi untuk img
            $rule['img'] = ['image', 'file', 'max:1024', 'required'];
        }

        $validator = Validator::make($property, $rule);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* UPDATE PRODUCT */
        $product = Product::where('id', $validate['id'])
                          ->first();
        $product->name = $validate['name'];
        $product->price = $validate['price'];
        $product->stock = $validate['stock'];
        $product->save();
        /* UPDATE PRODUCT */

        /* DELETE IMG PROVIOUS AND ADD IMG */
        if($request->file('img'))
        {
            if($validate['oldImg'])
            {
                Storage::delete($validate['oldImg']);
                
                $validate['img'] = $request->file('img')->store('product-imgs');
    
                $product->img = $validate['img'];
                $product->save();
            }

        }
        /* DELETE IMG PROVIOUS AND ADD IMG */

        return response()->json(['status' => 200, 'message' => 'Update Product Success', 'product' => $product]);
    }

    public function delete(string $user_id_seller, string $id)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make(
            [
                'user_id_seller' => $user_id_seller,
                'id' => $id
            ],
            [
                'user_id_seller' => ['required', 'uuid'],
                'id' => ['required', 'uuid'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 402, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* DELETE */
        Keranjang::where('product_id', $validate['id'])
                 ->delete();

        $product = Product::where('id', $validate['id'])
                          ->first();

        Storage::delete($product->img);

        $product->delete();
        /* DELETE */

        return response()->json(['status' => 200, 'message' => 'Delete Product Success'], 200);
    }
}
