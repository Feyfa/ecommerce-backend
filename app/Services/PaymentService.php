<?php

namespace App\Services;

use App\Models\PaymentList;
use App\Models\PaymentUser;
use Faker\Factory as Faker;

class PaymentService
{
    /**
     * Mengambil katalog metode pembayaran yang dapat dipilih buyer saat checkout.
     *
     * Query mengambil metode pembayaran aktif yang dapat dipakai pada checkout dan mengembalikannya
     * dalam bentuk daftar sederhana. Data rekening withdrawal milik user tidak ikut tercampur dalam
     * pilihan pembayaran buyer.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getCheckoutPayment(): array
    {
        $paymentList = PaymentList::select('slug', 'method', 'name')
            ->where('type', 'incoming')
            ->where('method', 'va')
            ->where('slug', 'bca')
            ->get()
            ->toArray();

        return [
            'payments' => $paymentList,
        ];
    }

    /**
     * Mengambil daftar metode pembayaran yang mendukung withdrawal.
     *
     * Metode pembayaran milik user dimuat bersama katalog bank dan dapat difilter menggunakan
     * pencarian. Hasil hanya mencakup rekening yang dapat dipakai untuk pencairan dana.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $search  Kata kunci pencarian yang akan diterapkan.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getWithdrawalPayments(string $user_id = '', $search = ''): array
    {
        // --- step 1 - start - ambil data payment
        $payments = PaymentUser::select(
            'payment_users.id as id',
            'payment_lists.slug as slug',
            'payment_lists.name as name',
            'payment_users.account as account',
            'payment_users.name as username'
        )
            ->join('payment_lists', 'payment_lists.id', '=', 'payment_users.payment_id')
            ->where('payment_users.user_id', $user_id)
            ->where('payment_lists.type', 'withdrawal');

        if (! empty($search) && trim($search) != '') {
            $payments->where(function ($query) use ($search) {
                $query->where('payment_lists.slug', 'ILIKE', "%{$search}%")
                    ->orWhere('payment_lists.name', 'ILIKE', "%{$search}%")
                    ->orWhere('payment_users.name', 'ILIKE', "%{$search}%")
                    ->orWhere('payment_users.account', 'ILIKE', "%{$search}%");
            });
        }

        $payments = $payments->orderBy('payment_users.created_at', 'desc')
            ->get();
        // --- step 1 - end - ambil data payment

        return ['status' => 'success', 'payments' => $payments];
    }

    /**
     * Mengambil satu metode withdrawal berdasarkan ID.
     *
     * Identifier rekening dan user digunakan bersama sebagai scope ownership. Detail metode
     * dikembalikan ketika ditemukan, sedangkan rekening asing atau tidak ada menghasilkan error
     * terstruktur.
     *
     * @param  string  $user_id  ID user yang menjadi scope data atau mutasi.
     * @param  string  $account  ID rekening pembayaran yang dicari.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function getWithdrawalPayment(string $user_id, string $account): array
    {
        // --- step 1 - start - validasi input
        if (empty($user_id) || trim($user_id) == '') {
            return ['status' => 'error', 'message' => 'user id cannot be empty'];
        }
        if (empty($account) || trim($account) == '') {
            return ['status' => 'error', 'message' => 'payment account cannot be empty'];
        }
        // --- step 1 - end - validasi input

        // --- step 2 - start - ambil data payment
        $payment = PaymentUser::from('payment_users as pu')
            ->select(
                'pu.id',
                'pu.name as user_name',
                'pl.slug as payment_slug'
            )
            ->join('payment_lists as pl', 'pl.id', '=', 'pu.payment_id')
            ->where('pu.user_id', $user_id)
            ->where('pu.account', $account)
            ->where('pl.type', 'withdrawal')
            ->first();
        if (empty($payment)) {
            return ['status' => 'error', 'message' => 'rekening anda tidak ditemukan'];
        }
        $payment = $payment->toArray();

        $paymentSlug = $payment['payment_slug'] ?? '';
        $userName = $payment['user_name'] ?? '';
        if (empty($paymentSlug) || trim($paymentSlug) == '') {
            return ['status' => 'error', 'message' => 'Payment Slug Cannot Be Empty'];
        }
        if (empty($userName) || trim($userName) == '') {
            return ['status' => 'error', 'message' => 'User Name Cannot Be Empty'];
        }
        // --- step 2 - end - ambil data payment

        return ['status' => 'success', 'payment' => $payment];
    }

    /**
     * Membuat data user simulasi untuk pengujian pembayaran.
     *
     * Nilai acak yang aman untuk sandbox dibentuk sesuai kontrak identitas yang diperlukan provider.
     * Helper ini hanya mendukung simulasi dan tidak mengambil data pribadi user produksi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function generateFakeUser(): array
    {
        $faker = Faker::create('id_ID');

        $user = [
            'name' => $faker->name,
            'email' => $faker->unique()->safeEmail,
            'phone' => $faker->phoneNumber,
            'address' => $faker->address,
        ];

        return ['status' => 'success', 'user' => $user];
    }
}
