<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaldoUser extends Model
{
    use HasFactory;

    protected $table = 'saldo_users';

    protected $fillable = [
        'user_id',
        'saldo_income',
        'saldo_refund',
    ];
}
