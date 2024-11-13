<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Product;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        /* CHECK USER HAS BEN CONNECTED IN STRIPE */
        $data_request = new Request(['user_id_seller' => $user_id_seller]);
        $checkConnected = $paymentController->checkConnectAccountStripe($data_request)->getData();

        if($checkConnected->result == 'failed' || $checkConnected->result == 'warning')
        {
            return response()->json(['status' => 402 , 'message' => $checkConnected->message], 402);
        }
        else if(($checkConnected->result == 'success') && (count($checkConnected->account->requirements->currently_due) > 0 || count($checkConnected->account->requirements->past_due) > 0))
        {
            return response()->json(['status' => 402 , 'message' => 'Please Continue Connect Your Account'], 402);
        }
        /* CHECK USER HAS BEN CONNECTED IN STRIPE */

        /* GET PRODUCT */
        $products_current_id = json_decode($request->products_current_id, true);

        $products = Product::where('user_id_seller', $validate['user_id_seller'])
                           ->whereNotIn('id', $products_current_id)
                           ->orderBy('updated_at', 'DESC')
                           ->limit(50)
                           ->get();
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
                'user_id_seller' => ['required', 'integer'],
                'id' => ['required', 'integer'],
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
            'user_id_seller' => ['required', 'integer'],
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
            'id' => ['required', 'integer'],
            'oldImg' => ['required'],
            'name' => ['required', 'min:3'],
            'price' => ['required', 'integer', 'min:1'],
            'stock' => ['required', 'integer', 'min:1']
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
                'user_id_seller' => ['required', 'integer'],
                'id' => ['required', 'integer'],
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
