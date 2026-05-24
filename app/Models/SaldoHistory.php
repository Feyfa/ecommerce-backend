<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SaldoHistory extends Model
{
    use HasFactory, HasUuids;

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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transactionUser()
    {
        return $this->belongsTo(TransactionUser::class, 'transaction_user_id');
    }

    public function paymentUser()
    {
        return $this->belongsTo(PaymentUser::class, 'payment_user_id');
    }
}
