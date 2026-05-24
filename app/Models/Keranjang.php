<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Keranjang extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'product_id',
        'user_id_seller',
        'user_id_buyer',
        'checked',
        'checkout',
        'total',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'user_id_buyer');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id_seller');
    }
}
