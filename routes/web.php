<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

/**
 * Menampilkan status backend dan nilai header negara yang diterima untuk diagnostik Cloudflare.
 *
 * Header yang tidak tersedia menghasilkan null; nilainya tidak membuktikan asal request Cloudflare.
 */
Route::get('/', function (Request $request): JsonResponse {
    return response()->json([
        'status' => 'ok',
        'service' => 'backend',
        'timestamp' => now()->toIso8601String(),
        'cf_ipcountry' => $request->header('CF-IPCountry'),
    ])->header('Cache-Control', 'private, no-store');
});
