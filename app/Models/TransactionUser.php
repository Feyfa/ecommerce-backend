<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TransactionUser extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'transaction_users';

    protected $fillable = [
        'user_id_seller',
        'user_id_buyer',
        'transaction_invoice_id',
        'transaction_number',
        'alamat_seller',
        'kurir_price',
        'product_price',
        'kurir_type',
        'kurir_estimate',
        'noted',
        'status'
    ];

    public function invoice()
    {
        return $this->belongsTo(TransactionInvoice::class, 'transaction_invoice_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id_seller');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'user_id_buyer');
    }

    public function products()
    {
        return $this->hasMany(TransactionProduct::class, 'transaction_user_id');
    }
}
