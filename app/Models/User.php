<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'avatar',
        'is_active',
        'country_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'referral_benefit_expiry_date' => 'datetime',
            'referral_balance' => 'decimal:2',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public static function findByPhone(string $phone): ?self
    {
        $normalized = PhoneNumber::normalize($phone);
        $display = PhoneNumber::display($phone);

        $candidates = array_unique([
            $phone,
            $display,
            $normalized,
            '88'.$normalized,
            '+88'.$normalized,
        ]);

        return static::query()->whereIn('phone', $candidates)->first();
    }

    public static function findByLoginIdentifier(string $identifier): ?self
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return static::query()->where('email', $identifier)->first();
        }

        return static::findByPhone($identifier);
    }
}
