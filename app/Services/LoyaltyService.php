<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Program loyalitas ArahInn.
 *
 * Earn  : floor(total_price / earn_per) × multiplier(tier). Default Rp100 = 1 poin,
 *         dikali tier (Silver ×1, Gold ×2, Platinum ×5).
 * Aktivasi user baru : +activation_points (default 1000), sekali per user.
 * Redeem: 1 poin = Rp1 (di PricingService), tanpa batas minimal/maksimal.
 * Expiry: rolling — tiap poin earn hangus 12 bulan setelah didapat.
 * Tier  : dari LIFETIME EARNED (akumulasi poin positif sepanjang waktu, abaikan
 *         expiry), bukan saldo. Bisa di-override manual oleh superadmin.
 *
 * Catatan: kolom loyalty_points.type adalah enum('earn','redeem','expire').
 * Semua pemberian poin positif (booking/aktivasi/bonus/referral/admin) memakai
 * type='earn' dan dibedakan lewat description. Pengurangan memakai type='redeem'.
 */
class LoyaltyService
{
    // 4 tingkatan: Member (dasar) → Silver → Gold → Platinum.
    private const DEFAULTS = [
        'enabled'           => true,
        'earn_per'          => 100,     // Rp per 1 poin (tier Member/dasar)
        'activation_points' => 1000,
        'tier_silver'       => 5000,    // lifetime earned utk Silver
        'tier_gold'         => 25000,   // lifetime earned utk Gold
        'tier_platinum'     => 100000,  // lifetime earned utk Platinum
        'mult_member'       => 1,
        'mult_silver'       => 2,
        'mult_gold'         => 3,
        'mult_platinum'     => 5,
    ];

