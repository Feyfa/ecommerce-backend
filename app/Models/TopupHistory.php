<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TopupHistory extends Model
{
    use HasFactory;

    protected $table = 'topup_histories';

    protected $fillable = [
        'user_id_seller',
        'payment',
        'amount',
        'stripe_process_fee',
        'last_number',
        'status',
        'message_error'
    ];
}

