<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentList extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'payment_lists';

    protected $fillable = [
        'type',
        'method',
        'slug',
        'name'
    ];

    public function paymentUsers()
    {
        return $this->hasMany(PaymentUser::class, 'payment_id');
    }
}
