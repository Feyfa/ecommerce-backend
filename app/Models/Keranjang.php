<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Keranjang extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_id_seller',
        'user_id_buyer',
        'checked',
        'total',
    ];
}
