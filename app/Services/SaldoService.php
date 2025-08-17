<?php 

namespace App\Services;

use App\Models\PaymentUser;
use App\Models\SaldoHistory;
use App\Models\SaldoUser;
use App\Models\User;
use Carbon\Carbon;
use DB;

class SaldoService
{
    public function getSaldo(string $user_id)
    {
        /* VALIDATION */
        if(empty($user_id) || trim($user_id) == '')
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        /* VALIDATION */

        /* GET SALDO USER */
        $saldoUser = SaldoUser::where('user_id', $user_id)->first();
        $saldoIncome = (int) ($saldoUser->saldo_income ?? 0);
        $saldoRefund = (int) ($saldoUser->saldo_refund ?? 0);
        $saldoTotal = $saldoIncome + $saldoRefund;
        /* GET SALDO USER */
        
        return ['status' => 'success', 'saldoTotal' => $saldoTotal, 'saldoIncome' => $saldoIncome, 'saldoRefund' => $saldoRefund];
    }

    public function getSaldoHistory(string $user_id, string $start_date, string $end_date, array $saldo_history_current_ids = [])
    {
        // info(__FUNCTION__, ['get_defined_vars' => get_defined_vars()]);
        /* VALIDATION */
        if(empty($user_id) || trim($user_id) == '')
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        /* VALIDATION */

        /* GET SALDO HISTORY */
        $saldoHistory = SaldoHistory::from('saldo_histories as sh')
                                    ->select(
                                        'sh.*',
                                        'tu.transaction_number',
                                        'u.name as buyer_name',
                                        'pu.name as payment_name',
                                        'pu.account as payment_account',
                                        'pl.slug as payment_slug'
                                    )
                                    // for income
                                    ->leftJoin('transaction_users as tu', 'tu.id', '=', 'sh.transaction_user_id')
                                    ->leftJoin('users as u', 'u.id', '=', 'tu.user_id_buyer')
                                    // for income

                                    // for withdrawal
                                    ->leftJoin('payment_users as pu', 'pu.id', '=', 'sh.payment_user_id')
                                    ->leftJoin('payment_lists as pl', 'pl.id', '=', 'pu.payment_id')
                                    // for withdrawal

                                    ->where('sh.user_id', $user_id);

        if(
            !empty($start_date) && trim($start_date) != '' &&
            !empty($end_date) && trim($end_date) != ''
        )
        {
            $start_date = Carbon::parse($start_date)->format('Y-m-d');
            $end_date = Carbon::parse($end_date)->format('Y-m-d');
            $saldoHistory->whereBetween(DB::raw('DATE(sh.created_at)'), [$start_date, $end_date]);
        }

        if(count($saldo_history_current_ids) > 0)
        {
            $saldoHistory->whereNotIn('sh.id', $saldo_history_current_ids);
        }

        $saldoHistory = $saldoHistory->orderBy('sh.id', 'desc')
                                     ->limit(30)
                                     ->get()
                                     ->map(function ($item, $index) {
                                        $dateFormat = Carbon::parse($item->created_at)
                                                            ->timezone('Asia/Jakarta')
                                                            ->translatedFormat('d F Y H:i');
                                        $title = match($item->type) {
                                            'incoming' => "Pemasukan Saldo",
                                            'withdrawal' => "Penarikan Saldo",
                                        };

                                        $priceString = number_format($item->price, 0, ',', '.');
                                        $paymentSlugUpper = strtoupper($item->payment_slug ?? "");
                                        $description = match($item->type) {
                                            'incoming' => "Pembelian Dari {$item->buyer_name} - INV {$item->transaction_number}",
                                            'withdrawal' => "Penarikan Saldo Sebesar Rp{$priceString} Ke Bank {$paymentSlugUpper} {$item->payment_account} ({$item->payment_name})"
                                        };

                                        return [
                                            'id' => $item->id,
                                            'type' => $item->type,
                                            'title' => $title,
                                            'date' => $dateFormat,
                                            'price' => $item->price,
                                            'description' => $description
                                        ];
                                     });
        // info('', ['saldoHistory' => $saldoHistory]);
        /* GET SALDO HISTORY */

        return ['status' => 'success', 'saldoHistory' => $saldoHistory];
    }

