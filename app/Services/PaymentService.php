<?php

namespace App\Services;

use App\Models\PaymentList;
use App\Models\PaymentUser;
use Faker\Factory as Faker;

class PaymentService
{
    /**
     * ambil list payment untuk checkout
     */
    public function getCheckoutPayment()
    {
        $paymentList = PaymentList::select('slug', 'method', 'name')
                                  ->where('type', 'incoming')
                                  ->where('method', 'va')
                                  ->get()
                                  ->toArray();

        return [
            'payments' => $paymentList
        ];
    }

    public function getWithdrawalPayments(string $user_id = "", $search = "")
    {
        /* GET PAYMENT */
        $payments = PaymentUser::select(
                                'payment_users.id as id',
                                'payment_lists.slug as slug',
                                'payment_lists.name as name',
                                'payment_users.account as account',
                                'payment_users.name as username'
                            )
                            ->join('payment_lists', 'payment_lists.id', '=', 'payment_users.payment_id')
                            ->where('payment_users.user_id', $user_id)
                            ->where('payment_lists.type', 'withdrawal');

        if(!empty($search) && trim($search) != '')
        {
            $payments->where(function ($query) use ($search) {
                $query->where('payment_lists.slug', 'LIKE', "%{$search}%")
                      ->orWhere('payment_lists.name', 'LIKE', "%{$search}%")
                      ->orWhere('payment_users.name', 'LIKE', "%{$search}%")
                      ->orWhere('payment_users.account', 'LIKE', "%{$search}%");
            });
        }
        
        $payments = $payments->orderBy('payment_users.id', 'desc')
                            //  ->limit(5)
                             ->get();
        /* GET PAYMENT */

        return ['status' => 'success', 'payments' => $payments];
    }

    public function getWithdrawalPayment(string $user_id, string $account)
    {
        /* VALIDATE */
        if(empty($user_id) || trim($user_id) == '')
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        if(empty($account) || trim($account) == '')
            return ['status' => 'error', 'message' => 'payment account cannot be empty'];
        /* VALIDATE */

        /* GET PAYMENT */
        $payment = PaymentUser::from('payment_users as pu')
                              ->select(
                                'pu.id',
                                'pu.name as user_name',
                                'pl.slug as payment_slug'
                              )
                              ->join('payment_lists as pl', 'pl.id', '=', 'pu.payment_id')
                              ->where('pu.user_id', $user_id)
                              ->where('pu.account', $account)
                              ->where('pl.type', 'withdrawal')
                              ->first();
        if(empty($payment))
            return ['status' => 'error', 'message' => 'rekening anda tidak ditemukan'];
        $payment = $payment->toArray();

        $paymentSlug = $payment['payment_slug'] ?? '';
        $userName = $payment['user_name'] ?? '';
        if(empty($paymentSlug) || trim($paymentSlug) == '')
            return ['status' => 'error', 'message' => "Payment Slug Cannot Be Empty"];
        if(empty($userName) || trim($userName) == '')
            return ['status' => 'error', 'message' => "User Name Cannot Be Empty"];
        /* GET PAYMENT */

        return ['status' => 'success', 'payment' => $payment];
    }

    public function generateFakeUser()
    {
        $faker = Faker::create('id_ID');

        $user = [
            'name' => $faker->name,
            'email' => $faker->unique()->safeEmail,
            'phone' => $faker->phoneNumber,
            'address' => $faker->address,
        ];

        return ['status' => 'success', 'user' => $user];
    }
}