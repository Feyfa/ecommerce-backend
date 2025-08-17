<?php

use App\Http\Controllers\AlamatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BelanjaController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KeranjangController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MessendController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaldoController;
use App\Http\Controllers\TopupController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookStripeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tokenvalidation', [AuthController::class, 'tokenValidation']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [UserController::class, 'show']);
    Route::put('/user/{id}', [UserController::class, 'updateUser']);
    Route::put('/user/change/password', [UserController::class, 'changePassword']);
    Route::put('/user/account/type', [UserController::class, 'changeAccountType']);
    Route::post('/user/image', [UserController::class, 'uploadImage']);
    Route::delete('/user/image', [UserController::class, 'deleteImage']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'updateCompany']);
    Route::post('/company/image', [CompanyController::class, 'uploadImage']);
    Route::delete('/company/image', [CompanyController::class, 'deleteImage']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/alamat/buyer', [AlamatController::class, 'getAlamatBuyer']);
    Route::post('/alamat/buyer', [AlamatController::class, 'addAlamatBuyer']);
    Route::put('/alamat-enable/buyer/{id}', [ALamatController::class, 'setEnableAlamatBuyer']);
    Route::put('/alamat/buyer/{id}', [AlamatController::class, 'updateAlamatBuyer']);
    Route::delete('/alamat/buyer/{id}', [AlamatController::class, 'deleteAlamatBuyer']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payment', [PaymentController::class, 'getPayment']);
    Route::get('/payment/list', [PaymentController::class, 'getPaymentList']);
    Route::post('/payment/account/validate', [PaymentController::class, 'validatePaymentAccount']);
    Route::post('/payment', [PaymentController::class, 'addPayment']);
    Route::delete('/payment/{id}', [PaymentController::class, 'deletePayment']);
    Route::post('/payment/simulate/charge-virtual-account', [PaymentController::class, 'simulateChargeVirtualAccount']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/product/{user_id_seller}', [ProductController::class, 'index']);
    Route::get('/product/{user_id_seller}/{id}', [ProductController::class, 'show']);
    Route::post('/product', [ProductController::class, 'store']);
    Route::put('/product/{id}', [ProductController::class, 'update']);
    Route::delete('/product/{user_id_seller}/{id}', [ProductController::class, 'delete']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/keranjang/{user_id_buyer}', [KeranjangController::class, 'index']);
    Route::post('/keranjang', [KeranjangController::class, 'store']);
    Route::delete('/keranjang/{user_id_buyer}/{product_id}', [KeranjangController::class, 'delete']);
    Route::post('/keranjang/checked', [KeranjangController::class, 'checked']);
    Route::post('/keranjang/checked/group', [KeranjangController::class, 'checkedGroup']);
    Route::post('/keranjang/total/plus', [KeranjangController::class, 'plusTotalKeranjang']);
    Route::post('/keranjang/total/minus', [KeranjangController::class, 'minusTotalKeranjang']);
    Route::post('/keranjang/total/change', [KeranjangController::class, 'changeTotalKeranjang']);
    Route::post('/keranjang/validate/checkout', [KeranjangController::class, 'validateCheckout']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/belanja/{user_id_seller}', [BelanjaController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/invoice', [InvoiceController::class, 'show']); // sudah tidak dipakai
    Route::get('/transaction', [TransactionController::class, 'getTransaction']);
    Route::post('/transaction/approved', [TransactionController::class, 'approvedTransaction']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/checkout/data', [CheckoutController::class, 'getDataCheckout']);
    Route::post('/checkout/process', [CheckoutController::class, 'processCheckout']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/saldo', [SaldoController::class, 'getSaldo']);
    Route::get('/saldo-history', [SaldoController::class, 'getSaldoHistory']);
    Route::post('/saldo-withdraw', [SaldoController::class, 'withdrawSaldo']);
});