<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionInvoice extends Model
{
    use HasFactory;

    protected $table = 'transaction_invoices';

    protected $fillable = [
        'user_id_buyer',
        'alamat_buyer',
        'payment_slug',
        'payment_name',
        'payment_method',
        'payment_account',
        'payment_reference',
        'price',
        'status',
        'expired_at',
        'created_at',
        'updated_at',
    ];
}
