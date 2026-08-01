<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutAvailabilityException;
use App\Exceptions\CheckoutChangedException;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CheckoutController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan checkout dan ketersediaan produk.
     *
     * @param  CheckoutService  $checkoutService  Layanan penyusunan checkout.
     * @param  XenditService  $xenditService  Layanan integrasi pembayaran Xendit.
     * @param  PaymentService  $paymentService  Layanan metode pembayaran.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected CheckoutService $checkoutService,
        protected XenditService $xenditService,
        protected PaymentService $paymentService,
    ) {}

    /**
     * Mengambil data checkout terkini untuk buyer yang terautentikasi.
     *
     * Function merekonsiliasi cart checkout, memverifikasi alamat buyer serta seller, lalu menyusun
     * snapshot harga, kurir, catatan, dan metode pembayaran dari database. Snapshot dan checkout key
     * dikembalikan agar frontend dapat mendeteksi perubahan sebelum pembayaran.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getDataCheckout(): JsonResponse
    {
        // --- step 1 - start - validasi buyer terautentikasi
        $user_id_buyer = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id_buyer)->exists();

        if (! $userExists) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi buyer terautentikasi

        // --- step 2 - start - sinkronkan availability item checkout
        $cartState = $this->checkoutService->reconcileCheckoutCart($user_id_buyer);
        $availabilityError = $this->checkoutService->checkoutAvailabilityError($cartState);

        if ($availabilityError !== null) {
            return response()->json([
                'status' => 'error',
                ...$availabilityError,
            ], 409);
        }
        // --- step 2 - end - sinkronkan availability item checkout

        // --- step 3 - start - validasi alamat aktif buyer
        $getAlamatBuyer = $this->checkoutService->getAlamatBuyer($user_id_buyer);
        $alamat = $getAlamatBuyer['alamat'];

        if (empty($alamat)) {
            return response()->json(['status' => 'error', 'message' => 'Alamat belum ditambahkan'], 400);
        }

        if (! $this->checkoutService->isAddressVerified($alamat)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Alamat pengiriman perlu diverifikasi dengan pinpoint sebelum melanjutkan checkout.',
            ], 409);
        }
        // --- step 3 - end - validasi alamat aktif buyer

        // --- step 4 - start - ambil dan validasi kelompok item checkout
        $getKeranjangCheckout = $this->checkoutService->getKeranjangCheckout($user_id_buyer);
        $checkouts = $getKeranjangCheckout['checkouts'];
        $totalPrice = $getKeranjangCheckout['totalPrice'];

        if (count($checkouts) == 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Keranjang Not Checked',
            ], 409);
        }

        if ($this->checkoutService->hasUnverifiedSellerAddress($checkouts)) {
            return response()->json([
                'status' => 'error',
                'code' => 'SELLER_ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Lokasi toko penjual belum diverifikasi. Checkout belum dapat dilanjutkan.',
            ], 409);
        }
        // --- step 4 - end - ambil dan validasi kelompok item checkout

        // --- step 5 - start - ambil metode pembayaran checkout
        $getCheckoutPayment = $this->paymentService->getCheckoutPayment();
        $payments = $getCheckoutPayment['payments'];

        if (count($payments) == 0) {
            return response()->json(['status' => 'error', 'message' => 'Payment List Empty'], 400);
        }
        // --- step 5 - end - ambil metode pembayaran checkout

        return response()->json([
            'status' => 'success',
            'alamat' => $alamat,
            'checkouts' => $checkouts,
            'payments' => $payments,
            'totalPrice' => $totalPrice,
        ]);
    }

    /**
     * Memvalidasi ulang dan memproses pembayaran checkout buyer.
     *
     * Payload frontend dibandingkan dengan snapshot backend terbaru setelah cart dan produk dikunci
     * serta divalidasi ulang. Virtual account dibuat sebelum invoice dan transaksi disimpan; kegagalan
     * availability membuka lock dan mengembalikan state yang perlu dimuat ulang tanpa membuat pesanan
     * parsial.
     *
     * @param  Request  $request  Data pembayaran dan snapshot checkout.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function processCheckout(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi buyer terautentikasi
        $user_id_buyer = optional(auth()->user())->id;
        $user = User::where('id', $user_id_buyer)->first();

        if (! $user) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi buyer terautentikasi

        // --- step 2 - start - validasi payload checkout
        $validator = Validator::make($request->all(), [
            'payment_slug' => ['required'],
            'shipping_options' => ['required', 'array'],
            'shipping_options.*.user_id_seller' => ['required'],
            'shipping_options.*.kurir_name' => ['required'],
            'noteds' => ['required', 'array'],
            'client_snapshot' => ['required', 'array'],
            'client_snapshot.alamat_id' => ['required', 'string'],
            'client_snapshot.alamat_updated_at' => ['required', 'string'],
            'client_snapshot.cart_item_ids' => ['required', 'array'],
            'client_snapshot.total_product' => ['required', 'numeric'],
            'client_snapshot.total_shipping' => ['required', 'numeric'],
            'client_snapshot.total_all' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        // --- step 2 - end - validasi payload checkout

        // --- step 3 - start - bangun dan bandingkan snapshot checkout backend
        $checkoutSnapshot = $this->checkoutService->buildCheckoutSnapshot(
            user_id_buyer: $user_id_buyer,
            shippingOptions: $request->shipping_options ?? [],
            noteds: $request->noteds ?? [],
            paymentSlug: $request->payment_slug ?? '',
        );

        if (($checkoutSnapshot['status'] ?? '') == 'invalid') {
            return response()->json([
                'status' => 'error',
                'code' => $checkoutSnapshot['code'] ?? 'CHECKOUT_INVALID',
                'message' => $checkoutSnapshot['message'] ?? 'Keranjang berubah, silakan cek ulang',
            ], 409);
        }

        if (($checkoutSnapshot['status'] ?? '') == 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $checkoutSnapshot['message'] ?? 'Checkout tidak valid',
            ], 400);
        }

        if ($this->checkoutService->checkoutSnapshotChanged($checkoutSnapshot, $request->client_snapshot ?? [])) {
            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_CHANGED',
                'message' => 'Checkout berubah, silakan cek ulang sebelum membayar',
                'checkout' => $this->checkoutService->formatCheckoutSnapshotForFrontend($checkoutSnapshot),
            ], 409);
        }
        // --- step 3 - end - bangun dan bandingkan snapshot checkout backend

        $checkoutKey = '';

        try {
            // --- step 4 - start - kunci proses checkout yang sama
            $checkoutKey = $this->checkoutService->generateCheckoutKey(
                user_id_buyer: $user_id_buyer,
                checkoutSnapshot: $checkoutSnapshot,
            );
            $this->checkoutService->lockCheckoutKey($checkoutKey);
            // --- step 4 - end - kunci proses checkout yang sama

            // --- step 5 - start - cegah checkout yang sudah diproses
            $existingCheckoutInvoice = $this->checkoutService->getExistingCheckoutInvoice(
                user_id_buyer: $user_id_buyer,
                checkout_key: $checkoutKey,
            );

            if (! empty($existingCheckoutInvoice)) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'CHECKOUT_ALREADY_PROCESSED',
                    'message' => 'Checkout ini sudah diproses, silakan cek transaksi Anda',
                ], 409);
            }
            // --- step 5 - end - cegah checkout yang sudah diproses

            // --- step 6 - start - siapkan data payment
            $now = Carbon::now()->timestamp;
            $uniqid = uniqid();
            $external_id = "{$checkoutSnapshot['data']['payment']['method']}-{$checkoutSnapshot['data']['payment']['slug']}-{$user_id_buyer}-{$now}-{$uniqid}";
            $bank_code = 'BCA';
            $name = $user->name ?? '';
            $country = 'ID';
            $currency = 'IDR';
            $is_single_use = true;
            $is_closed = true;
            $expected_amount = intval($checkoutSnapshot['clientComparable']['total_all'] ?? 0);

            $expired_at = Carbon::now()->addDay()->setMinutes(0)->setSeconds(0)->setMicroseconds(0);
            $expired_at_xendit = $expired_at->toIso8601String();
            $expired_at_transaction = $expired_at->format('Y-m-d H:i:s');
            // --- step 6 - end - siapkan data payment

            // --- step 7 - start - proses checkout secara atomik
            DB::transaction(function () use (
                $user_id_buyer,
                $checkoutSnapshot,
                $expired_at_transaction,
                $checkoutKey,
                $external_id,
                $bank_code,
                $name,
                $country,
                $currency,
                $is_single_use,
                $is_closed,
                $expected_amount,
                $expired_at_xendit,
            ) {
                // Data dikunci dan diverifikasi lagi karena seller dapat mengubah produk
                // setelah buyer membuka halaman checkout atau setelah snapshot awal dibuat.
                $this->checkoutService->lockAndValidateCheckoutItems(
                    $user_id_buyer,
                    $checkoutSnapshot,
                );

                // Payment baru dibuat setelah data checkout lolos validasi row-lock,
                // sehingga item invalid tidak menghasilkan virtual account yatim.
                $resultXendit = $this->xenditService->createVirtualAccount(
                    external_id: $external_id,
                    bank_code: $bank_code,
                    name: $name,
                    country: $country,
                    currency: $currency,
                    is_single_use: $is_single_use,
                    is_closed: $is_closed,
                    expected_amount: $expected_amount,
                    expiration_date: $expired_at_xendit,
                );

                if (($resultXendit['status'] ?? 'error') === 'error') {
                    throw new RuntimeException($resultXendit['message'] ?? 'Payment creation failed');
                }

                $saveCheckoutToDatabase = $this->checkoutService->saveCheckoutToDatabase(
                    user_id_buyer: $user_id_buyer,
                    checkouts: $checkoutSnapshot['data']['checkouts'] ?? [],
                    kurirs: $checkoutSnapshot['data']['kurirs'] ?? [],
                    noteds: $checkoutSnapshot['data']['noteds'] ?? [],
                    alamat_buyer: $checkoutSnapshot['data']['alamat'] ?? null,
                    payment_method: $checkoutSnapshot['data']['payment']['method'] ?? '',
                    payment_slug: $checkoutSnapshot['data']['payment']['slug'] ?? '',
                    payment_name: $checkoutSnapshot['data']['payment']['name'] ?? '',
                    expired_at: $expired_at_transaction,
                    price: intval($checkoutSnapshot['clientComparable']['total_all'] ?? 0),
                    checkout_key: $checkoutKey,
                    dataXendit: $resultXendit['data'] ?? []
                );

                if ($saveCheckoutToDatabase['status'] == 'error') {
                    throw new RuntimeException($saveCheckoutToDatabase['message'] ?? 'Save checkout failed');
                }

                $deleteKeranjangAfterCheckout = $this->checkoutService->deleteKeranjangAfterCheckoutForBuyer(
                    user_id_buyer: $user_id_buyer,
                    checkouts: $checkoutSnapshot['data']['checkouts'] ?? []
                );

                if ($deleteKeranjangAfterCheckout['status'] == 'error') {
                    throw new RuntimeException($deleteKeranjangAfterCheckout['message'] ?? 'Delete keranjang failed');
                }

                $changeStockProductAfterCheckout = $this->checkoutService->changeStockProductAfterCheckout(
                    checkouts: $checkoutSnapshot['data']['checkouts'] ?? []
                );

                if ($changeStockProductAfterCheckout['status'] == 'error') {
                    throw new CheckoutAvailabilityException(
                        $changeStockProductAfterCheckout['message'] ?? 'Change stock product failed',
                        $this->checkoutService->checkoutCartIds(
                            checkouts: $checkoutSnapshot['data']['checkouts'] ?? [],
                        ),
                    );
                }
            });
            // --- step 7 - end - proses checkout secara atomik
        } catch (CheckoutAvailabilityException $e) {
            // Transaksi database sudah rollback pada titik ini, sehingga perubahan cart
            // disimpan terpisah dan tidak ikut dibatalkan bersama pesanan.
            $this->checkoutService->uncheckCheckoutItems($user_id_buyer, $e->cartIds);

            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_INVALID',
                'message' => $e->getMessage(),
            ], 409);
        } catch (CheckoutChangedException $e) {
            $currentSnapshot = $this->checkoutService->buildCheckoutSnapshot(
                user_id_buyer: $user_id_buyer,
                shippingOptions: $request->shipping_options ?? [],
                noteds: $request->noteds ?? [],
                paymentSlug: $request->payment_slug ?? '',
            );

            if (($currentSnapshot['status'] ?? '') !== 'success') {
                return response()->json([
                    'status' => 'error',
                    'code' => $currentSnapshot['code'] ?? 'CHECKOUT_INVALID',
                    'message' => $currentSnapshot['message'] ?? 'Checkout tidak lagi dapat diproses',
                ], 409);
            }

            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_CHANGED',
                'message' => $e->getMessage(),
                'checkout' => $this->checkoutService->formatCheckoutSnapshotForFrontend($currentSnapshot),
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } finally {
            if ($checkoutKey !== '') {
                $this->checkoutService->unlockCheckoutKey($checkoutKey);
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Pembayaran Berhasil']);
    }
}
