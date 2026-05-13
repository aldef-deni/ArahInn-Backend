<?php

namespace App\Services;

use App\Models\Room;
use App\Models\Booking;
use App\Models\Promo;
use Carbon\Carbon;

class PricingService
{
    private float $markupPercent;
    private float $taxPercent;

    public function __construct()
    {
        $this->markupPercent = (float) config('ota.markup_percent', 12) / 100;
        $this->taxPercent    = (float) config('ota.tax_percent', 11) / 100;
    }

    /**
     * Hitung harga final booking
     */
    public function calculate(array $params): array
    {
        [
            'room_id'    => $roomId,
            'check_in'   => $checkIn,
            'check_out'  => $checkOut,
            'promo_code' => $promoCode,
            'user_id'    => $userId,
            'use_points' => $usePoints,
            'room_count' => $roomCount,
        ] = array_merge(['promo_code' => null, 'user_id' => null, 'use_points' => false, 'room_count' => 1], $params);

        $room      = Room::with('hotel:id,owner_id')->findOrFail($roomId);
        $roomCount = max(1, (int) $roomCount);
        $ciDate    = Carbon::parse($checkIn);
        $coDate    = Carbon::parse($checkOut);
        $nights    = $ciDate->diffInDays($coDate);

        if ($nights <= 0) {
            throw new \InvalidArgumentException('Tanggal checkout harus setelah check-in.');
        }

        // 1. Base price dengan weekend premium × jumlah kamar
        $basePrice = $this->calculateBasePrice($room->base_price, $ciDate, $coDate) * $roomCount;

        // 2. Occupancy-based markup
        $occupancyRate = $this->getOccupancyRate($roomId, $checkIn, $checkOut, $room->total_units);
        if ($occupancyRate > 0.8)       $basePrice *= 1.10;
        elseif ($occupancyRate > 0.5)   $basePrice *= 1.05;

        // 3. Platform markup
        $markupAmount = round($basePrice * $this->markupPercent, 2);
        $subtotal     = $basePrice + $markupAmount;

        // 4. Promo discount
        $hotelOwnerId = $room->hotel->owner_id ?? null;
        [$promoDiscount, $promo] = $this->applyPromo($promoCode, $subtotal, $hotelOwnerId);
        $subtotal -= $promoDiscount;

        // 5. Loyalty points (max 10% dari subtotal)
        $loyaltyDiscount = 0;
        if ($usePoints && $userId) {
            $user    = \App\Models\User::find($userId);
            $balance = $user?->getLoyaltyBalance() ?? 0;
            $loyaltyDiscount = min($balance, $subtotal * 0.10);
            $subtotal -= $loyaltyDiscount;
        }

        // 6. Pajak PPN
        $taxAmount = round($subtotal * $this->taxPercent, 2);

        // 7. Random 3-digit suffix (001–999) untuk transfer unik
        $priceSuffix = random_int(1, 999);
        $totalPrice  = (int) ceil($subtotal + $taxAmount) + $priceSuffix;

        return [
            'nights'           => $nights,
            'base_price'       => round($basePrice, 2),
            'markup_amount'    => round($markupAmount, 2),
            'promo_discount'   => round($promoDiscount, 2),
            'loyalty_discount' => round($loyaltyDiscount, 2),
            'tax_amount'       => round($taxAmount, 2),
            'price_suffix'     => $priceSuffix,
            'total_price'      => $totalPrice,
            'promo'            => $promo,
            'breakdown'        => [
                'occupancy_rate' => round($occupancyRate * 100),
            ],
        ];
    }

    /**
     * Harga dasar dengan weekend premium (+15%)
     */
    private function calculateBasePrice(float $basePerNight, Carbon $checkIn, Carbon $checkOut): float
    {
        $total = 0;
        $current = $checkIn->copy();

        while ($current->lt($checkOut)) {
            $isWeekend = $current->isWeekend();
            $total += $isWeekend ? $basePerNight * 1.15 : $basePerNight;
            $current->addDay();
        }

        return round($total, 2);
    }

    /**
     * Occupancy rate kamar
     */
    private function getOccupancyRate(int $roomId, string $checkIn, string $checkOut, int $totalUnits): float
    {
        if ($totalUnits <= 0) return 0;

        $booked = Booking::where('room_id', $roomId)
            ->whereIn('status', ['paid', 'issued'])
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn)
            ->count();

        return $booked / $totalUnits;
    }

    /**
     * Validasi dan hitung diskon promo
     */
    private function applyPromo(?string $code, float $amount, ?int $hotelOwnerId = null): array
    {
        if (!$code) return [0, null];

        $promo = Promo::where('code', $code)->active()->first();

        if (!$promo) {
            throw new \InvalidArgumentException('Kode promo tidak valid atau sudah kadaluarsa.');
        }

        // Owner-scoped promo: only valid for that owner's hotels
        if ($promo->owner_id !== null && $promo->owner_id !== $hotelOwnerId) {
            throw new \InvalidArgumentException('Kode promo tidak berlaku untuk hotel ini.');
        }

        if ($promo->quota !== null && $promo->used_count >= $promo->quota) {
            throw new \InvalidArgumentException('Kuota promo sudah habis.');
        }

        if ($amount < $promo->min_purchase) {
            throw new \InvalidArgumentException(
                'Minimum pembelian Rp ' . number_format($promo->min_purchase, 0, ',', '.') . ' untuk promo ini.'
            );
        }

        $discount = $promo->calculateDiscount($amount);

        return [round($discount, 2), $promo];
    }
}
