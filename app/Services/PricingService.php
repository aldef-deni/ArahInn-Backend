<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\Booking;
use App\Models\Promo;
use App\Models\RatePlan;
use Carbon\Carbon;

class PricingService
{
    // PPh (Pajak Penghasilan) yang selalu ditambahkan di atas komisi properti.
    private const PPH_PERCENT = 2.0;

    // Fallback markup kalau hotel belum punya commission_percent
    // (= komisi default 10% + PPh 2% = 12%). Diambil dari config supaya
    // bisa di-override via env tanpa edit kode.
    private float $defaultMarkupPercent;
    private float $taxPercent;
    private bool  $taxEnabled;

    public function __construct()
    {
        $this->defaultMarkupPercent = (float) config('ota.markup_percent', 12) / 100;

        // PPN dibaca dari setting superadmin (toggle on/off + persen).
        // Fallback ke config ota.tax_percent kalau cache kosong.
        $ppn              = \App\Http\Controllers\Admin\SettingController::ppnTax();
        $this->taxEnabled = (bool) $ppn['enabled'];
        $this->taxPercent = (float) $ppn['percent'] / 100;
    }

    /**
     * Hitung total markup ("Pajak & Others") untuk sebuah hotel.
     *   commission_percent (komisi properti) + 2% PPh
     * Kalau commission_percent NULL → fallback ke default config (12%).
     */
    private function resolveMarkup(?Room $room): float
    {
        $hotel = $room?->hotel;
        if (!$hotel || $hotel->commission_percent === null) {
            return $this->defaultMarkupPercent;
        }
        $commission = (float) $hotel->commission_percent;
        return ($commission + self::PPH_PERCENT) / 100;
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

        // Sertakan kolom category + lokasi → dibutuhkan untuk cek KONDISI promo
        // (jenis akomodasi & lokasi). Tanpa ini category null → promo selalu ditolak.
        $room      = Room::with('hotel:id,owner_id,commission_percent,category,city,district,province,address')->findOrFail($roomId);
        $roomCount = max(1, (int) $roomCount);
        $ciDate    = Carbon::parse($checkIn);
        $coDate    = Carbon::parse($checkOut);
        $nights    = $ciDate->diffInDays($coDate);

        if ($nights <= 0) {
            throw new \InvalidArgumentException('Tanggal checkout harus setelah check-in.');
        }

        // 1. Base price = sum harga per tanggal × jumlah kamar.
        //    Owner bisa override harga per tanggal lewat "Atur Harga & Ketersediaan"
        //    (kolom room_prices.price). Kalau tidak di-set, pakai room.base_price.
        //    TIDAK ADA weekend premium otomatis — owner bisa set harga weekend sendiri.
        $basePrice = $this->calculateBasePrice($room, $ciDate, $coDate) * $roomCount;

        // 1b. Rate plan: pilih plan yang berlaku → apply multiplier × (1 − discount%)
        $ratePlan         = RatePlan::pickApplicable($room->hotel_id, $room->id, $nights, $checkIn, $checkOut);
        $ratePlanModifier = $ratePlan ? $ratePlan->priceModifier() : 1.0;
        if ($ratePlanModifier !== 1.0) {
            $basePrice = round($basePrice * $ratePlanModifier, 2);
        }

        // Simpan harga sebelum promo untuk display "Rp X coret → Rp Y"
        $originalBasePrice = $basePrice;

        // 2. Promo discount — diterapkan ke BASE PRICE dulu (sebelum markup)
        //    agar konsisten dengan harga "discounted" di card kamar (yang juga
        //    dihitung dari base price, bukan subtotal).
        $hotelOwnerId = $room->hotel->owner_id ?? null;

        // 2a. Diskon CAMPAIGN otomatis (owner mengikuti campaign / promo platform) —
        //     SELALU dihitung, tidak hilang walau ada kode promo manual.
        $campaignDiscount = 0;
        $campaign = null;
        if ($hotelOwnerId) {
            $best = \App\Services\OwnerDiscountService::best($hotelOwnerId, $basePrice);
            if ($best) {
                $campaignDiscount = $best['discount'];
                $campaign         = $best['campaign'];  // null kalau sumbernya promo-follow
            }
        }

        // 2b. Diskon KODE PROMO manual (kalau ada) — DI-STACK di atas diskon campaign.
        [$codeDiscount, $promo] = $this->applyPromo($promoCode, $basePrice, $hotelOwnerId, $room->hotel, $checkIn);

        // Total diskon = campaign + kode, dibatasi agar tidak melebihi harga.
        $promoDiscount = min($campaignDiscount + $codeDiscount, $originalBasePrice);
        $basePrice = round(max(0, $originalBasePrice - $promoDiscount), 2);

        // 3. Markup "Pajak & Others" = komisi properti + 2% PPh
        //    Dihitung dari base price POST-promo, jadi customer dapat manfaat
        //    diskon di markup juga.
        $markupPercent = $this->resolveMarkup($room);
        $markupAmount  = round($basePrice * $markupPercent, 2);
        $subtotal      = $basePrice + $markupAmount;

        // ── Skema BEBAN DISKON (untuk laporan komisi/laba) ──────────────────
        // Siapa bikin diskon, dia yang nanggung. Harga customer TIDAK berubah.
        //   D_a = diskon ArahInn (campaign + promo owner_id NULL)
        //   D_o = diskon owner   (promo owner_id terisi)
        //   owner_payout      = N − D_o*(1+m)        → owner cuma nanggung diskonnya
        //   commission_profit = N*c − D_a*(1+m)      → ArahInn nanggung diskonnya (bisa minus = nalangin)
        // N = originalBasePrice (sebelum promo), m = markup, c = komisi murni (m − 2% PPh)
        $promoIsOwner = $promo && $promo->owner_id !== null;
        $rawDiscTotal = $campaignDiscount + $codeDiscount;
        $discScale    = $rawDiscTotal > 0 ? ($promoDiscount / $rawDiscTotal) : 0; // ≤1 bila ke-cap
        $discountOwner   = round(($promoIsOwner ? $codeDiscount : 0) * $discScale, 2);
        $discountArahinn = round(($campaignDiscount + ($promoIsOwner ? 0 : $codeDiscount)) * $discScale, 2);

        $commissionFrac   = max(0, $markupPercent - (self::PPH_PERCENT / 100)); // komisi murni
        $ownerPayout      = round(max(0, $originalBasePrice - $discountOwner * (1 + $markupPercent)), 2);
        $commissionProfit = round($originalBasePrice * $commissionFrac - $discountArahinn * (1 + $markupPercent), 2);

        // Kept for compatibility (occupancy_rate masih dilaporkan di breakdown)
        $occupancyRate = $this->getOccupancyRate($roomId, $checkIn, $checkOut, $room->total_units);

        // 5. Loyalty points (max 10% dari subtotal)
        $loyaltyDiscount = 0;
        if ($usePoints && $userId) {
            $user    = \App\Models\User::find($userId);
            $balance = $user?->getLoyaltyBalance() ?? 0;
            $loyaltyDiscount = min($balance, $subtotal * 0.10);
            $subtotal -= $loyaltyDiscount;
        }

        // 6. Pajak PPN — hanya kalau di-enable superadmin
        $taxAmount = $this->taxEnabled
            ? round($subtotal * $this->taxPercent, 2)
            : 0.0;

        // 7. Random 3-digit suffix (001–999) untuk transfer unik
        $priceSuffix = random_int(1, 999);
        $totalPrice  = (int) ceil($subtotal + $taxAmount) + $priceSuffix;

        return [
            'nights'                => $nights,
            'original_base_price'   => round($originalBasePrice, 2),  // sebelum diskon promo
            'base_price'            => round($basePrice, 2),           // setelah diskon promo
            'markup_amount'         => round($markupAmount, 2),
            'promo_discount'        => round($promoDiscount, 2),    // total (campaign + kode)
            'campaign_discount'     => round($campaignDiscount, 2), // bagian dari campaign
            'code_discount'         => round($codeDiscount, 2),     // bagian dari kode promo
            'discount_arahinn'      => $discountArahinn,            // diskon yang ditanggung ArahInn
            'discount_owner'        => $discountOwner,              // diskon yang ditanggung owner
            'owner_payout'          => $ownerPayout,                // diterima owner (skema beban)
            'commission_profit'     => $commissionProfit,           // laba komisi ArahInn (skema beban)
            'loyalty_discount' => round($loyaltyDiscount, 2),
            'tax_amount'       => round($taxAmount, 2),
            'price_suffix'     => $priceSuffix,
            'total_price'      => $totalPrice,
            'promo'            => $promo,
            'campaign'         => $campaign,
            'rate_plan'        => $ratePlan ? [
                'id'                 => $ratePlan->id,
                'name'                => $ratePlan->name,
                'type'                => $ratePlan->type,
                'is_default'          => (bool) $ratePlan->is_default,
                'multiplier'          => (float) $ratePlan->multiplier,
                'discount_percent'    => (float) ($ratePlan->discount_percent ?? 0),
                'breakfast'           => (bool) $ratePlan->breakfast,
                'cancelable'          => (bool) $ratePlan->cancelable,
                'cancellation_type'   => $ratePlan->cancellation_type,
            ] : null,
            'breakdown'        => [
                'occupancy_rate'      => round($occupancyRate * 100),
                'rate_plan_modifier'  => $ratePlanModifier,
            ],
        ];
    }

