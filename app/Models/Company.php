<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'companies';

    protected $fillable = [
        'user_id',
        'img',
        'name',
        'email',
        'phone',
        'description',
    ];

    /**
     * Mengambil user pemilik profil toko.
     *
     * @return BelongsTo<User, Company> Relasi Eloquent menuju user pemilik profil toko.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