    /** Konfigurasi loyalitas (DB settings + default). */
    public function config(): array
    {
        $stored = Cache::rememberForever('settings:loyalty', function () {
            try { return Setting::where('key', 'loyalty')->value('value'); }
            catch (\Throwable $e) { return null; }
        });
        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public function saveConfig(array $values): array
    {
        $cfg = array_merge($this->config(), $values);
        try { Setting::updateOrCreate(['key' => 'loyalty'], ['value' => $cfg]); }
        catch (\Throwable $e) {}
        Cache::forever('settings:loyalty', $cfg);
        return $cfg;
    }

    // ── Earn ──────────────────────────────────────────────────────────────
    public function earn(int $userId, int $points, ?int $bookingId = null, string $description = 'Poin dari booking'): void
    {
        if ($points <= 0) return;
        LoyaltyPoint::create([
            'user_id'     => $userId,
            'points'      => $points,
            'type'        => 'earn',
            'description' => $description,
            'booking_id'  => $bookingId,
            'expires_at'  => now()->addYear(),
            'created_at'  => now(),
        ]);
    }

    /** Earn dari booking yang sudah issued — Rp earn_per per poin × multiplier tier. */
    public function earnForBooking(Booking $booking): void
    {
        $cfg = $this->config();
        if (! ($cfg['enabled'] ?? true)) return;

        $perRp = max(1, (int) $cfg['earn_per']);
        $base  = (int) floor(((float) $booking->total_price) / $perRp);
        if ($base <= 0) return;

        $mult   = $this->multiplier($this->getTier($booking->user_id));
        $points = $base * $mult;

        $this->earn($booking->user_id, $points, $booking->id, 'Poin dari booking');
    }

    /** Poin aktivasi user baru — idempoten (sekali per user). */
    public function grantActivation(int $userId): void
    {
        $cfg = $this->config();
        if (! ($cfg['enabled'] ?? true)) return;
        $pts = (int) $cfg['activation_points'];
        if ($pts <= 0) return;

        $already = LoyaltyPoint::where('user_id', $userId)
            ->where('description', 'Bonus aktivasi akun')->exists();
        if ($already) return;

        $this->earn($userId, $pts, null, 'Bonus aktivasi akun');
    }

    // ── Redeem ────────────────────────────────────────────────────────────
    public function redeem(int $userId, int $amount, int $bookingId): void
    {
        if ($amount <= 0) return;
        LoyaltyPoint::create([
            'user_id'     => $userId,
            'points'      => -$amount,
            'type'        => 'redeem',
            'description' => 'Poin digunakan untuk pembayaran',
            'booking_id'  => $bookingId,
            'created_at'  => now(),
        ]);
    }

    /** Penyesuaian poin manual oleh admin (+/-). */
    public function adjust(int $userId, int $points, string $reason): void
    {
        if ($points === 0) return;
        LoyaltyPoint::create([
            'user_id'     => $userId,
            'points'      => $points,
            'type'        => $points > 0 ? 'earn' : 'redeem',
            'description' => $reason ?: 'Penyesuaian oleh admin',
            'booking_id'  => null,
            'expires_at'  => $points > 0 ? now()->addYear() : null,
            'created_at'  => now(),
        ]);
    }

    // ── Saldo, pencapaian, tier ───────────────────────────────────────────
    public function getBalance(int $userId): int
    {
        return (int) LoyaltyPoint::where('user_id', $userId)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('points');
    }

    /** Total poin positif sepanjang waktu (pencapaian, abaikan expiry & redeem). */
    public function getLifetimeEarned(int $userId): int
    {
        return (int) LoyaltyPoint::where('user_id', $userId)
            ->where('points', '>', 0)
            ->sum('points');
    }

    public function computeTier(int $lifetime): string
    {
        $cfg = $this->config();
        if ($lifetime >= (int) $cfg['tier_platinum']) return 'platinum';
        if ($lifetime >= (int) $cfg['tier_gold'])     return 'gold';
        if ($lifetime >= (int) $cfg['tier_silver'])   return 'silver';
        return 'member';
    }

    /** Tier efektif: override admin bila ada, selain itu dihitung dari lifetime. */
    public function getTier(int $userId): string
    {
        $override = User::where('id', $userId)->value('loyalty_tier_override');
        if (in_array($override, ['member', 'silver', 'gold', 'platinum'], true)) return $override;
        return $this->computeTier($this->getLifetimeEarned($userId));
    }

    public function multiplier(string $tier): int
    {
        $cfg = $this->config();
        return (int) match ($tier) {
            'platinum' => $cfg['mult_platinum'],
            'gold'     => $cfg['mult_gold'],
            'silver'   => $cfg['mult_silver'],
            default    => $cfg['mult_member'],
        };
    }

    /** Ringkasan untuk halaman customer. */
    public function summary(int $userId): array
    {
        $cfg      = $this->config();
        $balance  = $this->getBalance($userId);
        $lifetime = $this->getLifetimeEarned($userId);
        $tier     = $this->getTier($userId);

        // Threshold tier berikutnya (berdasarkan lifetime; abaikan jika override aktif)
        $nextTier = null; $nextThreshold = null;
        if ($lifetime < (int) $cfg['tier_silver']) {
            $nextTier = 'silver'; $nextThreshold = (int) $cfg['tier_silver'];
        } elseif ($lifetime < (int) $cfg['tier_gold']) {
            $nextTier = 'gold'; $nextThreshold = (int) $cfg['tier_gold'];
        } elseif ($lifetime < (int) $cfg['tier_platinum']) {
            $nextTier = 'platinum'; $nextThreshold = (int) $cfg['tier_platinum'];
        }
        $remaining = $nextThreshold ? max(0, $nextThreshold - $lifetime) : 0;

        return [
            'balance'        => $balance,
            'lifetime'       => $lifetime,
            'tier'           => $tier,
            'multiplier'     => $this->multiplier($tier),
            'next_tier'      => $nextTier,
            'next_threshold' => $nextThreshold,
            'remaining'      => $remaining,
            'config'         => [
                'earn_per'          => (int) $cfg['earn_per'],
                'activation_points' => (int) $cfg['activation_points'],
                'tier_silver'       => (int) $cfg['tier_silver'],
                'tier_gold'         => (int) $cfg['tier_gold'],
                'tier_platinum'     => (int) $cfg['tier_platinum'],
            ],
        ];
    }

    public function getHistory(int $userId, int $page = 1, int $limit = 20)
    {
        return LoyaltyPoint::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
