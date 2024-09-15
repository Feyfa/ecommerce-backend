<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $table = "transactions";

    protected $fillable = [
        'order_id',
        'product_id',
        'user_id_seller',
        'user_id_buyer',
        'price',
        'total',
    ];
}
