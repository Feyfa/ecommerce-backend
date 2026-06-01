<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TransactionInvoice extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'transaction_invoices';

    protected $fillable = [
        'user_id_buyer',
        'checkout_key',
        'alamat_buyer',
        'payment_slug',
        'payment_name',
        'payment_method',
        'payment_account',
        'payment_reference',
        'price',
        'status',
        'expired_at',
        'created_at',
        'updated_at',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'user_id_buyer');
    }

    public function transactionUsers()
    {
        return $this->hasMany(TransactionUser::class, 'transaction_invoice_id');
    }
}
