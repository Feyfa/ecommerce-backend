<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaldoService;
use App\Services\XenditService;
use Illuminate\Http\Request;

class SaldoController extends Controller
{
    protected SaldoService $saldoService;
    protected XenditService $xenditService;
    protected PaymentService $paymentService;

    public function __construct() 
    {
        $this->saldoService = new SaldoService();
        $this->xenditService = new XenditService(config('xendit.key'));
        $this->paymentService = new PaymentService();
    }

    public function getSaldo(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET SALDO */
        $getSaldo = $this->saldoService->getSaldo($user_id);
        $status = $getSaldo['status'] ?? '';
        $saldoIncome = $getSaldo['saldoIncome'] ?? 0;
        $saldoRefund = $getSaldo['saldoRefund'] ?? 0;
        $saldoTotal = $getSaldo['saldoTotal'] ?? 0;
        if($status == 'error')
            return response()->json(['status' => 'error', 'message' => ($getSaldo['message'] ?? 'Sepertinya Ada Yang Salah')]);
        /* GET SALDO */ 

        return response()->json(['status' => 'success', 'saldoIncome' => $saldoIncome, 'saldoRefund' => $saldoRefund, 'saldoTotal' => $saldoTotal]);
    }

    public function getSaldoHistory(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VARIABLE */
        $startDate = $request->startDate ?? '';
        $endDate = $request->endDate ?? '';
        $saldo_history_current_ids = !empty($request->saldo_history_current_ids) ? json_decode($request->saldo_history_current_ids, true) : [];
        // info('', ['startDate' => $startDate,'endDate' => $endDate,'saldo_history_current_ids' => $saldo_history_current_ids,]);
        /* VARIABLE */

        /* GET SALDO HISTORY */
        $getSaldoHistory = $this->saldoService->getSaldoHistory($user_id, $startDate, $endDate, $saldo_history_current_ids);
        $status = $getSaldoHistory['status'] ?? '';
        $saldoHistory = $getSaldoHistory['saldoHistory'] ?? [];
        if($status == 'error')
            return response()->json(['status' => 'error', 'message' => ($getSaldoHistory['message'] ?? 'Sepertinya Ada Yang Salah')]);
        /* GET SALDO HISTORY */

        return response()->json(['status' => 'success', 'saldoHistory' => $saldoHistory]);
    }

    public function withdrawSaldo(Request $request)
    {
        // info(__FUNCTION__, ['all' => $request->all()]);
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION REQUEST */
        $wihtdrawMaximum = 1000000;
        $wihtdrawMaximumString = number_format($wihtdrawMaximum, 0, ',', '.');

        $paymentAccount = $request->paymentAccount ?? '';
        $wihtdrawPrice = $request->wihtdrawPrice ?? '';
        if(empty($paymentAccount) || trim($paymentAccount) == '')
            return response()->json(['status' => 'error', 'message' => 'Rekening Bank Tidak Boleh Kosong'], 400);
        if(empty($wihtdrawPrice) || trim($wihtdrawPrice) == '')
            return response()->json(['status' => 'error', 'message' => 'Nominal Tidak Boleh Kosong'], 400);
        if(!is_numeric($wihtdrawPrice))
            return response()->json(['status' => 'error', 'message' => "Format Nominal Tidak Valid"], 400);
        if($wihtdrawPrice > $wihtdrawMaximum)
            return response()->json(['status' => 'error', 'message' => "Nominal Tidak Boleh Lebih Dari Rp$wihtdrawMaximumString"], 400);
        
        $wihtdrawPriceString = number_format($wihtdrawPrice, 0, ',', '.');
        /* VALIDATION REQUEST */

        /* GET PAYMENT */
        $getPayment = $this->paymentService->getWithdrawalPayment($user_id, $paymentAccount);
        // info('GET PAYMENT', ['getPayment' => $getPayment]);
        $status = $getPayment['status'] ?? '';
        $message = $getPayment['message'] ?? '';
        $paymentUserId = isset($getPayment['payment']['id']) ? $getPayment['payment']['id'] : ''; 
        $userName = isset($getPayment['payment']['user_name']) ? $getPayment['payment']['user_name'] : '';
        $paymentSlug = isset($getPayment['payment']['payment_slug']) ? $getPayment['payment']['payment_slug'] : '';
        $paymentSlugUpper = strtoupper($paymentSlug);
        if($status == 'error') 
            return response()->json(['status' => 'error', 'message' => $message], 400);
        /* GET PAYMENT */

        /* GET SALDO USER */
        // info('GET SALDO USER');
        $getSaldo = $this->saldoService->getSaldo($user_id);
        $saldoTotal = $getSaldo['saldoTotal'] ?? 0;
        $saldoTotalString = number_format($saldoTotal, 0, ',', '.');
        if($saldoTotal == 0 || empty($saldoTotal))
            return response()->json(['status' => 'error', 'message' => "Saldo Anda Rp0 Anda Tidak Bisa Tarik Saldo"], 400);
        if($saldoTotal < $wihtdrawPrice)
            return response()->json(['status' => 'error', 'message' => "Saldo Anda Hanya Rp$saldoTotalString, Tidak Bisa Tarik Saldo Sebesar $wihtdrawPriceString"], 400);
        /* GET SALDO USER */

        /* PROCESS WITHDRAW */
        // info('PROCESS WITHDRAW');
        $disbursement = $this->xenditService->disbursement(
            external_id: "dis-$paymentSlug-" . uniqid(),
            amount: $wihtdrawPrice,
            bank_code: $paymentSlugUpper,
            account_holder_name: $userName,
            account_number: $paymentAccount,
            description: "Transfer Rekening $paymentSlugUpper Sebesar Rp$wihtdrawPriceString"
        );
        // info('', ['disbursement' => $disbursement]);
        $status = $disbursement['status'] ?? '';
        $message = $disbursement['message'] ?? '';
        if($status == 'error')
            return response()->json(['status' => 'error', 'message' => $message], 400);
        /* PROCESS WITHDRAW */

        /* SAVE SALDO AFTER DISBUSMENT */
        $saveSaldoAfterDisbursement = $this->saldoService->saveSaldoAfterDisbursement($user_id, $paymentUserId, $wihtdrawPrice);
        $saldoHistoryId = $saveSaldoAfterDisbursement['saldoHistoryId'] ?? null;
        /* SAVE SALDO AFTER DISBUSMENT */

        /* GET ONE SALDO HISTORY */
        $getSaldoById = $this->saldoService->getSaldoById($saldoHistoryId);
        $saldoHistory = $getSaldoById['saldoHistory'] ?? [];
        /* GET ONE SALDO HISTORY */

        return response()->json(['status' => 'success', 'saldoHistory' => $saldoHistory]);
    }
}
