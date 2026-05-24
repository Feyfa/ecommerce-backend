<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentUser extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'payment_users';

    protected $fillable = [
        'user_id',
        'payment_id',
        'name',
        'account',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function paymentList()
    {
        return $this->belongsTo(PaymentList::class, 'payment_id');
    }
}
