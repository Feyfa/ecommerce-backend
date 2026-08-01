<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Mengalihkan user yang sudah terautentikasi agar tidak kembali mengakses route guest.
     *
     * Setiap guard yang diberikan diperiksa sebelum request diteruskan. User yang sudah login
     * diarahkan ke halaman utama sehingga route guest tidak dapat digunakan sebagai halaman
     * autentikasi kedua.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next  Callback middleware berikutnya pada pipeline request.
     * @param  string  $guards  Daftar guard autentikasi yang akan diperiksa.
     *
     * @return Response  Hasil proses yang telah dinormalisasi sesuai kontrak function ini.
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
