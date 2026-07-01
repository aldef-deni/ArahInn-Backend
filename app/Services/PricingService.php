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
     * Komisi properti (fraksi) yang DIPOTONG dari setoran owner.
     *   owner_payout = (harga − diskon campaign/ArahInn) × (1 − [komisi + 2% PPh]).
     *   PPh 2% sudah termasuk di komisi (dipotong dari owner, bukan ditambah ke customer).
     * Kalau commission_percent NULL → fallback default config (12% = 10% + PPh 2%).
     */
    private function resolveCommission(?Room $room): float
    {
        $hotel = $room?->hotel;
        if (!$hotel || $hotel->commission_percent === null) {
            return $this->defaultMarkupPercent;
        }
        return ((float) $hotel->commission_percent + self::PPH_PERCENT) / 100;
    }

    /**
     * Komisi (fraksi) untuk long-stay mingguan/bulanan, per properti.
     *   weekly  → commission_percent_weekly
     *   monthly → commission_percent_monthly
     * NULL = belum diatur → 0 (tanpa komisi, opt-in). PPh 2% ditambahkan bila diatur.
     */
    private function resolveCommissionByStay(?Room $room, string $stayType): float
    {
        $hotel = $room?->hotel;
        if (!$hotel) return 0.0;
        $pct = $stayType === 'monthly' ? $hotel->commission_percent_monthly : $hotel->commission_percent_weekly;
        if ($pct === null) return 0.0;
        return ((float) $pct + self::PPH_PERCENT) / 100;
    }

    /**
     * Hitung harga final booking
     */
    public function calculate(array $params): array
    {
        [
            'room_id'          => $roomId,
            'check_in'         => $checkIn,
            'check_out'        => $checkOut,
            'promo_code'       => $promoCode,
            'user_id'          => $userId,
            'use_points'       => $usePoints,
            'points_to_redeem' => $pointsToRedeem,
            'room_count'       => $roomCount,
            'stay_type'        => $stayType,
            'stay_plan_index'  => $stayPlanIndex,
        ] = array_merge(['promo_code' => null, 'user_id' => null, 'use_points' => false, 'points_to_redeem' => null, 'room_count' => 1, 'stay_type' => 'daily', 'stay_plan_index' => 0], $params);

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

        // Long-stay: harga TETAP per kamar (mingguan 7 mlm / bulanan 30 mlm),
        // di-set owner/superadmin. TIDAK dari harga harian, TIDAK kena promo/campaign/rate-plan.
        $isLongStay       = in_array($stayType, ['weekly', 'monthly'], true);
        $ratePlan         = null;
        $ratePlanModifier = 1.0;
        $stayPlanLabel    = null;

        if ($isLongStay) {
            // Opsi/varian menginap lama (mis. "Tanpa IPL" / "Termasuk IPL") — pilih per index.
            $plans = $room->longStayPlans($stayType);
            $idx   = max(0, (int) $stayPlanIndex);
            $plan  = $plans[$idx] ?? ($plans[0] ?? null);
            if (!$plan) {
                throw new \InvalidArgumentException('Harga ' . ($stayType === 'weekly' ? 'mingguan' : 'bulanan') . ' belum tersedia untuk kamar ini.');
            }
            $stayPlanLabel = $plan['label'];
            $basePrice     = round((float) $plan['price'] * $roomCount, 2);
        } else {
            // 1. Base price = sum harga per tanggal × jumlah kamar.
            //    Owner bisa override harga per tanggal lewat "Atur Harga & Ketersediaan"
            //    (kolom room_prices.price). Kalau tidak di-set, pakai room.base_price.
            $basePrice = $this->calculateBasePrice($room, $ciDate, $coDate) * $roomCount;

            // 1b. Rate plan: pilih plan yang berlaku → apply multiplier × (1 − discount%)
            $ratePlan         = RatePlan::pickApplicable($room->hotel_id, $room->id, $nights, $checkIn, $checkOut);
            $ratePlanModifier = $ratePlan ? $ratePlan->priceModifier() : 1.0;
            if ($ratePlanModifier !== 1.0) {
                $basePrice = round($basePrice * $ratePlanModifier, 2);
            }
        }

        // Simpan harga sebelum promo untuk display "Rp X coret → Rp Y"
        $originalBasePrice = $basePrice;

        // 2. Promo discount — diterapkan ke BASE PRICE dulu (sebelum markup)
        //    agar konsisten dengan harga "discounted" di card kamar (yang juga
        //    dihitung dari base price, bukan subtotal).
        $hotelOwnerId = $room->hotel->owner_id ?? null;

        $campaignDiscount = 0;
        $campaign = null;
        $codeDiscount = 0;
        $promo = null;
        if (!$isLongStay) {
            // 2a. Diskon CAMPAIGN otomatis (owner mengikuti campaign / promo platform) —
            //     SELALU dihitung, tidak hilang walau ada kode promo manual. HANYA harian.
            if ($hotelOwnerId) {
                $best = \App\Services\OwnerDiscountService::best($hotelOwnerId, $basePrice);
                if ($best) {
                    $campaignDiscount = $best['discount'];
                    $campaign         = $best['campaign'];  // null kalau sumbernya promo-follow
                }
            }
            // 2b. Diskon KODE PROMO manual (kalau ada) — DI-STACK di atas diskon campaign.
            [$codeDiscount, $promo] = $this->applyPromo($promoCode, $basePrice, $hotelOwnerId, $room->hotel, $checkIn, $stayType);
        } else {
            // Long-stay: tanpa campaign auto, tapi promo KODE yang di-scope mingguan/bulanan TETAP berlaku.
            [$codeDiscount, $promo] = $this->applyPromo($promoCode, $basePrice, $hotelOwnerId, $room->hotel, $checkIn, $stayType);
        }

        // Total diskon = campaign + kode, dibatasi agar tidak melebihi harga.
        $promoDiscount = min($campaignDiscount + $codeDiscount, $originalBasePrice);
        $basePrice = round(max(0, $originalBasePrice - $promoDiscount), 2);

        // 3. Biaya Layanan (tampil ke customer sbg "Pajak & Others") — DITAMBAHKAN ke total.
        //    Dari setting superadmin: persen (dari harga POST-promo) ATAU nominal flat.
        //    Long-stay (mingguan/bulanan): TANPA biaya layanan — customer bayar harga tetap.
        if ($isLongStay) {
            $serviceFee = 0.0;
        } else {
            $sf = \App\Http\Controllers\Admin\SettingController::accommodationServiceFee();
            $serviceFee = ((float) ($sf['percent'] ?? 0)) > 0
                ? round($basePrice * ((float) $sf['percent'] / 100), 2)   // persen dari harga setelah promo
                : (float) ($sf['amount'] ?? 0);                            // nominal flat
        }
        $markupAmount = $serviceFee;            // disimpan di kolom markup_amount → baris "Pajak & Others"
        $subtotal     = $basePrice + $serviceFee;

        // ── Setoran owner & laba platform ───────────────────────────────────
        //   Komisi properti DIPOTONG dari setoran owner (BUKAN ditambah ke customer).
        //   Basis komisi = harga listed DIKURANGI diskon ArahInn saja:
        //     • Promo/campaign ARAHINN → owner menanggung: (harga − promoArahinn) × (1 − komisi)
        //     • Promo OWNER            → owner TIDAK menanggung (basis = harga listed penuh):
        //                                 harga × (1 − komisi)   → promo owner diserap ArahInn
        //   (PPh sudah termasuk di dalam komisi — internal platform, tak tampil ke customer)
        //   Harian → commission_percent; mingguan/bulanan → komisi long-stay per properti
        //   (NULL = belum diatur → 0). PPh 2% sudah termasuk di tiap rate.
        $commissionRate = $isLongStay
            ? $this->resolveCommissionByStay($room, $stayType)
            : $this->resolveCommission($room);

        $promoIsOwner = $promo && $promo->owner_id !== null;
        $rawDiscTotal = $campaignDiscount + $codeDiscount;
        $discScale    = $rawDiscTotal > 0 ? ($promoDiscount / $rawDiscTotal) : 0; // ≤1 bila ke-cap
        $discountOwner   = round(($promoIsOwner ? $codeDiscount : 0) * $discScale, 2);
        $discountArahinn = round(($campaignDiscount + ($promoIsOwner ? 0 : $codeDiscount)) * $discScale, 2);

        // Basis komisi = harga listed − diskon ArahInn (diskon owner TIDAK mengurangi basis).
        // Berlaku sama untuk harian & long-stay; rate yang membedakan.
        $commissionBase = max(0, $originalBasePrice - $discountArahinn);
        $ownerPayout    = round($commissionBase * (1 - $commissionRate), 2);
        // Laba ArahInn = yang dibayar customer untuk kamar (post-promo) − setoran owner + biaya layanan.
        $commissionProfit = round($basePrice - $ownerPayout + $serviceFee, 2);

        // Kept for compatibility (occupancy_rate masih dilaporkan di breakdown)
        $occupancyRate = $this->getOccupancyRate($roomId, $checkIn, $checkOut, $room->total_units);

        // 5. Loyalty points — 1 poin = Rp1, tanpa batas (s/d 100% subtotal).
        //    points_to_redeem (opsional) = nominal poin yang customer pilih untuk dipakai;
        //    kalau kosong tapi use_points true → pakai maksimal (saldo s/d subtotal).
        $loyaltyBalance  = $userId ? (\App\Models\User::find($userId)?->getLoyaltyBalance() ?? 0) : 0;
        $loyaltyDiscount = 0;
        $wantRedeem = $pointsToRedeem !== null ? max(0, (int) $pointsToRedeem) : null;
        if ($userId && ($usePoints || ($wantRedeem ?? 0) > 0)) {
            $maxRedeem       = min($loyaltyBalance, (int) floor($subtotal));
            $loyaltyDiscount = $wantRedeem !== null ? min($wantRedeem, $maxRedeem) : $maxRedeem;
            $subtotal       -= $loyaltyDiscount;
        }

        // 6. Pajak PPN — hanya kalau di-enable superadmin & BUKAN long-stay
        //    (mingguan/bulanan tanpa pajak — customer bayar harga tetap).
        $taxAmount = (!$isLongStay && $this->taxEnabled)
            ? round($subtotal * $this->taxPercent, 2)
            : 0.0;

        // 7. Kode unik 3-digit untuk transfer (di-skip utk long-stay agar total = harga tetap)
        $priceSuffix = $isLongStay ? 0 : random_int(1, 999);
        $totalPrice  = (int) ceil($subtotal + $taxAmount) + $priceSuffix;

        return [
            'nights'                => $nights,
            'stay_type'             => $stayType,
            'stay_plan_label'       => $stayPlanLabel,
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
            'loyalty_balance'  => (int) $loyaltyBalance,
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
    private function applyPromo(?string $code, float $amount, ?int $hotelOwnerId = null, $hotel = null, ?string $checkIn = null, string $stayType = 'daily'): array
    {
        if (!$code) return [0, null];

        $promo = Promo::where('code', $code)->active()->first();

        if (!$promo) {
            throw new \InvalidArgumentException('Kode promo tidak valid atau sudah kadaluarsa.');
        }

        // Owner-scoped promo: only valid for that owner's hotels (cast int)
        if ($promo->owner_id !== null && (int) $promo->owner_id !== (int) $hotelOwnerId) {
            throw new \InvalidArgumentException('Kode promo tidak berlaku untuk hotel ini.');
        }

        // Hotel-scoped promo: kalau promo dibatasi ke 1 properti, hanya berlaku di properti itu
        if ($promo->hotel_id !== null && $hotel && (int) $promo->hotel_id !== (int) $hotel->id) {
            throw new \InvalidArgumentException('Kode promo hanya berlaku untuk properti tertentu.');
        }

        // Kondisi opsional (weekday/weekend, jenis akomodasi, lokasi, tipe menginap)
        if ($err = $promo->conditionError($hotel, $checkIn, 'accommodation', $stayType)) {
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
