<?php

namespace App\Http\Controllers;

use App\Models\TopupHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookStripeController extends Controller
{
    public function stripe(Request $request)
    {   
        $stripe_app_name = config('stripe.app.name');
        
        $event = $request->attributes->get('stripe_event');
        $type = $event['type'] ?? "";
        $payment_method = $event['data']['object']['payment_method_types'][0] ?? "";
        $function = $event['data']['object']['metadata']['function'] ?? "";
        $app_name = $event['data']['object']['metadata']['app_name'] ?? "";
        
        // Log::channel('stripe')->info('', [
        //     'event' => $event
        // ]);
        // Log::channel('stripe')->info('', [
        //     'all' => $event->all(),
        //     'type' => $type,
        //     'payment_method' => $payment_method,
        //     'function' => $function,
        // ]);

        if($type == "payment_intent.succeeded" && $payment_method == "us_bank_account" && $function == "storeTopup" && $app_name === $stripe_app_name)
        {
            $this->paymentIntentSucceeded_usBankAccount_storeTopup($event);
        }
        else if($type == "payment_intent.payment_failed" && $payment_method == "us_bank_account" && $function == "storeTopup" && $app_name === $stripe_app_name)
        {
            $this->paymentIntentPaymentFailed_usBankAccount_storeTopup($event);
        }

    }

    private function paymentIntentSucceeded_usBankAccount_storeTopup(object $event)
    {
        Log::channel('stripe')->info('paymentIntentSucceeded_usBankAccount_storeTopup');

        /* VARIABLE */
        $metadata = $event['data']['object']['metadata'] ?? "";
        $topup_id = $metadata["topup_id"] ?? "";
        /* VARIABLE */

        /* UPDATE SUCCESS STATUS TOPUP HISTORIES */
        if(!empty($topup_id))
        {
            TopupHistory::where('id', $topup_id)
                        ->update([
                            'status' => 'success'
                        ]);
        }
        /* UPDATE SUCCESS STATUS TOPUP HISTORIES */
    }

    private function paymentIntentPaymentFailed_usBankAccount_storeTopup(object $event)
    {
        Log::channel('stripe')->info('paymentIntentPaymentFailed_usBankAccount_storeTopup');

        /* VARIABLE */
        $metadata = $event['data']['object']['metadata'] ?? "";
        $topup_id = $metadata["topup_id"] ?? "";
        /* VARIABLE */

        /* UPDATE SUCCESS STATUS TOPUP HISTORIES */
        if(!empty($topup_id))
        {
            TopupHistory::where('id', $topup_id)
                        ->update([
                            'status' => 'failed'
                        ]);
        }
        /* UPDATE SUCCESS STATUS TOPUP HISTORIES */
    }
}
