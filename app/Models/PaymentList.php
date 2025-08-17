<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentList extends Model
{
    use HasFactory;

    protected $table = 'payment_lists';

    protected $fillable = [
        'type',
        'method',
        'slug',
        'name'
    ];
}
