<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class WebhookStipeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = config('stripe.webhook.key');

        /* VERIFIKASI SIGNATURE */
        try
        {
            $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
        }
        catch (SignatureVerificationException $e) 
        {
            // Jika verifikasi signature gagal, balas dengan error Unauthorized
            Log::channel('stripe')->info('', ['error1' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 403);
        } 
        catch (UnexpectedValueException $e)
        {
            // Jika payload tidak bisa diproses, misalnya JSON tidak valid
            Log::channel('stripe')->info('', ['error2' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } 
        catch (\Exception $e)
        {
            // Jika terjadi error lain, balas dengan error umum
            Log::channel('stripe')->info('', ['error3' => $e->getMessage()]);
            return response()->json(['error' => 'Something went wrong'], 500);
        }
        /* VERIFIKASI SIGNATURE */

        $request->attributes->set('stripe_event', $event);

        return $next($request);
    }
}
