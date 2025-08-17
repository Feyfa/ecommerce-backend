<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentUser extends Model
{
    use HasFactory;

    protected $table = 'payment_users';

    protected $fillable = [
        'user_id',
        'payment_id',
        'name',
        'account',
    ];
}
