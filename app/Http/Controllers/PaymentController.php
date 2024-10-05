<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Midtrans\Config as MidtransConfig;
use Midtrans\Snap as MidtransSnap;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function checkConnectAccountStripe(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required', 'integer'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        $user = User::where('id', $request->user_id_seller)
                    ->first();

        Log::info('', [$user]);

        /* IF USER NOT FOUND */
        if(!$user)
            return response()->json(['result' => 'failed', 'account' => '', 'message' => 'User Not Found'], 422);
        /* IF USER NOT FOUND */

        /* IF USER NOT YET REGISTERED */
        if(empty($user->connect_account_id)) 
            return response()->json(['result' => 'success', 'account' => '', 'message' => 'User Has Not Registered a Connected Account, Please Connect Your Account Before Transaction']);
        /* IF USER NOT YET REGISTERED */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        try
        {
            $account = $stripe->accounts->retrieve($user->connect_account_id, []);

            return response()->json(['result' => 'success', 'account' => $account, 'message' => '']);
        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'success', 'account' => '', 'message' => "Something Error : {$e->getMessage()}"], 400);
        }
    }

    public function connectAccountStripe(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required', 'integer'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        $user = User::where('id', $request->user_id_seller)
                    ->first();

        /* IF USER NOT FOUND */
        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found'], 422);
        /* IF USER NOT FOUND */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        /* IF THE CONNECTED ACCOUNT NOT BEEN CREATED */
        if(empty($user->connect_account_id))
        {
            Log::info("IF THE CONNECTED ACCOUNT NOT BEEN CREATED");
            try 
            {
                /* CREATE ACCOUNT CONNECT */
                $accountCreate = $stripe->accounts->create([
                    'type' => 'standard',
                    'business_type' => 'non_profit',
                    'business_profile' => [
                        'mcc' => 8661,
                        'url' => 'https://ecommerce-frontend-delta-seven.vercel.app',
                    ]
                ]);
                /* CREATE ACCOUNT CONNECT */

                $accID = $accountCreate->id ?? "";

                /* CREATE ACCOUNT LINK */
                $createAccountLink = $this->createAccountLinkStripe($accID);
                /* CREATE ACCOUNT LINK */

                if($createAccountLink->result == 'success')
                {
                    $user->connect_account_id = $accID;
                    $user->save();
                    return response()->json(['result' => 'success', 'accountLink' => $createAccountLink->accountLink, 'message' => 'Success Create Account Link']);
                }
                else if($createAccountLink->result == 'failed')
                {
                    return response()->json(['result' => 'failed', 'accountLink' => '', 'message' => $createAccountLink->message], 400);
                }
            }
            catch (\Exception $e)
            {
                return response()->json(['result' => 'failed', 'accountLink' => '', 'message' => $e->getMessage()], 400);
            }
        }
        /* IF THE CONNECTED ACCOUNT NOT BEEN CREATED */
        /* IF THE CONNECTED ACCOUNT HAS ALREADY BEEN CREATED */
        else if(!empty($user->connect_account_id))
        {
            Log::info("IF THE CONNECTED ACCOUNT HAS ALREADY BEEN CREATED");
            try 
            {
                $accID = $user->connect_account_id ?? "";

                /* CREATE ACCOUNT LINK */
                $createAccountLink = $this->createAccountLinkStripe($accID);
                /* CREATE ACCOUNT LINK */

                if($createAccountLink->result == 'success')
                {
                    $user->connect_account_id = $accID;
                    $user->save();
                    return response()->json(['result' => 'success', 'accountLink' => $createAccountLink->accountLink, 'message' => 'Success Create Account Link']);
                }
                else if($createAccountLink->result == 'failed')
                {
                    return response()->json(['result' => 'failed', 'accountLink' => '', 'message' => $createAccountLink->message], 400);
                }
            }
            catch (\Exception $e)
            {
                return response()->json(['result' => 'failed', 'accountLink' => '', 'message' => $e->getMessage()], 400);
            }
        }
        /* IF THE CONNECTED ACCOUNT HAS ALREADY BEEN CREATED */
        /* IF FIELD CONNECT_ACCOUNT_ID NOT FOUND */
        else 
        {
            return response()->json(['status' => 422, 'message' => 'Field connect_account_id Not Found'], 422);
        }
        /* IF FIELD CONNECT_ACCOUNT_ID NOT FOUND */
    }

    private function createAccountLinkStripe(string $accID)
    {
        $secret_key = config('stripe.secret.key');
        Log::info(['secret_key' => $secret_key]);

        $stripe = new StripeClient($secret_key);

        try
        {
            $accountLink = $stripe->accountLinks->create([
                'account' => $accID,
                'refresh_url' => 'https://ecommerce-frontend-delta-seven.vercel.app',
                'return_url' => 'https://ecommerce-frontend-delta-seven.vercel.app',
                'type' => 'account_onboarding'
            ]);

            Log::info('', ['accountLink' => $accountLink]);

            return (object) ['result' => 'success', 'accountLink' => $accountLink, 'message' => ''];
        }
        catch (\Exception $e)
        {
            Log::info('', ['error' => $e->getMessage()]);
            return (object) ['result' => 'failed', 'accountLink' => '', 'message' => $e->getMessage()];
        }
    }

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
