<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar',
        'oauth_provider', 'oauth_id', 'is_active', 'primary_role',
        'loyalty_tier_override',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_active'         => 'boolean',
    ];

    protected $attributes = ['is_active' => true, 'primary_role' => 'user'];

    // ── Relations ─────────────────────────────────────
    public function hotels()      { return $this->hasMany(Hotel::class, 'owner_id'); }
    public function bookings()    { return $this->hasMany(Booking::class); }
    public function loyaltyPoints(){ return $this->hasMany(LoyaltyPoint::class); }
    public function activityLogs(){ return $this->hasMany(ActivityLog::class); }
    public function chatRooms()   { return $this->hasMany(ChatRoom::class); }

    // ── Helpers ───────────────────────────────────────
    /**
     * Akun super-approver: hanya email ini yang boleh menerbitkan/approve tiket
     * yang batas waktunya (hold vendor) sudah lewat / expired.
     */
    public function isExpiredApprover(): bool
    {
        return strtolower(trim((string) $this->email)) === 'aldeftech@gmail.com';
    }

    public function getLoyaltyBalance(): int
    {
        return (int) $this->loyaltyPoints()
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('points');
    }
}
