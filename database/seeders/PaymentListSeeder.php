<?php

namespace Database\Seeders;

use App\Models\PaymentList;
use Illuminate\Database\Seeder;

class PaymentListSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $paymentLists = [
            [
                'type' => 'withdrawal',
                'method' => 'debit',
                'slug' => 'bca',
                'name' => 'PT. BCA (BANK CENTRAL ASIA) TBK',
            ],
            [
                'type' => 'withdrawal',
                'method' => 'debit',
                'slug' => 'bni',
                'name' => 'PT. BANK NEGARA INDONESIA (BNI) (PERSERO)',
            ],
            [
                'type' => 'withdrawal',
                'method' => 'debit',
                'slug' => 'bri',
                'name' => 'PT. BANK RAKYAT INDONESIA (BRI) (PERSERO)',
            ],
            [
                'type' => 'withdrawal',
                'method' => 'debit',
                'slug' => 'mandiri',
                'name' => 'PT. BANK MANDIRI',
            ],
            [
                'type' => 'incoming',
                'method' => 'va',
                'slug' => 'bca',
                'name' => 'BCA Virtual Account',
            ],
            [
                'type' => 'incoming',
                'method' => 'va',
                'slug' => 'bri',
                'name' => 'BRI Virtual Account',
            ],
            [
                'type' => 'incoming',
                'method' => 'va',
                'slug' => 'bni',
                'name' => 'BNI Virtual Account',
            ],
            [
                'type' => 'incoming',
                'method' => 'va',
                'slug' => 'mandiri',
                'name' => 'Mandiri Virtual Account',
            ],
        ];

        foreach ($paymentLists as $paymentList) {
            PaymentList::updateOrCreate(
                [
                    'type' => $paymentList['type'],
                    'method' => $paymentList['method'],
                    'slug' => $paymentList['slug'],
                ],
                [
                    'name' => $paymentList['name'],
                ],
            );
        }
    }
}