    public function getSaldoById(string $id)
    {
        // info(__FUNCTION__, ['get_defined_vars' => get_defined_vars()]);
        /* VALIDATION */
        if(empty($id) || trim($id) == '')
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        /* VALIDATION */

        $saldoHistory = SaldoHistory::from('saldo_histories as sh')
                                    ->select(
                                        'sh.*',
                                        'tu.transaction_number',
                                        'u.name as buyer_name',
                                        'pu.name as payment_name',
                                        'pu.account as payment_account',
                                        'pl.slug as payment_slug'
                                    )
                                    // for income
                                    ->leftJoin('transaction_users as tu', 'tu.id', '=', 'sh.transaction_user_id')
                                    ->leftJoin('users as u', 'u.id', '=', 'tu.user_id_buyer')
                                    // for income

                                    // for withdrawal
                                    ->leftJoin('payment_users as pu', 'pu.id', '=', 'sh.payment_user_id')
                                    ->leftJoin('payment_lists as pl', 'pl.id', '=', 'pu.payment_id')
                                    // for withdrawal

                                    ->where('sh.id', $id)
                                    ->first();
        
        $saldoHistoryMap = [];
        if($saldoHistory)
        {
            $dateFormat = Carbon::parse($saldoHistory->created_at)
                                ->timezone('Asia/Jakarta')
                                ->translatedFormat('d F Y H:i');

            $title = match($saldoHistory->type) {
                'incoming' => "Pemasukan Saldo",
                'withdrawal' => "Penarikan Saldo"
            };

            $priceString = number_format($saldoHistory->price, 0, ',', '.');
            $paymentSlugUpper = strtoupper($saldoHistory->payment_slug ?? "");
            $description = match($saldoHistory->type) {
                'incoming' => "Pembelian Dari {$saldoHistory->buyer_name} - INV {$saldoHistory->transaction_number}",
                'withdrawal' => "Penarikan Saldo Sebesar Rp{$priceString} Ke Bank {$paymentSlugUpper} {$saldoHistory->payment_account} ({$saldoHistory->payment_name})"
            };

            $saldoHistoryMap = [
                'id' => $saldoHistory->id,
                'type' => $saldoHistory->type,
                'title' => $title,
                'date' => $dateFormat,
                'price' => $saldoHistory->price,
                'description' => $description
            ];
        }

        return ['status' => 'success', 'saldoHistory' => $saldoHistoryMap];
    }

    public function saveSaldoAfterDisbursement(?string $user_id = null, string $payment_user_id = null, int $price = 0)
    {
        /* GET USER */
        $userExists = User::where('id', $user_id)
                          ->exists();
        if(!$userExists)
            return ['status' => 'error', 'message' => 'user not found'];
        /* GET USER */

        /* GET SALDO USER */
        $saldoUser = SaldoUser::where('user_id', $user_id)
                              ->first();
        $saldoRefund = (int) ($saldoUser->saldo_refund ?? 0);
        $saldoIncome = (int) ($saldoUser->saldo_income ?? 0);
        $saldoBefore = $saldoRefund + $saldoIncome;
        /* GET SALDO USER */

        /* REDUCE SALDO */
        if($saldoRefund >= $price)
        {
            $saldoRefund -= $price;
            $saldoUser->saldo_refund = $saldoRefund;
        }
        else 
        {
            $remainingPrice = $price - $saldoRefund;
            $saldoRefund = 0;
            $saldoUser->saldo_refund = $saldoRefund;
            
            if($saldoIncome >= $remainingPrice)
            {
                $saldoIncome -= $remainingPrice;
                $saldoUser->saldo_income = $saldoIncome;
            }
            else 
            {
                $saldoIncome = 0;
                $saldoUser->saldo_income = $saldoIncome;
            }
        }
        $saldoUser->save();

        $saldoRefund = (int) ($saldoUser->saldo_refund ?? 0);
        $saldoIncome = (int) ($saldoUser->saldo_income ?? 0);
        $saldoAfter = $saldoRefund + $saldoIncome;
        /* REDUCE SALDO */

        /* CREATE SALDO HISTORY */
        $saldoHistory = SaldoHistory::create([
            'user_id' => $user_id,
            'transaction_user_id' => null,
            'payment_user_id' => $payment_user_id,
            'type' => 'withdrawal',
            'price' => $price,
            'saldo_before' => $saldoBefore,
            'saldo_after' => $saldoAfter
        ]);
        $saldoHistoryId = $saldoHistory->id;
        /* CREATE SALDO HISTORY */

        return ['status' => 'success', 'saldoHistoryId' => $saldoHistoryId];
    }
}