<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menambahkan correlation id konsisten pada setiap HTTP request dan response.
 */
class AssignRequestId
{
    /**
     * Menetapkan correlation id valid untuk application log, audit log,
     * dan response tanpa mempercayai header bebas dari client.
     *
     * Header client hanya dipakai jika memenuhi format correlation ID yang dibatasi; selain itu server
     * membuat UUID baru. Identifier yang sama ditempelkan pada request, log context, dan response
     * untuk menelusuri satu alur end-to-end.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  Closure  $next  Callback middleware berikutnya pada pipeline request.
     *
     * @return Response  Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // --- step 1 - start - hanya terima incoming UUID valid agar header tidak menjadi data bebas
        $incomingRequestId = trim((string) $request->header('X-Request-ID', ''));
        $requestId = Str::isUuid($incomingRequestId)
            ? $incomingRequestId
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        // --- step 1 - end - hanya terima incoming UUID valid agar header tidak menjadi data bebas

        // --- step 2 - start - teruskan request lalu pantulkan correlation id pada response
        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        // --- step 2 - end - teruskan request lalu pantulkan correlation id pada response

        return $response;
    }
}
