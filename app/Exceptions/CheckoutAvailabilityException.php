<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutAvailabilityException extends RuntimeException
{
    /**
     * Membuat exception ketersediaan checkout beserta daftar cart yang menyebabkan proses ditolak.
     *
     * Daftar ID dipertahankan agar caller dapat melepas item bermasalah dari pilihan checkout dan
     * mengembalikan state yang dapat diperbaiki buyer tanpa kehilangan quantity.
     *
     * @param  string  $message  Pesan kegagalan yang menjelaskan alasan operasi dihentikan.
     * @param  array<int, string>  $cartIds  Daftar ID cart yang akan direkonsiliasi atau diperbarui.
     *
     * @return void  Tidak mengembalikan nilai; message dan daftar cart disimpan pada exception.
     */
    public function __construct(
        string $message,
        public readonly array $cartIds = [],
    ) {
        parent::__construct($message);
    }
}
