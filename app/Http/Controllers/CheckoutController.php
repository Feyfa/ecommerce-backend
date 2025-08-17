<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends Controller
{
    protected CheckoutService $checkoutService; 
    protected XenditService $xenditService;
    protected PaymentService $paymentService;
    
    public function __construct() 
    {
        $this->checkoutService = new CheckoutService();
        $this->xenditService = new XenditService(config('xendit.key'));
        $this->paymentService = new PaymentService();
    }

    public function getDataCheckout()
    {
        /* VALIDATION USER */
        $user_id_buyer = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id_buyer)->exists();

        if(!$userExists)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET ALAMAT */
        $getAlamatBuyer = $this->checkoutService->getAlamatBuyer($user_id_buyer);
        $alamat = $getAlamatBuyer['alamat'];

        if(empty($alamat))
            return response()->json(['status' => 'error', 'message' => 'Alamat belum ditambahkan'], 400);
        /* GET ALAMAT */

        /* GET DATA CHECKOUT */
        $getKeranjangCheckout = $this->checkoutService->getKeranjangCheckout($user_id_buyer);
        $checkouts = $getKeranjangCheckout['checkouts'];
        $totalPrice = $getKeranjangCheckout['totalPrice'];

        if(count($checkouts) == 0)
            return response()->json(['status' => 'error', 'message' => 'Keranjang Not Checked'], 400);
        /* GET DATA CHECKOUT */

        /* GET PAYMENT */
        $getCheckoutPayment = $this->paymentService->getCheckoutPayment();
        $payments = $getCheckoutPayment['payments'];

        if(count($payments) == 0)
            return response()->json(['status' => 'error', 'message' => 'Payment List Empty'], 400);
        /* GET PAYMENT */

        return response()->json([
            'status' => 'success',
            'alamat' => $alamat,
            'checkouts' => $checkouts,
            'payments' => $payments,
            'totalPrice' => $totalPrice
        ]);
    }

    public function processCheckout(Request $request)
    {
        $startTime = microtime(true);
        /* VALIDATION USER */
        $user_id_buyer = optional(auth()->user())->id;
        $user = User::where('id', $user_id_buyer)->first();

        if(!$user)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION REQUEST */
        $validator = Validator::make($request->all(), [
            'checkouts' => ['required'],
            'kurirs' => ['required'],
            'noteds' => ['required'],
            'alamat' => ['required'],
            'payment_method' => ['required'],
            'payment_slug' => ['required'],
            'payment_name' => ['required'],
            'price' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATION REQUEST */

        /* CREATE PAYMENT IN XENDIT */
        $resultXendit = [];
        $now = Carbon::now()->timestamp;
        $uniqid = uniqid();
        $external_id = "";
        $bank_code = "";
        $name = $user->name ?? "";
        $country = "ID";
        $currency = "IDR";
        $is_single_use = true;
        $is_closed = true;
        $expected_amount = intval($request->price ?? 0);
        
        $expired_at = Carbon::now()->addDay()->setMinutes(0)->setSeconds(0)->setMicroseconds(0);
        $expired_at_xendit = $expired_at->toIso8601String();
        $expired_at_transaction = $expired_at->format('Y-m-d H:i:s');

        if($request->payment_method == 'va' && $request->payment_slug == 'bca' && $request->payment_name == 'BCA Virtual Account')
        {
            $external_id = "{$request->payment_method}-{$request->payment_slug}-{$user_id_buyer}-{$now}-{$uniqid}";
            $bank_code = "BCA";
            
            $resultXendit = $this->xenditService->createVirtualAccount(
                external_id: $external_id,
                bank_code: $bank_code,
                name: $name,
                country: $country,
                currency: $currency,
                is_single_use: $is_single_use,
                is_closed: $is_closed,
                expected_amount: $expected_amount,
                expiration_date: $expired_at_xendit
            );
        }
        else 
        {
            return response()->json(['status' => 'error', 'message' => 'Pembayaran Harus Menggunakan BCA Virtual Account'], 400);
        }

        if($resultXendit['status'] == 'error') 
        {
            return response()->json(['status' => $resultXendit['status'], 'message' => $resultXendit['message']], 400);
        }
        /* CREATE PAYMENT IN XENDIT */

        
    
        /* SAVE CHECKOUT TO DATABASE */
        // info('', ['checkouts' => $request->checkouts]);
        $saveCheckoutToDatabase = $this->checkoutService->saveCheckoutToDatabase(
            user_id_buyer: $user_id_buyer,
            checkouts: $request->checkouts,
            kurirs: $request->kurirs,
            noteds: $request->noteds,
            alamat: $request->alamat,
            payment_method: $request->payment_method,
            payment_slug: $request->payment_slug,
            payment_name: $request->payment_name,
            expired_at: $expired_at_transaction,
            price: $request->price,
            dataXendit: $resultXendit['data'] ?? []
        );

        if($saveCheckoutToDatabase['status'] == 'error') 
        {
            return response()->json(['status' => $saveCheckoutToDatabase['status'], 'message' => $saveCheckoutToDatabase['message']], 400);
        }
        /* SAVE CHECKOUT TO DATABASE */

        /* DELETE KERANJANG */
        $deleteKeranjangAfterCheckout = $this->checkoutService->deleteKeranjangAfterCheckout(
            checkouts: $request->checkouts
        );

        if($deleteKeranjangAfterCheckout['status'] == 'error') 
        {
            return response()->json(['status' => $deleteKeranjangAfterCheckout['status'], 'message' => $deleteKeranjangAfterCheckout['message']], 400);
        }
        /* DELETE KERANJANG */

        /* CHANGE TOTAL PRODUCT */
        $changeStockProductAfterCheckout = $this->checkoutService->changeStockProductAfterCheckout(
            checkouts: $request->checkouts
        );
        
        if($changeStockProductAfterCheckout['status'] == 'error') 
        {
            return response()->json(['status' => $changeStockProductAfterCheckout['status'], 'message' => $changeStockProductAfterCheckout['message']], 400);
        }
        /* CHANGE TOTAL PRODUCT */
        
        // info('', ['checkouts' => $request->checkouts,'kurirs' => $request->kurirs,'noteds' => $request->noteds,'alamat' => $request->alamat,'payment' => $request->payment,'price' => $request->price,'resultXendit' => $resultXendit['data'],]);
        // info('Duration = ' . (microtime(true) - $startTime));

        return response()->json(['status' => 'success', 'message' => 'Pembayaran Berhasil']);
    }
}
