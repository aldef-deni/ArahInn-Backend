<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = [
        'title', 'type', 'target', 'status',
        'start_date', 'end_date', 'budget', 'discount_percent', 'description',
        'image', 'owner_id', 'created_by', 'views', 'clicks',
    ];

    protected $casts = [
        'budget'           => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'start_date'       => 'date:Y-m-d',
        'end_date'         => 'date:Y-m-d',
        'views'            => 'integer',
        'clicks'           => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Owner yang mengikuti campaign ini (opt-in dari extranet)
    public function followers()
    {
        return $this->belongsToMany(User::class, 'campaign_followers', 'campaign_id', 'owner_id')->withTimestamps();
    }

    public function isFollowedBy($ownerId): bool
    {
        return $this->followers()->where('users.id', $ownerId)->exists();
    }

    /**
     * Diskon campaign terbaik yang DIIKUTI owner & sedang berjalan, untuk amount tertentu.
     * Hanya campaign aktif dengan discount_percent > 0 dan sudah mulai (start_date <= now).
     * Return ['campaign' => Campaign, 'discount' => float, 'final' => float] atau null.
     */
    public static function bestForOwner(int $ownerId, float $amount): ?array
    {
        $now = now();
        $campaigns = self::query()
            ->where('status', 'active')
            ->where('discount_percent', '>', 0)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $now))
            ->whereHas('followers', fn ($q) => $q->where('users.id', $ownerId))
            ->get();

        $best = null;
        foreach ($campaigns as $c) {
            $disc = round($amount * ((float) $c->discount_percent / 100), 2);
            if ($disc <= 0) continue;
            if (!$best || $disc > $best['discount']) {
                $best = [
                    'campaign' => $c,
                    'discount' => $disc,
                    'final'    => round(max(0, $amount - $disc), 2),
                ];
            }
        }
        return $best;
    }
}
