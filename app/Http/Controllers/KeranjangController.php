<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Product;
use App\Services\KeranjangService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KeranjangController extends Controller
{
    protected KeranjangService $keranjangService; 

    public function __construct(KeranjangService $keranjangService) 
    {
        $this->keranjangService = $keranjangService;
    }

    public function index(string $user_id_buyer)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make(
            [
                'user_id_buyer' => $user_id_buyer
            ],
            [
                'user_id_buyer' => ['required'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */
        
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

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

        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

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

        /* CHANGE CHECKED AND VALIDATION STOCK PRODUCT */
        $keranjang = Keranjang::where('product_id', $validate['product_id'])
                              ->where('user_id_buyer', $validate['user_id_buyer'])
                              ->first();
        $productSoldOutIds = $this->keranjangService->checkProductSoldOutByIds([$validate['product_id']]);
        
        $keranjang->checked = ($validate['checked']) && empty($productSoldOutIds['ids']) ? true : false;
        $keranjang->save();
        /* CHANGE CHECKED AND VALIDATION STOCK PRODUCT */

        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function checkedGroup(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(),
            [
                'user_id_buyer' => ['required', 'integer'],
                'checked' => ['required', 'boolean'],
                'user_id_seller' => ['required', 'integer'],
            ]
        );

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR AND GET */

        /* CHANGE CHECKED AND VALIDATION STOCK PRODUCT */
        $keranjangs = Keranjang::where('user_id_seller', $validate['user_id_seller'])
                               ->where('user_id_buyer', $validate['user_id_buyer'])
                               ->get();

        foreach($keranjangs as $keranjang)
        {
            $productSoldOutIds = $this->keranjangService->checkProductSoldOutByIds([$keranjang->product_id]);

            $keranjang->checked = ($validate['checked']) && empty($productSoldOutIds['ids']) ? true : false;
            $keranjang->save();
        }
        //   ->update(['checked' => $validate['checked']]);
        /* CHANGE CHECKED AND VALIDATION STOCK PRODUCT */

        /* GET KERANJANGS */
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        /* GET KERANJANGS */

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

        /* GET KERANJANGS */
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        /* GET KERANJANGS */

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

        /* GET KERANJANGS */
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        /* GET KERANJANGS */

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

        /* VALIDATES IF TOTAL KERANJANG > STOCK PRODUCT */
        if($validate['total'] > $product->stock) {
            /* GET KERANJANGS */
            $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
            /* GET KERANJANGS */

            return response()->json(['status' => 422, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice, 'message' => ['stock_maximum' => ["This product stock is a maximum of {$product->stock}"]]], 422);
        }  
        /* VALIDATES IF TOTAL KERANJANG > STOCK PRODUCT */

        /* CHANGE TOTAL KERANJANG */
        $keranjang->total = $validate['total'];
        $keranjang->save();
        /* CHANGE TOTAL KERANJANG */

        /* GET KERANJANGS */
        $getKeranjangs = $this->keranjangService->getKeranjangs($validate['user_id_buyer']);
        $keranjangs = $getKeranjangs['keranjangs'] ?? [];
        $totalPrice = $getKeranjangs['totalPrice'] ?? 0;
        /* GET KERANJANGS */

        return response()->json(['status' => 200, 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 200);
    }

    public function validateCheckout(Request $request)
    {
        /* VALIDATOR AND GET */
        $validator = Validator::make($request->all(),[
            'product_ids' => ['required', 'array'],
            'user_id_buyer' => ['required']
        ]);

        if($validator->fails())
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATOR AND GET */

        /* VALIDATION ALAMAT BUYER */
        $checkAlamatBuyerExist = $this->keranjangService->checkAlamatBuyerExist($request->user_id_buyer);

        if(!$checkAlamatBuyerExist['exists']) 
            return response()->json(['status' => 'error', 'message' => 'Alamat anda belum ditambahkan'], 400);
        /* VALIDATION ALAMAT BUYER */

        /* VALIDATION KERANJANG NOT CHECKED */
        $keranjangNotChecked = $this->keranjangService->checkKeranjangNotChecked($request->user_id_buyer);
        
        if(!$keranjangNotChecked['checked'])
            return response()->json(['status' => 'error', 'message' => 'Keranjang belum ada yang di checked'], 400);
        /* VALIDATION KERANJANG NOT CHECKED */

        /* CHECK IF THE PRODUCT WITH THAT ID HAS MORE THAN 0 STOCK */
        $productSoldOutIds = $this->keranjangService->checkProductSoldOutByIds($request->product_ids);

        // info(['productSoldOutIds' => $productSoldOutIds]);

        if(!empty($productSoldOutIds['ids']))
        {
            Keranjang::whereIn('product_id', $productSoldOutIds)
                     ->where('user_id_buyer', $request->user_id_buyer)
                     ->update([
                        'checked' => 0,
                        'total' => 0
                     ]);

            $getKeranjangs = $this->keranjangService->getKeranjangs($request->user_id_buyer);
            $keranjangs = $getKeranjangs['keranjangs'] ?? [];
            $totalPrice = $getKeranjangs['totalPrice'] ?? 0;

            return response()->json(['status' => 'error', 'message' => 'There is a problem because the item is out of stock, please select again', 'keranjangs' => $keranjangs, 'totalPrice' => $totalPrice], 400);
        }
        /* CHECK IF THE PRODUCT WITH THAT ID HAS MORE THAN 0 STOCK */

        /* UPDATE CHECKOUT PRODUCT */
        $this->keranjangService->updateCheckoutKeranjang($request->user_id_buyer);
        /* UPDATE CHECKOUT PRODUCT */
        
        return response()->json(['status' => 'success', 'message' => 'Checkout validation successful']);
    }
}
