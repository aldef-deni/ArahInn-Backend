<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Promo;

class OwnerDiscountService
{
    /**
     * Diskon auto terbaik untuk hotel milik $ownerId pada harga $amount:
     * memilih yang TERBESAR antara promo platform yang di-follow ATAU
     * campaign yang di-follow owner. Seri → campaign menang.
     *
     * Return null kalau owner tidak follow apa pun yang memberi diskon.
     * Bentuk return (dibuat kompatibel dengan struktur applied_promo di FE):
     * [
     *   'source'   => 'promo'|'campaign',
     *   'discount' => float,   // nominal diskon
     *   'final'    => float,   // harga setelah diskon
     *   'original' => float,   // = $amount
     *   'promo'    => Promo|null,
     *   'campaign' => Campaign|null,
     *   'applied'  => ['id','name','code','discount_type','discount_value'],
     * ]
     */
    public static function best(?int $ownerId, float $amount): ?array
    {
        if (!$ownerId || $amount <= 0) {
            return null;
        }

        $bestPromo    = Promo::bestForOwner($ownerId, $amount);
        $bestCampaign = Campaign::bestForOwner($ownerId, $amount);

        $promoDisc = $bestPromo['discount']    ?? 0;
        $campDisc  = $bestCampaign['discount'] ?? 0;

        if ($promoDisc <= 0 && $campDisc <= 0) {
            return null;
        }

        // Campaign menang saat diskonnya >= promo (termasuk seri).
        if ($campDisc > 0 && $campDisc >= $promoDisc) {
            $c = $bestCampaign['campaign'];
            return [
                'source'   => 'campaign',
                'discount' => $bestCampaign['discount'],
                'final'    => $bestCampaign['final'],
                'original' => $amount,
                'promo'    => null,
                'campaign' => $c,
                'applied'  => [
                    'id'             => $c->id,
                    'name'           => $c->title,
                    'code'           => null,
                    'discount_type'  => 'percent',
                    'discount_value' => (float) $c->discount_percent,
                ],
            ];
        }

        $p = $bestPromo['promo'];
        return [
            'source'   => 'promo',
            'discount' => $bestPromo['discount'],
            'final'    => $bestPromo['final'],
            'original' => $amount,
            'promo'    => $p,
            'campaign' => null,
            'applied'  => [
                'id'             => $p->id,
                'name'           => $p->name,
                'code'           => $p->code,
                'discount_type'  => $p->discount_type,
                'discount_value' => (float) $p->discount_value,
            ],
        ];
    }
}
