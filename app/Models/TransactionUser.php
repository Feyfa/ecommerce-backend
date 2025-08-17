<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionUser extends Model
{
    use HasFactory;

    protected $table = 'transaction_users';

    protected $fillable = [
        'user_id_seller',
        'user_id_buyer',
        'transaction_invoice_id',
        'transaction_number',
        'alamat_seller',
        'kurir_price',
        'product_price',
        'kurir_type',
        'kurir_estimate',
        'noted',
        'status'
    ];
}
