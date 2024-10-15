<?php

namespace App\Http\Controllers;

use App\Models\TopupHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookStripeController extends Controller
{
    public function stripe(Request $request)
    {
        Log::channel('stripe')->info("stripe");
        
        $type = $request['type'] ?? "";
        $payment_method = $request['data']['object']['payment_method_types'][0] ?? "";
        $function = $request['data']['object']['metadata']['function'] ?? "";
        
        // Log::channel('stripe')->info('', [
        //     'all' => $request->all(),
        //     'type' => $type,
        //     'payment_method' => $payment_method,
        //     'function' => $function,
        // ]);

        /* FOR PAYMENT SUCCESS  */
        if($type == "payment_intent.succeeded" && $payment_method == "us_bank_account" && $function == "storeTopup")
        {
            $this->paymentIntentSucceeded_usBankAccount_storeTopup($request);
        }
        else if($type == "payment_intent.payment_failed" && $payment_method == "us_bank_account" && $function == "storeTopup")
        {
            $this->paymentIntentPaymentFailed_usBankAccount_storeTopup($request);
        }

    }

    private function paymentIntentSucceeded_usBankAccount_storeTopup(Request $request)
    {
        /* VARIABLE */
        $metadata = $request['data']['object']['metadata'] ?? "";
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

    private function paymentIntentPaymentFailed_usBankAccount_storeTopup(Request $request)
    {
        /* VARIABLE */
        $metadata = $request['data']['object']['metadata'] ?? "";
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
