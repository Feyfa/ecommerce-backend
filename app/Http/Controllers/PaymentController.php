<?php

namespace App\Http\Controllers;

use App\Models\PaymentList;
use App\Models\PaymentUser;
use App\Models\TransactionInvoice;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;
    protected XenditService $xenditService;

    public function __construct() 
    {
        $this->paymentService = new PaymentService();
        $this->xenditService = new XenditService(config('xendit.key'));
    }

    public function getPayment(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* PROCESS GET PAYMENT */
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $request->searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        /* PROCESS GET PAYMENT */

        return response()->json(['status' => 'success', 'payments' => $payments]);
    }

    public function getPaymentList()
    {
        /* GET PAYMENT LIST */
        $paymentList = PaymentList::select('id','slug','name')
                                  ->where('type', 'withdrawal')
                                  ->get();
        /* GET PAYMENT LIST */

        return response()->json(['status' => 'success', 'paymentList' => $paymentList]);
    }

    public function validatePaymentAccount(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION PAYMENT ACCOUNT */
        if(empty($request->paymentAccount) || trim($request->paymentAccount) == '')
            return response()->json(['status' => 'error', 'message' => 'Nomor Rekening Tidak Boleh Kosong'], 400);
        /* VALIDATION PAYMENT ACCOUNT */

        /* VALIDATION PAYMENT SLUG */
        $slugs = PaymentList::distinct()
                            ->pluck('slug')
                            ->toArray();

        if(empty($request->paymentSlug) || trim($request->paymentSlug) == '')
            return response()->json(['status' => 'error', 'message' => 'Payment Slug Empty'], 400);
        else if(!in_array($request->paymentSlug, $slugs))
            return response()->json(['status' => 'error', 'message' => "Nama Bank {$request->paymentSlug} Tidak Tersedia"], 400);
        /* VALIDATION PAYMENT SLUG */

        /* CHECK DUPLICATE ACCOUNT */
        $paymentExists = PaymentUser::join('payment_lists', 'payment_lists.id', '=', 'payment_users.payment_id')
                                    ->where('payment_users.user_id', $user_id)  
                                    ->where('payment_users.account', $request->paymentAccount)
                                    ->where('payment_lists.slug', $request->paymentSlug)
                                    ->exists();
        if($paymentExists)
            return response()->json(['status' => 'error', 'message' => 'Nomor Rekening Sudah Digunakan'], 400);
        /* CHECK DUPLICATE ACCOUNT */

        /* GENERATE FAKE NAME */
        $generateFakeUser = $this->paymentService->generateFakeUser();
        $name = $generateFakeUser['user']['name'] ?? "";
        /* GENERATE FAKE NAME */

        return response()->json(['status' => 'success', 'username' => $name]);
    }

    public function addPayment(Request $request)
    {
        /* VALIDATION USER ID */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER ID */

        /* VALIDATION REQUEST */
        $validator = Validator::make($request->all(), [
            'paymentName' => ['required'],
            'paymentSlug' => ['required'],
            'paymentAccount' => ['required'],
            'paymentUsername' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATION REQUEST */

        /* VALIDATION MAX PAYMENT 4 */
        $totalPayment = PaymentUser::where('user_id', $user_id)
                                   ->count();

        if($totalPayment >= 10)
            return response()->json(['status' => 'error', 'message' => 'Rekening Tidak Boleh Lebih Dari 10'], 400);
        /* VALIDATION MAX PAYMENT 4 */

        /* VALIDATION PAYMENT NAME AND PAYMENT SLUG */
        $paymentList = PaymentList::where('type', 'withdrawal')
                                  ->where('slug', $request->paymentSlug)
                                  ->where('name', $request->paymentName)
                                  ->first();
            
        if(!$paymentList)
            return response()->json(['status' => 'error', 'message' => 'Tipe Rekening Bank Tidak Tersedia'], 400);
        /* VALIDATION PAYMENT NAME AND PAYMENT SLUG */

        /* CREATE PAYMENT USERS */
        PaymentUser::create([
            'user_id' => $user_id,
            'payment_id' => ($paymentList->id ?? null),
            'name' => $request->paymentUsername,
            'account' => $request->paymentAccount
        ]);
        /* CREATE PAYMENT USERS */

        /* PROCESS GET PAYMENT */
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $request->searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        /* PROCESS GET PAYMENT */

        return response()->json(['status' => 'success', 'payments' => $payments, 'message' => 'Rekening Berhasil Ditambah']);
    }

    public function deletePayment(string $id = "", Request $request)
    {
        /* VALIDATION USER ID */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER ID */

        /* VALIDATION PAYMENT AND DELETE */
        $paymentUser = PaymentUser::where('id', $id)
                                  ->where('user_id', $user_id)
                                  ->first();
                        
        if(!$paymentUser) 
            return response()->json(['status' => 'error', 'message' => 'Data Rekening Tidak Ditemukan'], 400);
        
        $paymentUser->delete();
        /* VALIDATION PAYMENT AND DELETE */

        /* PROCESS GET PAYMENT */
        $getWithdrawalPayments = $this->paymentService->getWithdrawalPayments(
            user_id: $user_id,
            search: $request->searchPayment,
        );
        $payments = $getWithdrawalPayments['payments'];
        /* PROCESS GET PAYMENT */

        return response()->json(['status' => 'success', 'payments' => $payments, 'message' => 'Rekening Berhasil Dihapus']);
    }

    public function simulateChargeVirtualAccount(Request $request)
    {
        // info(__FUNCTION__, ['all' => $request->all()]);
        /* VALIDATION USER ID */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER ID */

        /* VALIDATION REQUEST */
        if(empty($request->payment_slug) || trim($request->payment_slug) == '')
            return response()->json(['status' => 'error', 'message' => 'Nama Bank Harus Dipilih'], 400);
        if(empty($request->payment_account) || trim($request->payment_account) == '')
            return response()->json(['status' => 'error', 'message' => 'Nomor Virtual Account Harus Dipilih'], 400);
        /* VALIDATION REQUEST */

        /* VALIDATION VIRTUAL ACCOUNT IS HAVE THIS USER */
        $now = Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s');
        $transactionInvoice = TransactionInvoice::where('user_id_buyer', $user_id)
                                                ->where('payment_account', $request->payment_account)
                                                ->where('payment_slug', $request->payment_slug)
                                                ->where('payment_method', 'va')
                                                ->where('status', 'pending')
                                                ->first();
        // info(__FUNCTION__, ['transactionInvoice' => $transactionInvoice]);
        if(empty($transactionInvoice))
            return response()->json(['status' => 'error', 'message' => 'Nomor Virtual Account Tidak Ditemukan'], 400);
        if($transactionInvoice->expired_at <= $now) 
            return response()->json(['status' => 'error', 'message' => 'Nomor Virtual Account Ini Sudah Expired'], 400); 
        /* VALIDATION VIRTUAL ACCOUNT IS HAVE THIS USER */
        
        /* PROCESS CHARGE VIRTUAL ACCOUNT */
        $simulateVirtualAccountFixed = $this->xenditService->simulateVirtualAccountFixed(
            external_id: $transactionInvoice->payment_reference ?? "",
            amount: $transactionInvoice->price ?? 0
        );
        // info(__FUNCTION__, ['simulateVirtualAccountFixed' => $simulateVirtualAccountFixed]);
        if($simulateVirtualAccountFixed['status'] == 'error')
            return response()->json(['status' => 'error', 'message' => $simulateVirtualAccountFixed['message']], 400);
        /* PROCESS CHARGE VIRTUAL ACCOUNT */

        /* UPDATE STATUS TRANSACTION INVOICES */
        $transactionInvoice->status = 'done';
        $transactionInvoice->save();
        /* UPDATE STATUS TRANSACTION INVOICES */

        return response()->json(['status' => 'success', 'message' => 'Success Charge Virtual Account']);
    }
}
