<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaldoHistory extends Model
{
    use HasFactory;

    protected $table = 'saldo_histories';

    protected $fillable = [
        'user_id',
        'transaction_user_id',
        'payment_user_id',
        'type',
        'title',
        'price',
        'saldo_before',
        'saldo_after'
    ];
}
