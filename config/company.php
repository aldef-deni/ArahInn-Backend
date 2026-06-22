<?php

/**
 * Identitas perusahaan untuk dokumen Invoice / Bukti Transaksi.
 * ⚠️ ISI dengan data legal ArahInn yang sebenarnya (NPWP, nama PT, alamat).
 * Bisa di-override via .env (COMPANY_*).
 */
return [
    // Sembunyikan blok legal (Nama PT, NPWP, alamat) di invoice sampai akte/NPWP beres.
    // Set COMPANY_SHOW_LEGAL=true di .env saat sudah siap.
    'show_legal' => env('COMPANY_SHOW_LEGAL', false),

    'name'    => env('COMPANY_NAME', 'PT ArahInn Digital Nusantara'),
    'npwp'    => env('COMPANY_NPWP', '—'),               // mis. 31.371.281.2.018.000
    'address' => env('COMPANY_ADDRESS', 'Indonesia'),    // alamat lengkap
    'phone'   => env('COMPANY_PHONE', '0804 1500 878'),
    'email'   => env('COMPANY_EMAIL', 'cs@arahinn.com'),
    'cs_hours'=> env('COMPANY_CS_HOURS', '24 Jam'),
    // Pajak sudah termasuk di harga (informatif di invoice).
    'tax_note'=> env('COMPANY_TAX_NOTE', 'Harga sudah termasuk pajak sesuai ketentuan yang berlaku.'),
];
