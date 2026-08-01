<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaldoUser extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'saldo_users';

    protected $fillable = [
        'user_id',
        'saldo_income',
        'saldo_refund',
    ];

    /**
     * Mengambil user pemilik saldo.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