    /**
     * Harga dasar = sum harga per tanggal dalam rentang stay.
     * Prioritas tiap malam:
     *   1. room_prices.price (kalau owner set harga khusus tanggal itu)
     *   2. room.base_price (default)
     *
     * TIDAK ADA weekend premium otomatis lagi. Kalau owner mau Saturday lebih
     * mahal, owner set lewat menu "Atur Harga & Ketersediaan".
     */
    private function calculateBasePrice(Room $room, Carbon $checkIn, Carbon $checkOut): float
    {
        $defaultPrice = (float) $room->base_price;

        // Ambil semua override harga per tanggal di rentang stay
        $stayDates = [];
        $cursor = $checkIn->copy();
        while ($cursor->lt($checkOut)) {
            $stayDates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        $overrides = RoomPrice::where('room_id', $room->id)
            ->whereIn('date', $stayDates)
            ->whereNotNull('price')
            ->get()
            ->keyBy(fn($p) => $p->date->format('Y-m-d'));

        $total = 0;
        foreach ($stayDates as $d) {
            $row = $overrides->get($d);
            $total += $row ? (float) $row->price : $defaultPrice;
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
    private function applyPromo(?string $code, float $amount, ?int $hotelOwnerId = null, $hotel = null, ?string $checkIn = null): array
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

        // Kondisi opsional (weekday/weekend, jenis akomodasi, lokasi)
        if ($err = $promo->conditionError($hotel, $checkIn)) {
            throw new \InvalidArgumentException($err);
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
