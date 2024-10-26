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
    public function replaceAch(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
            'holder_type' => ['required'],
            'holder_name' => ['required'],
            'routing_number' => ['required'],
            'account_number' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* GTE CONNECT ACCCOUNT */
        $user = User::where('id', $request->user_id_seller)
                    ->first();
        
        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found'], 422);
        else if(empty($user->connect_account_id)) 
            return response()->json(['result' => 'failed', 'message' => 'Your Connected Not Exists, Please Connect Your Stripe'], 422);
        /* GTE CONNECT ACCCOUNT */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        /* DELETE BANK ACCOUNT */
        try
        {
            /* DELETE ACH */
            $deleteSource = $stripe->customers->deleteSource(
                $user->topup_payment_id,
                $user->topup_ach_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );
            /* DELETE ACH */
        }
        catch (\Exception $e)
        {
            Log::info(['result1' => 'failed', 'message' => $e->getMessage()]);
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* DELETE BANK ACCOUNT */

        /* CREATE BANK ACCOUNT */
        try
        {
            /* CREATE TOKEN */
            $token = $stripe->tokens->create([
                "bank_account" => [
                    "country" => "US",
                    "currency" => "USD",
                    "account_holder_name" => $request->holder_name,
                    "account_holder_type" => $request->holder_type,
                    "routing_number" => $request->routing_number,
                    "account_number" => $request->account_number,
                ]
            ]);
            $token_id = $token->id;
            /* CREATE TOKEN */

            /* ADD BANK CUSTOMER */
            $customerCreate = $stripe->customers->createSource(
                $user->topup_payment_id,
                ['source' => $token_id],
                ['stripe_account' => $user->connect_account_id]
            );

            $user->topup_ach_id = $customerCreate->id ?? "";
            /* ADD BANK CUSTOMER */

            /* SAVE BANK CUSTOMER TO DATABASE */
            $user->verification_status_ach = 'pending';
            $user->save();
            /* SAVE BANK CUSTOMER TO DATABASE */

            /* GET CARD INFO */
            $customerRetreive = $stripe->customers->retrieveSource(
                $user->topup_payment_id,
                $user->topup_ach_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );
            return response()->json(['result' => 'success', 'message' => 'Create Bank Acccount Success', 'params_ach' => $customerRetreive]);
            /* GET CARD INFO */
        }
        catch (\Exception $e)
        {
            Log::info(['result1' => 'failed', 'message' => $e->getMessage()]);
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* CREATE BANK ACCOUNT */
    }

    public function deleteAch(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* CHECK PAYMENT METHOD AND ACH EXISTS */
        $user = User::where('id', $request->user_id_seller)
                    ->first();

        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found'], 422);
        else if(empty($user->connect_account_id) || empty($user->topup_payment_id) || empty($user->topup_ach_id))
            return response()->json(['result' => 'failed', 'message' => 'You Not Have Payment Method Ach'], 422);
        /* CHECK PAYMENT METHOD AND ACH EXISTS */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        try
        {
            /* DELETE ACH */
            $deleteSource = $stripe->customers->deleteSource(
                $user->topup_payment_id,
                $user->topup_ach_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );
            /* DELETE ACH */

            /* UPDATE TO DATABASE */
            $user->topup_ach_id = '';
            $user->verification_status_ach = '';
            $user->save();
            /* UPDATE TO DATABASE */

            return response()->json(['result' => 'success', 'message' => 'Delete Bank Account Success']);
        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
    }

    public function verifyAch(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
            'micro_deposite_1' => ['required', 'numeric'],
            'micro_deposite_2' => ['required', 'numeric'], 
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* CHECK PAYMENT METHOD AND ACH EXISTS */
        $user = User::where('id', $request->user_id_seller)
                    ->first();

        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found', 'params_ach' => ''], 422);
        else if(empty($user->connect_account_id) || empty($user->topup_payment_id) || empty($user->topup_ach_id))
            return response()->json(['result' => 'failed', 'message' => 'You Not Have Payment Method Ach', 'params_ach' => ''], 422);
        /* CHECK PAYMENT METHOD AND ACH EXISTS */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        $micro_deposite_1 = round($request->micro_deposite_1 * 100, 2);
        $micro_deposite_2 = round($request->micro_deposite_2 * 100, 2);
        
        try
        {
            /* VERIF MICRO DEPOSITE */
            $bank_account = $stripe->customers->retrieveSource(
                $user->topup_payment_id,
                $user->topup_ach_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );
            $bank_account = $bank_account->verify(['amounts' => [$micro_deposite_1, $micro_deposite_2]]);
            /* VERIF MICRO DEPOSITE */

            /* UPDATE TO DATABASE */
            $user->verification_status_ach = 'verify';
            $user->save();
            /* UPDATE TO DATABASE */

            return response()->json(['result' => 'success', 'message' => 'Verification Micro Deposite Success', 'params_ach' => $bank_account]);
        }
        catch (\Exception $e)
        {
            try 
            {
                /* GET INFO */
                $customer = $stripe->customers->retrieveSource(
                    $user->topup_payment_id,
                    $user->topup_ach_id,
                    [],
                    ['stripe_account' => $user->connect_account_id]
                );
                /* GET INFO */

                Log::info([
                    'customer' => $customer
                ]);

                return response()->json(['result' => 'failed', 'message' => $e->getMessage(), 'params_ach' => $customer], 400);
            }
            catch (\Exception $e)
            {
                return response()->json(['result' => 'failed', 'message' => $e->getMessage(), 'params_ach' => ''], 400);
            }
        }
    }

    public function createAch(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
            'holder_type' => ['required'],
            'holder_name' => ['required'], 
            'routing_number' => ['required'],
            'account_number' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* CHECK PAYMENT METHOD AND CC EXISTS */
        $user = User::where('id', $request->user_id_seller)
                    ->first();

        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found', 'params' => ''], 422);
        else if(empty($user->connect_account_id))
            return response()->json(['result' => 'failed', 'message' => 'Your Connected Not Exists, Please Connect Your Stripe'], 422);
        /* CHECK PAYMENT METHOD AND CC EXISTS */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        /* ADD BANK ACCOUNT ACH */
        try
        {
            /* CREATE TOKEN */
            $token = $stripe->tokens->create([
                "bank_account" => [
                    "country" => "US",
                    "currency" => "USD",
                    "account_holder_name" => $request->holder_name,
                    "account_holder_type" => $request->holder_type,
                    "routing_number" => $request->routing_number,
                    "account_number" => $request->account_number,
                ]
            ]);
            $token_id = $token->id;
            /* CREATE TOKEN */

            /* CHECK APAKAH CUTOMER ALREADY EXISTS */
            if(empty($user->topup_payment_id))
            {
                /* ADD BANK CUSTOMER */
                $customerCreate = $stripe->customers->create([
                    "name" => $user->name,
                    "email" => $user->email,
                    "source" => $token_id,
                ],['stripe_account' => $user->connect_account_id]);

                $user->topup_payment_id = $customerCreate->id;
                $user->topup_ach_id = $customerCreate->default_source ?? "";
                /* ADD BANK CUSTOMER */

                Log::info("1", ['customerCreate' => $customerCreate]);
            }
            else 
            {
                /* ADD BANK CUSTOMER */
                $customerCreate = $stripe->customers->createSource(
                    $user->topup_payment_id,
                    ['source' => $token_id],
                    ['stripe_account' => $user->connect_account_id]
                );

                $user->topup_ach_id = $customerCreate->id ?? "";
                /* ADD BANK CUSTOMER */

                Log::info("2", ['customerCreate' => $customerCreate]);
            }
            /* CHECK APAKAH CUTOMER ALREADY EXISTS */

            /* SAVE BANK CUSTOMER TO DATABASE */
            $user->verification_status_ach = 'pending';
            $user->save();
            /* SAVE BANK CUSTOMER TO DATABASE */

            /* GET CARD INFO */
            $customerRetreive = $stripe->customers->retrieveSource(
                $user->topup_payment_id,
                $user->topup_ach_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );
            return response()->json(['result' => 'success', 'message' => 'Create Bank Acccount Success', 'params_ach' => $customerRetreive]);
            /* GET CARD INFO */
        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* ADD BANK ACCOUNT ACH */
    }

    public function getInfoPaymentMethod(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required']
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages(), 'params_cc' => '', 'params_ach' => ''], 422);
        /* VALIDATOR */

        /* CHECK PAYMENT METHOD AND CC EXISTS */
        $user = User::where('id', $request->user_id_seller)
                    ->first();

        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found', 'params_cc' => '', 'params_ach' => ''], 422);
        else if(empty($user->connect_account_id) || (empty($user->topup_payment_id)) || (empty($user->topup_payment_id) && empty($user->topup_card_id)) || (empty($user->topup_payment_id) && empty($user->topup_ach_id)))
            return response()->json(['result' => 'success', 'message' => '', 'params_cc' => '', 'params_ach' => '']);
        /* CHECK PAYMENT METHOD AND CC EXISTS */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        $params_cc = [];
        $params_ach = [];

        /* GET CREDIT CARD INFO */
        if(!empty($user->topup_card_id))
        {
            try
            {
                Log::info([
                    $user->topup_payment_id,
                    $user->topup_card_id,
                    [],
                    $user->connect_account_id
                ]);
                $customer_cc = $stripe->customers->retrieveSource(
                    $user->topup_payment_id,
                    $user->topup_card_id,
                    [],
                    ['stripe_account' => $user->connect_account_id]
                );
    
                Log::info(['customer_cc' => $customer_cc->toArray()]);

                $params_cc = $customer_cc->toArray();
            }
            catch (\Exception $e) 
            {
                Log::info("error = {$e->getMessage()}");
                return response()->json(['result' => 'failed', 'message' => $e->getMessage(), 'params_cc' => '', 'params_ach' => ''], 400);
            }
        }
        /* GET CREDIT CARD INFO */

        /* GET BANK ACCOUNT INFO */
        if(!empty($user->topup_ach_id))
        {
            try
            {
                Log::info([
                    $user->topup_payment_id,
                    $user->topup_ach_id,
                    [],
                    $user->connect_account_id
                ]);
                $customer_ach = $stripe->customers->retrieveSource(
                    $user->topup_payment_id,
                    $user->topup_ach_id,
                    [],
                    ['stripe_account' => $user->connect_account_id]
                );
    
                Log::info(['customer_ach' => $customer_ach->toArray()]);

                $params_ach = $customer_ach->toArray();
            }
            catch (\Exception $e)
            {
                Log::info("error = {$e->getMessage()}");
                return response()->json(['result' => 'failed', 'message' => $e->getMessage(), 'params_cc' => '', 'params_ach' => ''], 400);
            }
        }
        /* GET BANK ACCOUNT INFO */
        return response()->json(['result' => 'success', 'message' => '', 'params_cc' => $params_cc, 'params_ach' => $params_ach]);
    }

    public function createCreditCard(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
            'zip' => ['required'],
            'token' => ['required'],
            'email' => ['required', 'email'],
            'card_holder_name' => ['required'],
            'address' => ['required'],
            'country' => ['required'],
            'state' => ['required'],
            'city' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* GTE CONNECT ACCCOUNT */
        $user = User::where('id', $request->user_id_seller)
                    ->where('email', $request->email)
                    ->first();
        
        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found'], 422);
        else if(empty($user->connect_account_id)) 
            return response()->json(['result' => 'failed', 'message' => 'Your Connected Not Exists, Please Connect Your Stripe'], 422);
        /* GTE CONNECT ACCCOUNT */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */
        
        /* CREATE CUSTOMER AND CREDIT CARD */
        try 
        {
            /* CHECK APAKAH CUTOMER ALREADY EXISTS */
            if(empty($user->topup_payment_id))
            {
                $customerCreate = $stripe->customers->create([
                    'name' => $user->name, // Nama pemegang kartu
                    'email' => $user->email, // Ganti dengan email yang valid
                    'source' => $request->token, // Menggunakan token yang didapat dari Stripe.js
                ], ['stripe_account' => $user->connect_account_id]);
                
                Log::info('1', ['customerCreate' => $customerCreate]);

                $user->topup_payment_id = $customerCreate->id ?? "";
                $user->topup_card_id = $customerCreate->default_source ?? "";
            }
            else 
            {
                $customerCreate = $stripe->customers->createSource(
                    $user->topup_payment_id, // ID customer yang sudah ada
                    ['source' => $request->token],
                    ['stripe_account' => $user->connect_account_id] // Menggunakan connected account
                );

                Log::info("2", ['customerCreate' => $customerCreate]);

                $user->topup_card_id = $customerCreate->id ?? "";
            }
            /* CHECK APAKAH CUTOMER ALREADY EXISTS */

            $user->save();
        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* CREATE CUSTOMER AND CREDIT CARD */

        /* UPDATE PAYMENT METHOD */
        try
        {
            $customerUpdate = $stripe->customers->updateSource(
                $user->topup_payment_id,
                $user->topup_card_id,
                [
                    'name' => $request->card_holder_name,
                    'address_line1' => $request->address,
                    'address_city' => $request->city,
                    'address_state' => $request->state,
                    'address_country' => $request->country,
                    'address_zip' => $request->zip,
                ], 
                ['stripe_account' => $user->connect_account_id]
            );

            Log::info('', ['customerUpdate' => $customerUpdate]);

        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* UPDATE PAYMENT METHOD */

        /* GET CARD INFO */
        try 
        {
            $customerRetreive = $stripe->customers->retrieveSource(
                $user->topup_payment_id,
                $user->topup_card_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );

            return response()->json(['result' => 'success', 'params' => $customerRetreive]);
        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* GET CARD INFO */
    }

    public function replaceCreditCard(Request $request)
    {
        Log::info('start replaceCreditCard');
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
            'zip' => ['required'],
            'token' => ['required'],
            'email' => ['required', 'email'],
            'card_holder_name' => ['required'],
            'address' => ['required'],
            'country' => ['required'],
            'state' => ['required'],
            'city' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* GTE CONNECT ACCCOUNT */
        $user = User::where('id', $request->user_id_seller)
                    ->where('email', $request->email)
                    ->first();
        
        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found'], 422);
        else if(empty($user->connect_account_id)) 
            return response()->json(['result' => 'failed', 'message' => 'Your Connected Not Exists, Please Connect Your Stripe'], 422);
        /* GTE CONNECT ACCCOUNT */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        /* DELETE CREDIT CARD */
        try
        {
            $customerDelete = $stripe->customers->deleteSource(
                $user->topup_payment_id,
                $user->topup_card_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );

            Log::info('', ['customerDelete' => $customerDelete]);
        }
        catch (\Exception $e)
        {
            Log::info(['result1' => 'failed', 'message' => $e->getMessage()]);
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* DELETE CREDIT CARD */
        
        /* CREATE CREDIT CARD */
        try 
        {
            $customerCreate = $stripe->customers->createSource(
                $user->topup_payment_id,
                ['source' => $request->token], // Menggunakan token yang didapat dari Stripe.js 
                ['stripe_account' => $user->connect_account_id]
            );

            Log::info('', ['customerCreate' => $customerCreate]);
        }
        catch (\Exception $e)
        {
            Log::info(['result2' => 'failed', 'message' => $e->getMessage()]);
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* CREATE CREDIT CARD */

        $user->topup_card_id = $customerCreate->id;
        $user->save();

        /* UPDATE PAYMENT METHOD */
        try
        {
            Log::info([
                $user->topup_payment_id,
                $user->topup_card_id,
                [
                    'name' => $request->card_holder_name,
                    'address_line1' => $request->address,
                    'address_city' => $request->city,
                    'address_state' => $request->state,
                    'address_country' => $request->country,
                    'address_zip' => $request->zip,
                ], 
                ['stripe_account' => $user->connect_account_id]
            ]);
            $customerUpdate = $stripe->customers->updateSource(
                $user->topup_payment_id,
                $user->topup_card_id,
                [
                    'name' => $request->card_holder_name,
                    'address_line1' => $request->address,
                    'address_city' => $request->city,
                    'address_state' => $request->state,
                    'address_country' => $request->country,
                    'address_zip' => $request->zip,
                ], 
                ['stripe_account' => $user->connect_account_id]
            );

            Log::info('', ['customerUpdate' => $customerUpdate]);

        }
        catch (\Exception $e)
        {
            Log::info(['result3' => 'failed', 'message' => $e->getMessage()]);
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
        }
        /* UPDATE PAYMENT METHOD */

        /* GET CARD UINFO */
        try 
        {
            $customerRetreive = $stripe->customers->retrieveSource(
                $user->topup_payment_id,
                $user->topup_card_id,
                [],
                ['stripe_account' => $user->connect_account_id]
            );

            return response()->json(['result' => 'success', 'params' => $customerRetreive]);
        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $e->getMessage()], 400);
            
        }
    }

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
            return response()->json(['result' => 'warning', 'account' => '', 'message' => 'User Has Not Registered a Connected Account, Please Connect Your Account Before Transaction'], 400);
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
            return response()->json(['result' => 'failed', 'account' => '', 'message' => "Something Error : {$e->getMessage()}"], 400);
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
                    'country' => 'US',
                    'type' => 'custom',
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                        'transfers' => ['requested' => true],
                    ],
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
