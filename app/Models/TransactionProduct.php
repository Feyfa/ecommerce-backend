<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionProduct extends Model
{
    use HasFactory;

    protected $table = "transaction_products";

    protected $fillable = [
        'user_id_seller',
        'user_id_buyer',
        'product_id',
        'transaction_user_id',
        'price',
        'total',
    ];  
}
