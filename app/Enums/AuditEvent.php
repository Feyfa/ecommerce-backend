<?php

namespace App\Enums;

/**
 * Daftar event audit autentikasi yang didukung pada phase 1.
 */
enum AuditEvent: string
{
    case AUTH_REGISTERED = 'auth.registered';
    case AUTH_LOGGED_IN = 'auth.logged_in';
    case AUTH_LOGGED_OUT = 'auth.logged_out';

    /**
     * Tujuan method ini untuk menjaga title user-facing tetap konsisten
     * tanpa menyimpan copy text presentasi di setiap row audit.
     */
    public function title(): string
    {
        return match ($this) {
            self::AUTH_REGISTERED => 'Akun berhasil dibuat',
            self::AUTH_LOGGED_IN => 'Login',
            self::AUTH_LOGGED_OUT => 'Logout',
        };
    }

    /**
     * Tujuan method ini untuk menyediakan deskripsi aman yang tidak
     * mengarang metode autentikasi ketika Clerk tidak mengirim datanya.
     */
    public function description(): string
    {
        return match ($this) {
            self::AUTH_REGISTERED => 'Akun Anda berhasil dibuat.',
            self::AUTH_LOGGED_IN => 'Akun Anda berhasil login.',
            self::AUTH_LOGGED_OUT => 'Anda keluar dari akun pada perangkat ini.',
        };
    }

    /**
     * Tujuan method ini untuk menyediakan label pendek pada filter dan badge UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::AUTH_REGISTERED => 'Register',
            self::AUTH_LOGGED_IN => 'Login',
            self::AUTH_LOGGED_OUT => 'Logout',
        };
    }
}
