<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends Controller
{
    protected CheckoutService $checkoutService; 
    protected XenditService $xenditService;
    protected PaymentService $paymentService;
    
    public function __construct() 
    {
        $this->checkoutService = new CheckoutService();
        $this->xenditService = new XenditService(config('xendit.key'));
        $this->paymentService = new PaymentService();
    }

    public function getDataCheckout()
    {
        /* VALIDATION USER */
        $user_id_buyer = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id_buyer)->exists();

        if(!$userExists)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET ALAMAT */
        $getAlamatBuyer = $this->checkoutService->getAlamatBuyer($user_id_buyer);
        $alamat = $getAlamatBuyer['alamat'];

        if(empty($alamat))
            return response()->json(['status' => 'error', 'message' => 'Alamat belum ditambahkan'], 400);
        /* GET ALAMAT */

        /* GET DATA CHECKOUT */
        $getKeranjangCheckout = $this->checkoutService->getKeranjangCheckout($user_id_buyer);
        $checkouts = $getKeranjangCheckout['checkouts'];
        $totalPrice = $getKeranjangCheckout['totalPrice'];

        if(count($checkouts) == 0)
            return response()->json(['status' => 'error', 'message' => 'Keranjang Not Checked'], 400);
        /* GET DATA CHECKOUT */

        /* GET PAYMENT */
        $getCheckoutPayment = $this->paymentService->getCheckoutPayment();
        $payments = $getCheckoutPayment['payments'];

        if(count($payments) == 0)
            return response()->json(['status' => 'error', 'message' => 'Payment List Empty'], 400);
        /* GET PAYMENT */

        return response()->json([
            'status' => 'success',
            'alamat' => $alamat,
            'checkouts' => $checkouts,
            'payments' => $payments,
            'totalPrice' => $totalPrice
        ]);
    }

    public function processCheckout(Request $request)
    {
        /* VALIDATION USER */
        $user_id_buyer = optional(auth()->user())->id;
        $user = User::where('id', $user_id_buyer)->first();

        if(!$user)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION REQUEST */
        $validator = Validator::make($request->all(), [
            'payment_slug' => ['required'],
            'shipping_options' => ['required', 'array'],
            'shipping_options.*.user_id_seller' => ['required'],
            'shipping_options.*.kurir_name' => ['required'],
            'noteds' => ['required', 'array'],
            'client_snapshot' => ['required', 'array'],
            'client_snapshot.cart_item_ids' => ['required', 'array'],
            'client_snapshot.total_product' => ['required', 'numeric'],
            'client_snapshot.total_shipping' => ['required', 'numeric'],
            'client_snapshot.total_all' => ['required', 'numeric'],
        ]);

        if($validator->fails())
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATION REQUEST */

        /* BUILD BACKEND CHECKOUT SNAPSHOT */
        $checkoutSnapshot = $this->checkoutService->buildCheckoutSnapshot(
            user_id_buyer: $user_id_buyer,
            shippingOptions: $request->shipping_options ?? [],
            noteds: $request->noteds ?? [],
            paymentSlug: $request->payment_slug ?? "",
        );

        if(($checkoutSnapshot['status'] ?? "") == 'invalid') {
            return response()->json([
                'status' => 'error',
                'code' => $checkoutSnapshot['code'] ?? 'CHECKOUT_INVALID',
                'message' => $checkoutSnapshot['message'] ?? 'Keranjang berubah, silakan cek ulang',
            ], 409);
        }

        if(($checkoutSnapshot['status'] ?? "") == 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $checkoutSnapshot['message'] ?? 'Checkout tidak valid',
            ], 400);
        }

        if($this->checkoutService->checkoutSnapshotChanged($checkoutSnapshot, $request->client_snapshot ?? [])) {
            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_CHANGED',
                'message' => 'Checkout berubah, silakan cek ulang sebelum membayar',
                'checkout' => $this->checkoutService->formatCheckoutSnapshotForFrontend($checkoutSnapshot),
            ], 409);
        }
        /* BUILD BACKEND CHECKOUT SNAPSHOT */

        $checkoutKey = $this->checkoutService->generateCheckoutKey(
            user_id_buyer: $user_id_buyer,
            checkoutSnapshot: $checkoutSnapshot,
        );

        $this->checkoutService->lockCheckoutKey($checkoutKey);

        try {
            $existingCheckoutInvoice = $this->checkoutService->getExistingCheckoutInvoice(
                user_id_buyer: $user_id_buyer,
                checkout_key: $checkoutKey,
            );

            if(!empty($existingCheckoutInvoice)) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'CHECKOUT_ALREADY_PROCESSED',
                    'message' => 'Checkout ini sudah diproses, silakan cek transaksi Anda',
                ], 409);
            }

            /* CREATE PAYMENT IN XENDIT */
            $resultXendit = [];
            $now = Carbon::now()->timestamp;
            $uniqid = uniqid();
            $external_id = "";
            $bank_code = "";
            $name = $user->name ?? "";
            $country = "ID";
            $currency = "IDR";
            $is_single_use = true;
            $is_closed = true;
            $expected_amount = intval($checkoutSnapshot['clientComparable']['total_all'] ?? 0);

            $expired_at = Carbon::now()->addDay()->setMinutes(0)->setSeconds(0)->setMicroseconds(0);
            $expired_at_xendit = $expired_at->toIso8601String();
            $expired_at_transaction = $expired_at->format('Y-m-d H:i:s');

            if (($checkoutSnapshot['data']['payment']['method'] ?? "") == 'va' && ($checkoutSnapshot['data']['payment']['slug'] ?? "") == 'bca' && ($checkoutSnapshot['data']['payment']['name'] ?? "") == 'BCA Virtual Account') {
                $external_id = "{$checkoutSnapshot['data']['payment']['method']}-{$checkoutSnapshot['data']['payment']['slug']}-{$user_id_buyer}-{$now}-{$uniqid}";
                $bank_code = "BCA";

                $resultXendit = $this->xenditService->createVirtualAccount(
                    external_id: $external_id,
                    bank_code: $bank_code,
                    name: $name,
                    country: $country,
                    currency: $currency,
                    is_single_use: $is_single_use,
                    is_closed: $is_closed,
                    expected_amount: $expected_amount,
                    expiration_date: $expired_at_xendit
                );
            } else {
                return response()->json(['status' => 'error', 'message' => 'Pembayaran Harus Menggunakan BCA Virtual Account'], 400);
            }

            if ($resultXendit['status'] == 'error') {
                return response()->json(['status' => $resultXendit['status'], 'message' => $resultXendit['message']], 400);
            }
            /* CREATE PAYMENT IN XENDIT */

            /* SAVE CHECKOUT TO DATABASE */
            try {
                DB::transaction(function () use ($user_id_buyer, $checkoutSnapshot, $expired_at_transaction, $resultXendit, $checkoutKey) {
                    $saveCheckoutToDatabase = $this->checkoutService->saveCheckoutToDatabase(
                        user_id_buyer: $user_id_buyer,
                        checkouts: $checkoutSnapshot['data']['checkouts'] ?? [],
                        kurirs: $checkoutSnapshot['data']['kurirs'] ?? [],
                        noteds: $checkoutSnapshot['data']['noteds'] ?? [],
                        alamat: $checkoutSnapshot['data']['alamat']['alamat'] ?? "",
                        payment_method: $checkoutSnapshot['data']['payment']['method'] ?? "",
                        payment_slug: $checkoutSnapshot['data']['payment']['slug'] ?? "",
                        payment_name: $checkoutSnapshot['data']['payment']['name'] ?? "",
                        expired_at: $expired_at_transaction,
                        price: intval($checkoutSnapshot['clientComparable']['total_all'] ?? 0),
                        checkout_key: $checkoutKey,
                        dataXendit: $resultXendit['data'] ?? []
                    );

                    if($saveCheckoutToDatabase['status'] == 'error') {
                        throw new \RuntimeException($saveCheckoutToDatabase['message'] ?? 'Save checkout failed');
                    }

                    $deleteKeranjangAfterCheckout = $this->checkoutService->deleteKeranjangAfterCheckoutForBuyer(
                        user_id_buyer: $user_id_buyer,
                        checkouts: $checkoutSnapshot['data']['checkouts'] ?? []
                    );

                    if($deleteKeranjangAfterCheckout['status'] == 'error') {
                        throw new \RuntimeException($deleteKeranjangAfterCheckout['message'] ?? 'Delete keranjang failed');
                    }

                    $changeStockProductAfterCheckout = $this->checkoutService->changeStockProductAfterCheckout(
                        checkouts: $checkoutSnapshot['data']['checkouts'] ?? []
                    );

                    if($changeStockProductAfterCheckout['status'] == 'error') {
                        throw new \RuntimeException($changeStockProductAfterCheckout['message'] ?? 'Change stock product failed');
                    }
                });
            } catch (\RuntimeException $e) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
            }
            /* SAVE CHECKOUT TO DATABASE */
        } finally {
            $this->checkoutService->unlockCheckoutKey($checkoutKey);
        }

        return response()->json(['status' => 'success', 'message' => 'Pembayaran Berhasil']);
    }
}
