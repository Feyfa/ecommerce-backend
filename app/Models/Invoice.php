<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'invoices';

    protected $fillable = [
        'user_id_buyer',
        'order_id',
        'payment_type',
        'gross_amount',
        'currency',
        'va_number',
        'va_bank',
        'transaction_status',
        'transaction_time',
        'settlement_time',
        'expiry_time',
    ];
}
