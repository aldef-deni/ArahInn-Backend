<?php

namespace Database\Seeders;

use App\Models\PpobCategory;
use Illuminate\Database\Seeder;

class PpobCategorySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['code' => 'pulsa',        'name' => 'Pulsa Prabayar',  'group' => 'pulsa',   'type' => 'prabayar',   'icon' => 'Smartphone',   'color' => '#2563eb', 'markup' => 500,  'order' => 1],
            ['code' => 'paket_data',   'name' => 'Paket Data',      'group' => 'pulsa',   'type' => 'prabayar',   'icon' => 'Wifi',         'color' => '#0891b2', 'markup' => 1000, 'order' => 2],
            ['code' => 'pln_token',    'name' => 'Token PLN',       'group' => 'pln',     'type' => 'prabayar',   'icon' => 'Zap',          'color' => '#facc15', 'markup' => 1500, 'order' => 3],
            ['code' => 'pln_postpaid', 'name' => 'Tagihan PLN',     'group' => 'tagihan', 'type' => 'pascabayar', 'icon' => 'Lightbulb',    'color' => '#f59e0b', 'markup' => 2500, 'order' => 4],
            ['code' => 'pdam',         'name' => 'Tagihan PDAM',    'group' => 'tagihan', 'type' => 'pascabayar', 'icon' => 'Droplets',     'color' => '#0ea5e9', 'markup' => 2500, 'order' => 5],
            ['code' => 'bpjs',         'name' => 'BPJS Kesehatan',  'group' => 'tagihan', 'type' => 'pascabayar', 'icon' => 'HeartPulse',   'color' => '#16a34a', 'markup' => 2500, 'order' => 6],
            ['code' => 'telkom',       'name' => 'Telkom / Indihome','group' => 'tagihan', 'type' => 'pascabayar', 'icon' => 'Router',       'color' => '#dc2626', 'markup' => 2500, 'order' => 7],
            ['code' => 'ewallet_ovo',     'name' => 'OVO',           'group' => 'ewallet', 'type' => 'prabayar', 'icon' => 'Wallet',        'color' => '#7c3aed', 'markup' => 1000, 'order' => 10],
            ['code' => 'ewallet_gopay',   'name' => 'GoPay',         'group' => 'ewallet', 'type' => 'prabayar', 'icon' => 'Wallet',        'color' => '#16a34a', 'markup' => 1000, 'order' => 11],
            ['code' => 'ewallet_dana',    'name' => 'DANA',          'group' => 'ewallet', 'type' => 'prabayar', 'icon' => 'Wallet',        'color' => '#0ea5e9', 'markup' => 1000, 'order' => 12],
            ['code' => 'ewallet_shopeepay','name' => 'ShopeePay',    'group' => 'ewallet', 'type' => 'prabayar', 'icon' => 'Wallet',        'color' => '#f97316', 'markup' => 1000, 'order' => 13],
        ];

        foreach ($items as $i) {
            PpobCategory::updateOrCreate(
                ['code' => $i['code']],
                [
                    'name'          => $i['name'],
                    'group'         => $i['group'],
                    'type'          => $i['type'],
                    'icon'          => $i['icon'],
                    'color'         => $i['color'],
                    'markup_amount' => $i['markup'],
                    'sort_order'    => $i['order'],
                    'is_active'     => true,
                ]
            );
        }
    }
}
