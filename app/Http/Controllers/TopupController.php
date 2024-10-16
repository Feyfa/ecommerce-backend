<?php

namespace App\Http\Controllers;

use App\Models\TopupHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Stripe\StripeClient;

class TopupController extends Controller
{   
    public function getTopupBalance(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* GET CONNECT ACCCOUNT */
        $user = User::where('id', $request->user_id_seller)
                    ->first();

        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found'], 422);
        else if(empty($user->connect_account_id)) 
            return response()->json(['result' => 'failed', 'message' => 'Your Connected Not Exists, Please Connect Your Stripe'], 422);
        /* GET CONNECT ACCCOUNT */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        try
        {
            /* GET TOPUP HISTORY */
            $topup_history = TopupHistory::where('user_id_seller', $request->user_id_seller)
                                        ->orderBy('id', 'DESC')
                                        ->get();
            /* GET TOPUP HISTORY */

            /* RETREIVE BALANCE */
            $balance = $stripe->balance->retrieve([], ['stripe_account' => $user->connect_account_id]);
            $balance_pending = round($balance->pending[0]->amount / 100, 2);
            $balance_available = round($balance->available[0]->amount / 100, 2);
            /* RETREIVE BALANCE */

            return response()->json(['result' => 'success', 'message' => '', 'balance_available' => $balance_available, 'balance_pending' => $balance_pending, 'topup_history' => $topup_history]);
        }
        catch(\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 400);
        }
    }

    public function storeTopup(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required'],
            'select_payment' => ['required'],
            'amount' => ['required'],
            'stripe_process_fee' => ['required'],
            'total_amount' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* GET CONNECT ACCCOUNT */
        $user = User::where('id', $request->user_id_seller)
                    ->first();

        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found'], 422);
        else if(empty($user->connect_account_id)) 
            return response()->json(['result' => 'failed', 'message' => 'Your Connected Not Exists, Please Connect Your Stripe'], 422);
        else if(empty($user->topup_payment_id))
            return response()->json(['result' => 'failed', 'message' => 'Your Payment Not Exists, Please Create Payment'], 422);
        else if($request->select_payment != 'credit_card' && $request->select_payment != 'bank_account')
            return response()->json(['result' => 'failed', 'message' => 'Please Select Payment'], 422);
        else if(($request->select_payment == 'credit_card' && empty($user->topup_card_id)) || ($request->select_payment == 'bank_account' && empty($user->topup_ach_id)))
            return response()->json(['result' => 'failed', 'message' => 'Your Payment Not Exists, Please Create Payment'], 422);
        else if(empty($request->total_amount) || $request->total_amount < 0.5)
            return response()->json(['result' => 'failed', 'message' => 'Amount Is Not Empty And Amount Greater Than 0.5'], 422);
        /* GET CONNECT ACCCOUNT */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */
        
        $payment_intent = "";
        $payment_intend_id = "";
        $last_number = "";

        $select_payment = $request->select_payment == 'credit_card' ? 'Credit Card' : 'Bank Account';

        $amount = round($request->amount, 2);
        $stripe_process_fee = round($request->stripe_process_fee, 2);

        $total_amount = round($request->total_amount, 2);
        $topup_amount = round($total_amount * 100, 2);

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $description = "Topup \$$amount $now";

        try
        {
            if($select_payment == 'Credit Card')
            {   
                /* PROCESS TOPUP */
                $payment_intent =  $stripe->paymentIntents->create([
                    'payment_method_types' => ['card'],
                    'customer' => trim($user->topup_payment_id),
                    'amount' => $topup_amount,
                    'currency' => 'usd',
                    'payment_method' => $user->topup_card_id,
                    'confirm' => true,
                    'description' => $description,
                ],['stripe_account' => $user->connect_account_id]);
                $payment_intend_id = $payment_intent->id ?? "";
                /* PROCESS TOPUP */

                /* RETREIVE CREDITR CARD */
                $params_cc = $stripe->customers->retrieveSource(
                    $user->topup_payment_id,
                    $user->topup_card_id,
                    [],
                    ['stripe_account' => $user->connect_account_id]
                );
                $last_number = $params_cc->last4;
                /* RETREIVE CREDITR CARD */

                /* CHECK STATUS */
                $payment_intent_status = $payment_intent->status ?? "";
                if($payment_intent_status == 'requires_action') 
                {
                    $message_error = "Payment unsuccessful: Stripe status '$payment_intent_status' indicates further user action is needed.";

                    TopupHistory::create([
                        'user_id_seller' => $request->user_id_seller,
                        'payment_intent_id' => $payment_intend_id,
                        'amount' => $amount,
                        'stripe_process_fee' => $stripe_process_fee,
                        'payment' => $select_payment,
                        'last_number' => $last_number,
                        'status' => 'failed',
                        'message_error' => $message_error,
                    ]);

                    /* GET TOPUP HISTORY */
                    $topup_history = TopupHistory::where('user_id_seller', $request->user_id_seller)
                                                 ->orderBy('id', 'DESC')
                                                 ->get();
                    /* GET TOPUP HISTORY */

                    return response()->json(['result' => 'failed', 'message' => $message_error, 'topup_history' => $topup_history], 400);
                }
                else 
                {
                    TopupHistory::create([
                        'user_id_seller' => $request->user_id_seller,
                        'payment_intent_id' => $payment_intend_id,
                        'amount' => $amount,
                        'stripe_process_fee' => $stripe_process_fee,
                        'payment' => $select_payment,
                        'last_number' => $last_number,
                        'status' => 'success',
                        'message_error' => '',
                    ]);

                    /* GET TOPUP HISTORY */
                    $topup_history = TopupHistory::where('user_id_seller', $request->user_id_seller)
                                                 ->orderBy('id', 'DESC')
                                                 ->get();
                    /* GET TOPUP HISTORY */

                    return response()->json(['result' => 'success', 'message' => "Topup \$$amount With $select_payment Successfully", 'topup_history' => $topup_history]);
                }
                /* CHECK STATUS */
            }
            else if($select_payment == 'Bank Account')
            {
                $ipAddress = $request->ip(); // Mendapatkan IP pengguna
                $userAgent = $request->header('User-Agent'); // Mendapatkan user agent pengguna

                /* PROCESS TOPUP */
                $payment_intent = $stripe->paymentIntents->create([
                    'amount' => $topup_amount,
                    'currency' => 'usd',
                    'payment_method_types' => ['us_bank_account'], // Menggunakan ACH
                    'customer' => trim($user->topup_payment_id), // Customer ID dari session
                    'payment_method' => $user->topup_ach_id, // Bank Account ID dari session
                    'confirm' => true, // Mengonfirmasi dan memulai pembayaran
                    'off_session' => false, // Pembayaran dilakukan saat sesi pengguna aktif
                    'description' => $description,
                    'mandate_data' => [
                        'customer_acceptance' => [
                            'type' => 'online',
                            'online' => [
                                'ip_address' => $ipAddress, // Alamat IP pengguna
                                'user_agent' => $userAgent, // User agent pengguna
                            ],
                        ],
                    ],
                ],['stripe_account' => $user->connect_account_id]);
                $payment_intend_id = $payment_intent->id ?? "";
                /* PROCESS TOPUP */

                /* RETREIVE CREDITR CARD */
                $params_ach = $stripe->customers->retrieveSource(
                    $user->topup_payment_id,
                    $user->topup_ach_id,
                    [],
                    ['stripe_account' => $user->connect_account_id]
                );
                $last_number = $params_ach->last4;
                /* RETREIVE CREDITR CARD */

                $topupHistory = TopupHistory::create([
                    'user_id_seller' => $request->user_id_seller,
                    'payment_intent_id' => $payment_intend_id,
                    'amount' => $amount,
                    'stripe_process_fee' => $stripe_process_fee,
                    'payment' => $select_payment,
                    'last_number' => $last_number,
                    'status' => 'pending',
                    'message_error' => '',
                ]);

                /* UPDATE METADATA */
                $stripe->paymentIntents->update(
                    $payment_intend_id, // Menggunakan ID Payment Intent yang baru dibuat
                    [
                        'metadata' => [
                            'topup_id' => $topupHistory->id, // Menyimpan ID top-up yang kamu miliki,
                            'function' => 'storeTopup'
                        ]
                    ],
                    ['stripe_account' => $user->connect_account_id] // Pastikan connected account juga dimasukkan jika ada
                );
                /* UPDATE METADATA */

                /* GET TOPUP HISTORY */
                $topup_history = TopupHistory::where('user_id_seller', $request->user_id_seller)
                                             ->orderBy('id', 'DESC')
                                             ->get();
                /* GET TOPUP HISTORY */

                return response()->json(['result' => 'success', 'message' => "Topup \$$amount With $select_payment Pending, Please Check Status Topup Periodically", 'topup_history' => $topup_history]);
            }
        }
        catch(\Exception $e)
        {
            Log::info(['error' => $e->getMessage()]);

            TopupHistory::create([
                'user_id_seller' => $request->user_id_seller,
                'payment_intent_id' => $payment_intend_id,
                'amount' => $amount,
                'stripe_process_fee' => $stripe_process_fee,
                'payment' => $select_payment,
                'last_number' => $last_number,
                'status' => 'failed',
                'message_error' => $e->getMessage(),
            ]);

            /* GET TOPUP HISTORY */
            $topup_history = TopupHistory::where('user_id_seller', $request->user_id_seller)
                                         ->orderBy('id', 'DESC')
                                         ->get();
            /* GET TOPUP HISTORY */

            return response()->json(['result' => 'failed', 'message' => "Payment unsuccessful: {$validator->messages()}", 'topup_history' => $topup_history], 400);
        }
    }

    public function getPaymentList(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'user_id_seller' => ['required']
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages(), 'params_cc' => '', 'params_ach' => ''], 422);
        /* VALIDATOR */

        /* GET CONNECT ACCCOUNT */
        $user = User::where('id', $request->user_id_seller)
                    ->first();
        
        if(!$user)
            return response()->json(['result' => 'failed', 'message' => 'User Not Found', 'params_cc' => '', 'params_ach' => ''], 422);
        else if(empty($user->connect_account_id)) 
            return response()->json(['result' => 'failed', 'message' => 'Your Connected Not Exists, Please Connect Your Stripe', 'params_cc' => '', 'params_ach' => ''], 422);
        /* GET CONNECT ACCCOUNT */

        /* SETUP STRIPE */
        $secret_key = config('stripe.secret.key');
        $stripe = new StripeClient($secret_key);
        /* SETUP STRIPE */

        /* GET ALL PALYMENT LIST USER */
        try 
        {
            /* RETREIVE CREDIT CARD IF EXISTS */
            $params_cc = "";
            if(!empty($user->topup_payment_id) && !empty($user->topup_card_id))
            {
                $params_cc = $stripe->customers->retrieveSource(
                    $user->topup_payment_id,
                    $user->topup_card_id,
                    [],
                    ['stripe_account' => $user->connect_account_id]
                );
            }
            /* RETREIVE CREDIT CARD IF EXISTS */

            /* RETREIVE BANK ACCOUNT IF EXISTS */
            $params_ach = "";
            if(!empty($user->topup_payment_id) && !empty($user->topup_ach_id) && $user->verification_status_ach == 'verify')
            {
                $params_ach = $stripe->customers->retrieveSource(
                    $user->topup_payment_id,
                    $user->topup_ach_id,
                    [],
                    ['stripe_account' => $user->connect_account_id]
                );
            }
            /* RETREIVE BANK ACCOUNT IF EXISTS */

            return response()->json(['result' => 'success', 'message' => '', 'params_cc' => $params_cc, 'params_ach' => $params_ach]);

        }
        catch (\Exception $e)
        {
            return response()->json(['result' => 'failed', 'message' => $validator->messages(), 'params_cc' => '', 'params_ach' => ''], 400);
        }
        /* GET ALL PALYMENT LIST USER */
    }
}
