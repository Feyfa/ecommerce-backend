<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BelanjaController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KeranjangController;
use App\Http\Controllers\MessendController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
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
    Route::get('/user/{id}', [UserController::class, 'show']);
    Route::put('/user/{id}', [UserController::class, 'updateUser']);
    Route::post('/user/image', [UserController::class, 'uploadImage']);
    Route::delete('/user/image', [UserController::class, 'deleteImage']);
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
    Route::post('/keranjang/total/plus', [KeranjangController::class, 'plusTotalKeranjang']);
    Route::post('/keranjang/total/minus', [KeranjangController::class, 'minusTotalKeranjang']);
    Route::post('/keranjang/total/change', [KeranjangController::class, 'changeTotalKeranjang']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/belanja/{user_id_seller}', [BelanjaController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post("/payment/createtokenmidtrans", [PaymentController::class, 'createTokenMidtrans']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post("/messend/gmail/send", [MessendController::class, 'sendEmail']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/transaction/{user_id_buyer}/{order_id}', [TransactionController::class, 'deleteTransaction']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/invoice', [InvoiceController::class, 'show']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/invoice/order-id-exists', [InvoiceController::class, 'checkOrderId']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/stripe/replace-ach', [PaymentController::class, 'replaceAch']);
    Route::delete('/stripe/delete-ach', [PaymentController::class, 'deleteAch']);
    Route::post('/stripe/verify-ach', [PaymentController::class, 'verifyAch']);
    Route::post('/stripe/create-ach', [PaymentController::class, 'createAch']);
    Route::get('/stripe/get-info-payment-method', [PaymentController::class, 'getInfoPaymentMethod']);
    Route::post('/stripe/create-credit-card', [PaymentController::class, 'createCreditCard']);
    Route::put('/stripe/replace-credit-card', [PaymentController::class, 'replaceCreditCard']);
    Route::get('/stripe/check-connect-account', [PaymentController::class, 'checkConnectAccountStripe']);
    Route::post('/stripe/connect-account', [PaymentController::class, 'connectAccountStripe']);
});

/* MIDTRANS WEBHOOK */
Route::prefix('invoice')->group(function () {
    Route::post('/', [InvoiceController::class, 'createInvoice']);
});
/* MIDTRANS WEBHOOK */