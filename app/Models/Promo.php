<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    protected $fillable = [
        'code', 'type', 'name', 'description', 'image',
        'discount_type', 'discount_value',
        'min_purchase', 'max_discount', 'quota', 'used_count',
        'start_date', 'end_date', 'is_active', 'created_by', 'owner_id',
        'day_type', 'hotel_types', 'location',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'is_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'hotel_types' => 'array',
    ];

    protected $attributes = [
        'used_count' => 0,
        'is_active' => true,
        'min_purchase' => 0,
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'promo_followers', 'promo_id', 'owner_id')->withTimestamps();
    }

    public function isFollowedBy(int $ownerId): bool
    {
        return $this->followers()->where('users.id', $ownerId)->exists();
    }

    /**
     * Cari promo terbaik yang sudah di-follow oleh owner & cocok untuk amount tertentu.
     * Mengembalikan ['promo' => Promo, 'discount' => float, 'final' => float] atau null.
     */
    public static function bestForOwner(int $ownerId, float $amount): ?array
    {
        $now = now();
        $promos = self::query()
            ->whereNull('owner_id')                          // hanya promo platform
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $now))
            ->whereHas('followers', fn ($q) => $q->where('users.id', $ownerId))
            ->where(fn ($q) => $q->whereNull('quota')->orWhereColumn('used_count', '<', 'quota'))
            ->get();

        $best = null;
        foreach ($promos as $p) {
            if ($amount < (float) $p->min_purchase) continue;
            $disc = $p->calculateDiscount($amount);
            if ($disc <= 0) continue;
            if (!$best || $disc > $best['discount']) {
                $best = [
                    'promo'    => $p,
                    'discount' => round($disc, 2),
                    'final'    => round(max(0, $amount - $disc), 2),
                ];
            }
        }
        return $best;
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)
            ->where(fn($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', now()))
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    public function calculateDiscount(float $amount): float
    {
        if ($amount < $this->min_purchase) {
            return 0;
        }

        $discount = $this->discount_type === 'percent'
            ? $amount * ($this->discount_value / 100)
            : $this->discount_value;

        return $this->max_discount ? min($discount, $this->max_discount) : $discount;
    }

    /**
     * Cek kondisi opsional promo (weekday/weekend, jenis akomodasi, lokasi).
     * Return pesan error kalau TIDAK memenuhi, atau null kalau lolos/tidak ada kondisi.
     * Kondisi yang kosong = tidak diterapkan.
     */
    public function conditionError(?Hotel $hotel, ?string $checkIn): ?string
    {
        // 1. Hari berlaku (weekday/weekend) — berdasarkan tanggal check-in.
        if ($this->day_type && $checkIn) {
            $dow       = \Carbon\Carbon::parse($checkIn)->dayOfWeek; // 0=Min .. 6=Sab
            $isWeekend = in_array($dow, [0, 6], true);
            if ($this->day_type === 'weekend' && !$isWeekend) {
                return 'Promo ini hanya berlaku untuk check-in akhir pekan (Sabtu–Minggu).';
            }
            if ($this->day_type === 'weekday' && $isWeekend) {
                return 'Promo ini hanya berlaku untuk check-in hari kerja (Senin–Jumat).';
            }
        }

        // 2. Jenis akomodasi — cocokkan dengan kolom category hotel (case-insensitive).
        $types = is_array($this->hotel_types) ? array_filter($this->hotel_types) : [];
        if (!empty($types) && $hotel) {
            $cat       = strtolower((string) $hotel->category);
            $typeLower = array_map('strtolower', $types);
            if (!in_array($cat, $typeLower, true)) {
                return 'Promo ini hanya berlaku untuk jenis akomodasi: ' . implode(', ', $types) . '.';
            }
        }

        // 3. Lokasi — cocokkan (sebagian, case-insensitive) dengan kota/area hotel.
        if ($this->location && $hotel) {
            $loc = strtolower(trim($this->location));
            $hay = strtolower(implode(' ', array_filter([
                $hotel->city, $hotel->district, $hotel->province, $hotel->address,
            ])));
            if ($loc !== '' && strpos($hay, $loc) === false) {
                return 'Promo ini hanya berlaku untuk lokasi: ' . $this->location . '.';
            }
        }

        return null;
    }
}
