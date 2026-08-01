<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Menandai perubahan state checkout setelah snapshot awal dibuat tetapi sebelum pembayaran diproses.
 *
 * Exception ini membatalkan transaksi database sebelum virtual account dibuat, lalu memungkinkan
 * controller mengirim snapshot terbaru agar buyer dapat meninjau ulang perubahan tersebut.
 */
class CheckoutChangedException extends RuntimeException
{
    /**
     * Membuat exception perubahan checkout dengan pesan yang aman ditampilkan kepada buyer.
     *
     * @param  string  $message  Pesan yang menjelaskan bahwa checkout harus dimuat dan dikonfirmasi ulang.
     *
     * @return void  Tidak mengembalikan nilai; pesan perubahan diteruskan ke exception induk.
     */
    public function __construct(string $message = 'Checkout berubah, silakan cek ulang sebelum membayar')
    {
        parent::__construct($message);
    }
}
