<?php

namespace App\Enums;

/**
 * Daftar event audit user-facing yang didukung aplikasi.
 */
enum AuditEvent: string
{
    case AUTH_REGISTERED = 'auth.registered';
    case AUTH_LOGGED_IN = 'auth.logged_in';
    case AUTH_LOGGED_OUT = 'auth.logged_out';
    case PRODUCT_CREATED = 'product.created';
    case PRODUCT_UPDATED = 'product.updated';
    case PRODUCT_DELETED = 'product.deleted';
    case ADDRESS_CREATED = 'address.created';
    case ADDRESS_UPDATED = 'address.updated';
    case ADDRESS_DELETED = 'address.deleted';
    case ADDRESS_SELECTED = 'address.selected';

    /**
     * Tujuan method ini untuk menjaga title user-facing tetap konsisten
     * tanpa menyimpan copy text presentasi di setiap row audit.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    public function title(): string
    {
        return match ($this) {
            self::AUTH_REGISTERED => 'Akun Berhasil Dibuat',
            self::AUTH_LOGGED_IN => 'Login',
            self::AUTH_LOGGED_OUT => 'Logout',
            self::PRODUCT_CREATED => 'Produk Ditambahkan',
            self::PRODUCT_UPDATED => 'Produk Diperbarui',
            self::PRODUCT_DELETED => 'Produk Dihapus',
            self::ADDRESS_CREATED => 'Alamat Ditambahkan',
            self::ADDRESS_UPDATED => 'Alamat Diperbarui',
            self::ADDRESS_DELETED => 'Alamat Dihapus',
            self::ADDRESS_SELECTED => 'Alamat Dipilih',
        };
    }

    /**
     * Tujuan method ini untuk menyediakan deskripsi aman yang tidak
     * mengarang metode autentikasi ketika Clerk tidak mengirim datanya.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    public function description(): string
    {
        return match ($this) {
            self::AUTH_REGISTERED => 'Akun Anda berhasil dibuat.',
            self::AUTH_LOGGED_IN => 'Akun Anda berhasil login.',
            self::AUTH_LOGGED_OUT => 'Anda keluar dari akun pada perangkat ini.',
            self::PRODUCT_CREATED => 'Produk berhasil ditambahkan.',
            self::PRODUCT_UPDATED => 'Produk berhasil diperbarui.',
            self::PRODUCT_DELETED => 'Produk berhasil dihapus.',
            self::ADDRESS_CREATED => 'Alamat pengiriman berhasil ditambahkan.',
            self::ADDRESS_UPDATED => 'Alamat pengiriman berhasil diperbarui.',
            self::ADDRESS_DELETED => 'Alamat pengiriman berhasil dihapus.',
            self::ADDRESS_SELECTED => 'Alamat pengiriman utama berhasil diubah.',
        };
    }

    /**
     * Tujuan method ini untuk menyediakan label pendek pada filter dan badge UI.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    public function label(): string
    {
        return match ($this) {
            self::AUTH_REGISTERED => 'Register',
            self::AUTH_LOGGED_IN => 'Login',
            self::AUTH_LOGGED_OUT => 'Logout',
            self::PRODUCT_CREATED => 'Produk Ditambahkan',
            self::PRODUCT_UPDATED => 'Produk Diperbarui',
            self::PRODUCT_DELETED => 'Produk Dihapus',
            self::ADDRESS_CREATED => 'Alamat Ditambahkan',
            self::ADDRESS_UPDATED => 'Alamat Diperbarui',
            self::ADDRESS_DELETED => 'Alamat Dihapus',
            self::ADDRESS_SELECTED => 'Alamat Dipilih',
        };
    }

    /**
     * Mengelompokkan event agar persistence dan presentasi tidak perlu
     * mengulang pemetaan category di setiap caller.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    public function category(): string
    {
        return match ($this) {
            self::AUTH_REGISTERED,
            self::AUTH_LOGGED_IN,
            self::AUTH_LOGGED_OUT => 'authentication',
            self::PRODUCT_CREATED,
            self::PRODUCT_UPDATED,
            self::PRODUCT_DELETED => 'product',
            self::ADDRESS_CREATED,
            self::ADDRESS_UPDATED,
            self::ADDRESS_DELETED,
            self::ADDRESS_SELECTED => 'address',
        };
    }
}
