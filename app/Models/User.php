<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'clerk_user_id',
        'img',
        'name',
        'email',
        'phone',
        'jenis_kelamin',
        'tanggal_lahir',
        // 'alamat',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'user_id_seller');
    }

    public function buyerKeranjangs()
    {
        return $this->hasMany(Keranjang::class, 'user_id_buyer');
    }

    public function sellerKeranjangs()
    {
        return $this->hasMany(Keranjang::class, 'user_id_seller');
    }

    public function alamats()
    {
        return $this->hasMany(Alamat::class, 'user_id');
    }

    public function company()
    {
        return $this->hasOne(Company::class, 'user_id');
    }

    public function saldoUser()
    {
        return $this->hasOne(SaldoUser::class, 'user_id');
    }

    /**
     * Mengambil seluruh audit yang dimiliki user sebagai actor.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }
}
