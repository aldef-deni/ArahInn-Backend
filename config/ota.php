<?php
// config/ota.php — OTA Platform Settings

return [
    'markup_percent'       => env('PLATFORM_MARKUP_PERCENT', 12),
    'tax_percent'          => env('TAX_PERCENT', 11),
    'booking_expiry_minutes' => env('BOOKING_EXPIRY_MINUTES', 30),
    'loyalty_per_1000'     => env('LOYALTY_POINTS_PER_1000', 1),
    'frontend_url'         => env('FRONTEND_URL', 'http://localhost:5173'),
];
