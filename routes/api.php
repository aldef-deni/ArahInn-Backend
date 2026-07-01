<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\HotelManageController;
use App\Http\Controllers\Admin\UserManageController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\MarketManagerController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\PropertyListingController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RatePlanController;
use App\Http\Controllers\RoomPriceController;
use App\Http\Controllers\HotelFeeController;
use App\Http\Controllers\HotelSettingsController;
use App\Http\Controllers\OwnerDashboardController;
use App\Http\Controllers\InteriorInquiryController;
use App\Http\Controllers\InteriorDesignController;
use App\Http\Controllers\PpobController;
use App\Http\Controllers\PpobCallbackController;
use App\Http\Controllers\XasController;
use App\Http\Controllers\AnalyticsController;

/*
|--------------------------------------------------------------------------
| API Routes — OTA Arahinn
| Prefix: /api/v1
|--------------------------------------------------------------------------
*/

// ── Health Check ─────────────────────────────────────
Route::get('/health', fn() => response()->json([
    'status'    => 'OK',
    'service'   => 'OTA Arahinn API',
    'version'   => '1.0.0',
    'timestamp' => now()->toISOString(),
]));

// ── Maintenance Status (Public) ──────────────────────
Route::get('/maintenance/status', fn() => response()->json([
    'success' => true,
    'data'    => \App\Http\Controllers\Admin\SettingController::maintenanceMode(),
]));

// ── Auth (Public) ─────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register',       [AuthController::class, 'register']);
    Route::post('/register-owner', [AuthController::class, 'registerOwner']);
    Route::post('/login',          [AuthController::class, 'login']);
    Route::post('/refresh-token',  [AuthController::class, 'refresh']);
    Route::post('/forgot-password',[AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // OAuth Google
    Route::get('/google',          [SocialAuthController::class, 'redirectGoogle']);
    Route::get('/google/callback', [SocialAuthController::class, 'callbackGoogle']);
    // Mobile native Google Sign-In — verify ID token, return auth token
    Route::post('/google/mobile',  [SocialAuthController::class, 'mobileGoogle']);

    // Mobile native Sign in with Apple — verify identity token, return auth token
    Route::post('/apple/mobile',   [SocialAuthController::class, 'mobileApple']);

    // OAuth Facebook
    Route::get('/facebook',          [SocialAuthController::class, 'redirectFacebook']);
    Route::get('/facebook/callback', [SocialAuthController::class, 'callbackFacebook']);
});

// ── Hotels (Owner - must be before public /{id} wildcard) ────────────
Route::get('/hotels/my-hotel',  [HotelController::class, 'myHotel'])
    ->middleware(['auth:sanctum', 'role:owner']);
Route::get('/hotels/my-hotels', [HotelController::class, 'myHotels'])
    ->middleware(['auth:sanctum', 'role:owner']);

// ── Hotels (Public) ───────────────────────────────────
Route::prefix('hotels')->group(function () {
    Route::get('/search',            [HotelController::class, 'search']);
    Route::get('/cities',            [HotelController::class, 'cities']);
    Route::get('/popular-destinations', [HotelController::class, 'popularDestinations']);
    Route::get('/{id}',              [HotelController::class, 'show']);
    Route::get('/{id}/rooms',        [RoomController::class, 'byHotel']);
    Route::get('/{id}/availability', [RoomController::class, 'availability']);
    Route::get('/{id}/reviews',      [ReviewController::class, 'byHotel']);
    Route::get('/{id}/campaigns',    [CampaignController::class, 'forHotel']);
});

// ── Promos (Public) ───────────────────────────────────
Route::prefix('promos')->group(function () {
    Route::get('/active',      [PromoController::class, 'active']);
    Route::get('/flash-sales', [PromoController::class, 'flashSales']);
    Route::get('/flyers',      [PromoController::class, 'flyers']);
    Route::get('/hotel/{hotelId}', [PromoController::class, 'hotelPromos']); // voucher owner utk properti
});

// ── Campaigns (Public: campaign platform aktif untuk ditampilkan di home) ──
Route::get('/campaigns/active', [CampaignController::class, 'activePublic']);

// ── Property Listings (Owner - must be before public /{id} wildcard) ────
Route::get('/properties/my-listings', [PropertyListingController::class, 'myListings'])
    ->middleware(['auth:sanctum', 'role:owner']);

// ── Property Listings (Public) ────────────────────────
Route::prefix('properties')->group(function () {
    Route::get('/',           [PropertyListingController::class, 'index']);
    Route::get('/pending',    [PropertyListingController::class, 'pending'])
        ->middleware(['auth:sanctum', 'role:superadmin|admin']);
    Route::get('/{id}',           [PropertyListingController::class, 'show']);
    Route::get('/{id}/reviews',   [ReviewController::class, 'byProperty']);
    Route::post('/{id}/approve', [PropertyListingController::class, 'approve'])
        ->middleware(['auth:sanctum', 'role:superadmin']);
    Route::post('/{id}/reject',  [PropertyListingController::class, 'reject'])
        ->middleware(['auth:sanctum', 'role:superadmin']);
});

// ── Interior Designs (Public — gallery untuk customer & owner) ───────
Route::get('/interior-designs', [InteriorDesignController::class, 'publicIndex']);
Route::get('/interior-wa', [SettingController::class, 'getInteriorWa']); // nomor WA konsultasi (publik)

// ── Interior Inquiries — submit publik (no auth required) ────────────
Route::post('/interior-inquiries', [InteriorInquiryController::class, 'store']);

// ── Payment Webhooks (Public — no auth) ──────────────
Route::post('/payments/webhook/doku', [PaymentController::class, 'webhookDoku']);

// ── Payment mode info (Public) ───────────────────────
Route::get('/payments/mode', [PaymentController::class, 'mode']);

// ── PPOB (Public catalog) ─────────────────────────────
Route::prefix('ppob')->group(function () {
    Route::get('/categories', [PpobController::class, 'categories']);
    Route::get('/products',   [PpobController::class, 'products']);
});

// ── Travel settings (public: markup per pax) ──────────
Route::get('/travel/settings', [\App\Http\Controllers\TravelController::class, 'settings']);

// ── Travel KERETA (Rajabiller API langsung) ───────────
// Public read-only: cari jadwal, stasiun, denah kursi (tanpa uang).
Route::prefix('travel/train')->group(function () {
    Route::get('/stations',  [\App\Http\Controllers\TravelController::class, 'stations']);
    Route::post('/search',   [\App\Http\Controllers\TravelController::class, 'search']);
    Route::post('/seat-layout', [\App\Http\Controllers\TravelController::class, 'seatLayout']);
});

// ── Travel PESAWAT (public read-only: bandara, maskapai, cari, fare) ──
Route::prefix('travel/flight')->group(function () {
    Route::get('/airports',  [\App\Http\Controllers\TravelController::class, 'airports']);
    Route::get('/airlines',  [\App\Http\Controllers\TravelController::class, 'airlines']);
    Route::post('/search',     [\App\Http\Controllers\TravelController::class, 'searchFlight']);
    Route::post('/search-all', [\App\Http\Controllers\TravelController::class, 'searchAllFlight']);
    Route::post('/fare',       [\App\Http\Controllers\TravelController::class, 'flightFare']);
});

// ── Travel PELNI (public read-only: pelabuhan, cari, cek kuota) ────
Route::prefix('travel/pelni')->group(function () {
    Route::get('/origins',           [\App\Http\Controllers\TravelController::class, 'pelniOrigins']);
    Route::get('/destinations',      [\App\Http\Controllers\TravelController::class, 'pelniDestinations']);
    Route::post('/search',           [\App\Http\Controllers\TravelController::class, 'searchPelni']);
    Route::post('/check-availability',[\App\Http\Controllers\TravelController::class, 'pelniCheckAvailability']);
});

// ── PPOB Callback dari Rajabiller (IP whitelisted, public) ─────────
Route::prefix('ppob/callback/rajabiller')->middleware('rajabiller.whitelist')->group(function () {
    Route::post('/transaction',  [PpobCallbackController::class, 'transaction']);
    Route::post('/product-info', [PpobCallbackController::class, 'productInfo']);
});

// ── XAS Travel Callback (Public, validated via token_mitra header) ──
Route::post('/xas/callback', [XasController::class, 'callback']);

// =====================================================
// AUTHENTICATED ROUTES
// =====================================================
Route::middleware('auth:sanctum')->group(function () {

    // ── Auth ─────────────────────────────────────────
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    // Hapus akun (wajib Apple App Store guideline 5.1.1(v) — account deletion in-app)
    Route::delete('/auth/account', [AuthController::class, 'deleteAccount']);

    // ── Wishlist (customer: simpan hotel & properti favorit) ──
    Route::prefix('wishlist')->group(function () {
        Route::get('/',        [\App\Http\Controllers\WishlistController::class, 'index']);
        Route::get('/ids',     [\App\Http\Controllers\WishlistController::class, 'ids']);
        Route::get('/config',  [\App\Http\Controllers\WishlistController::class, 'config']);
        Route::post('/toggle', [\App\Http\Controllers\WishlistController::class, 'toggle']);
        Route::delete('/{id}', [\App\Http\Controllers\WishlistController::class, 'destroy']);
    });

    // ── Property Listings (Owner) ─────────────────────
    Route::prefix('properties')->middleware('role:owner|admin|superadmin')->group(function () {
        Route::post('/',      [PropertyListingController::class, 'store']);
        Route::put('/{id}',   [PropertyListingController::class, 'update']);
        Route::delete('/{id}',[PropertyListingController::class, 'destroy']);
    });

    // ── Hotels (Owner / Admin) ────────────────────────
    Route::prefix('hotels')->middleware('role:owner|admin|superadmin')->group(function () {
        Route::post('/',                    [HotelController::class, 'store']);
        Route::put('/{id}',                 [HotelController::class, 'update']);
        Route::post('/{id}/rooms',          [RoomController::class, 'store']);
        Route::put('/{hotelId}/rooms/{roomId}',   [RoomController::class, 'update']);
        Route::delete('/{hotelId}/rooms/{roomId}', [RoomController::class, 'destroy']);

        // ── Harga & Ketersediaan ───────────────────────
        // Settings (pricing model, child policy)
        Route::get('/{hotelId}/settings',   [HotelSettingsController::class, 'show']);
        Route::put('/{hotelId}/settings',   [HotelSettingsController::class, 'update']);

        // Rate Plans
        Route::get('/{hotelId}/rate-plans',              [RatePlanController::class, 'index']);
        Route::post('/{hotelId}/rate-plans',             [RatePlanController::class, 'store']);
        Route::get('/{hotelId}/rate-plans/{planId}',     [RatePlanController::class, 'show']);
        Route::put('/{hotelId}/rate-plans/{planId}',     [RatePlanController::class, 'update']);
        Route::delete('/{hotelId}/rate-plans/{planId}',  [RatePlanController::class, 'destroy']);

        // Per-date room prices & availability
        Route::get('/{hotelId}/rooms/{roomId}/prices',  [RoomPriceController::class, 'index']);
        Route::put('/{hotelId}/rooms/{roomId}/prices',  [RoomPriceController::class, 'upsert']);
        Route::put('/{hotelId}/rooms/{roomId}/toggle-now', [RoomPriceController::class, 'toggleNow']);

        // Bulk update
        Route::post('/{hotelId}/rooms/prices/bulk',     [RoomPriceController::class, 'bulk']);

        // Range view (semua kamar × tanggal) untuk Softblock Allotment
        Route::get('/{hotelId}/rooms/prices/range',     [RoomPriceController::class, 'range']);

        // Hotel fees (biaya tambahan)
        Route::get('/{hotelId}/fees',             [HotelFeeController::class, 'index']);
        Route::post('/{hotelId}/fees',            [HotelFeeController::class, 'store']);
        Route::put('/{hotelId}/fees/{feeId}',     [HotelFeeController::class, 'update']);
        Route::delete('/{hotelId}/fees/{feeId}',  [HotelFeeController::class, 'destroy']);
    });
    Route::post('/hotels/{id}/approve', [HotelController::class, 'approve'])
        ->middleware('role:superadmin');
    Route::post('/hotels/{id}/block', [HotelController::class, 'block'])
        ->middleware('role:superadmin');

    // ── Bookings ──────────────────────────────────────
    Route::prefix('bookings')->group(function () {
        Route::post('/calculate-price', [BookingController::class, 'calculatePrice']);
        Route::post('/',               [BookingController::class, 'store']);
        Route::get('/my-orders',       [BookingController::class, 'myOrders']);
        Route::get('/{id}',            [BookingController::class, 'show']);
        Route::put('/{id}/cancel',     [BookingController::class, 'cancel']);
        Route::put('/{id}/reschedule', [BookingController::class, 'reschedule']);
        Route::post('/{id}/resend-voucher', [BookingController::class, 'resendVoucher']);
        Route::get('/{id}/voucher',         [BookingController::class, 'downloadVoucher']);
        Route::post('/{id}/refund',    [BookingController::class, 'refund'])
            ->middleware('role:superadmin|admin|finance');
        // Admin: all bookings
        Route::get('/', [BookingController::class, 'index'])
            ->middleware('role:superadmin|admin|finance|owner');
        // Hapus massal pesanan — gate KERAS per-email di controller (khusus aldeftech@gmail.com)
        Route::post('/bulk-delete', [BookingController::class, 'bulkDestroy'])
            ->middleware('role:superadmin');
    });

    // ── Orders ────────────────────────────────────────
    Route::prefix('orders')->middleware('role:superadmin|admin|finance|owner')->group(function () {
        Route::get('/',              [OrderController::class, 'index']);
        Route::put('/{id}/status',  [OrderController::class, 'updateStatus'])
            ->middleware('role:superadmin|admin');
    });

    // ── Payments ──────────────────────────────────────
    Route::prefix('payments')->group(function () {
        Route::post('/initiate',     [PaymentController::class, 'initiate']);
        Route::get('/{bookingId}/status', [PaymentController::class, 'status']);
        // Manual transfer mode: customer upload bukti transfer
        Route::post('/{bookingId}/upload-proof', [PaymentController::class, 'uploadProof']);
        // Admin: konfirmasi manual transfer setelah cek mutasi rekening
        Route::post('/{bookingId}/confirm-manual', [PaymentController::class, 'confirmManual'])
            ->middleware('role:superadmin|admin|finance');
        Route::get('/', [PaymentController::class, 'index'])
            ->middleware('role:superadmin|admin|finance');
    });

    // ── Promos ────────────────────────────────────────
    Route::prefix('promos')->group(function () {
        Route::post('/validate', [PromoController::class, 'validate']);

        // Owner-only: list promo sendiri + follow promo platform
        Route::middleware('role:owner')->group(function () {
            Route::get('/my',                 [PromoController::class, 'myPromos']);
            Route::get('/platform',           [PromoController::class, 'platformPromos']);
            Route::post('/{id}/follow',       [PromoController::class, 'follow']);
            Route::delete('/{id}/follow',     [PromoController::class, 'unfollow']);
        });

        // Admin-only: lihat semua promo + dropdown owner
        Route::middleware('role:admin|superadmin')->group(function () {
            Route::get('/',           [PromoController::class, 'index']);
            Route::get('/owners-list',[PromoController::class, 'ownersList']);
        });

        // Shared: create/update/delete. Controller PromoController otomatis set
        // owner_id sesuai role (owner → promo miliknya; admin → dari request).
        // Digabung supaya tidak saling menimpa (dulu route admin menutup route owner → owner 403).
        Route::middleware('role:owner|admin|superadmin')->group(function () {
            Route::post('/',          [PromoController::class, 'store']);
            Route::put('/{id}',       [PromoController::class, 'update']);
            Route::delete('/{id}',    [PromoController::class, 'destroy']);
        });

        // Loyalty
        Route::get('/loyalty/balance',  [LoyaltyController::class, 'balance']);
        Route::get('/loyalty/summary',  [LoyaltyController::class, 'summary']);
        Route::get('/loyalty/history',  [LoyaltyController::class, 'history']);
        Route::post('/loyalty/redeem',  [LoyaltyController::class, 'redeem']);
    });

    // ── Superadmin: Loyalty (konfigurasi + manajemen poin/tier member) ──────
    Route::prefix('admin/loyalty')->middleware('role:superadmin')->group(function () {
        Route::get('/config',              [\App\Http\Controllers\Admin\LoyaltyController::class, 'getConfig']);
        Route::post('/config',             [\App\Http\Controllers\Admin\LoyaltyController::class, 'setConfig']);
        Route::get('/users',               [\App\Http\Controllers\Admin\LoyaltyController::class, 'users']);
        Route::post('/users/{id}/adjust',  [\App\Http\Controllers\Admin\LoyaltyController::class, 'adjust']);
        Route::post('/users/{id}/tier',    [\App\Http\Controllers\Admin\LoyaltyController::class, 'setTier']);
    });

    // ── Superadmin: MM Handler (assign owners to Market Managers) ────────
    Route::prefix('admin/mm-handler')->middleware('role:superadmin')->group(function () {
        Route::get('/',                [MarketManagerController::class, 'listMMs']);
        Route::get('/{mmId}/owners',   [MarketManagerController::class, 'getMMOwners']);
        Route::post('/{mmId}/owners',  [MarketManagerController::class, 'setMMOwners']);
    });

    // ── Owner Dashboard ───────────────────────────────────────────────────
    Route::get('/owner/dashboard', [OwnerDashboardController::class, 'index'])
        ->middleware('role:owner');

    // ── Owner: get my Market Manager ──────────────────────────────────────
    Route::get('/owner/market-manager', [MarketManagerController::class, 'myMarketManager'])
        ->middleware('role:owner');

    // ── Campaigns (Owner: lihat semua campaign global + ikut/berhenti) ────
    Route::get('/campaigns/my', [CampaignController::class, 'myList'])
        ->middleware('role:owner');
    Route::post('/campaigns/{id}/follow', [CampaignController::class, 'follow'])
        ->middleware('role:owner');
    Route::delete('/campaigns/{id}/follow', [CampaignController::class, 'unfollow'])
        ->middleware('role:owner');

    // ── Users ─────────────────────────────────────────
    Route::prefix('users')->group(function () {
        Route::get('/profile',          [UserController::class, 'profile']);
        Route::put('/profile',          [UserController::class, 'updateProfile']);
        Route::post('/profile/avatar',  [UserController::class, 'updateAvatar']);
        Route::put('/change-password',  [UserController::class, 'changePassword']);

        Route::middleware('role:superadmin|admin')->group(function () {
            Route::get('/',              [UserController::class, 'index']);
            Route::get('/{id}',          [UserController::class, 'show']);
            Route::put('/{id}/role',     [UserManageController::class, 'changeRole'])
                ->middleware('role:superadmin');
            Route::put('/{id}/status',   [UserManageController::class, 'toggleStatus']);
        });
        Route::middleware('role:superadmin')->group(function () {
            Route::post('/',             [UserManageController::class, 'store']);
            Route::put('/{id}',          [UserManageController::class, 'update']);
            Route::delete('/{id}',       [UserManageController::class, 'destroy']);
        });
    });

    // ── Reviews ───────────────────────────────────────
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::get('/reviews/mine', [ReviewController::class, 'myReviews']);
    Route::get('/reviews/eligibility/hotel/{hotelId}', [ReviewController::class, 'eligibility']);
    Route::prefix('admin/reviews')->middleware('role:superadmin|admin')->group(function () {
        Route::get('/',              [ReviewController::class, 'adminIndex']);
        Route::post('/{id}/approve', [ReviewController::class, 'approve']);
        Route::post('/{id}/reject',  [ReviewController::class, 'reject']);
    });

    // ── Notifications ─────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/',            [NotificationController::class, 'index']);
        Route::get('/unread-count',[NotificationController::class, 'unreadCount']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllRead']);
        Route::post('/{id}/read', [NotificationController::class, 'markRead']);
    });

    // ── Device tokens (push notification) ─────────────
    Route::post('/devices/register',   [DeviceTokenController::class, 'register']);
    Route::post('/devices/unregister', [DeviceTokenController::class, 'unregister']);

    // ── Chat ──────────────────────────────────────────
    Route::prefix('chat')->group(function () {
        Route::get('/rooms',           [ChatController::class, 'myRooms']);
        Route::post('/rooms',          [ChatController::class, 'createRoom']);
        Route::get('/rooms/{id}',      [ChatController::class, 'showRoom']);
        Route::get('/rooms/{id}/messages', [ChatController::class, 'messages']);
        Route::post('/rooms/{id}/messages',[ChatController::class, 'sendMessage']);
        Route::get('/all-rooms', [ChatController::class, 'allRooms'])
            ->middleware('role:superadmin|admin|admin_property');
        Route::get('/owner-rooms', [ChatController::class, 'ownerRooms'])
            ->middleware('role:owner');

        // Support chat: customer ↔ Arahinn CS (no booking needed)
        Route::get('/support/my-room',  [ChatController::class, 'mySupportRoom']);
        Route::get('/support/rooms',    [ChatController::class, 'adminSupportRooms'])
            ->middleware('role:superadmin|admin');

        // Inquiry chat: customer ↔ Owner penginapan (pra-booking)
        Route::post('/inquiry',           [ChatController::class, 'inquiryRoom']);
        Route::get('/inquiry/my-rooms',   [ChatController::class, 'myInquiries']);
        Route::get('/owner-inquiries',    [ChatController::class, 'ownerInquiries'])
            ->middleware('role:owner');
    });

    // ── Admin ─────────────────────────────────────────
    Route::prefix('admin')->middleware('role:superadmin|admin|finance')->group(function () {
        Route::get('/dashboard',           [DashboardController::class, 'index']);
        Route::get('/reports/revenue',     [ReportController::class, 'revenue']);
        Route::get('/reports/bookings',    [ReportController::class, 'bookings']);
        Route::get('/reports/canceled',    [ReportController::class, 'canceled']);
        Route::get('/reports/profit',      [ReportController::class, 'profit']);

        // Analytics (overview/users/bookings/top-hotels)
        Route::get('/analytics/overview',   [AnalyticsController::class, 'overview']);
        Route::get('/analytics/users',      [AnalyticsController::class, 'users']);
        Route::get('/analytics/bookings',   [AnalyticsController::class, 'bookings']);
        Route::get('/analytics/top-hotels', [AnalyticsController::class, 'topHotels']);
        Route::get('/logs',                [DashboardController::class, 'logs'])
            ->middleware('role:superadmin');
        // Finance can read hotels list (for report filters)
        Route::get('/hotels',         [HotelManageController::class, 'index']);
        Route::prefix('hotels')->middleware('role:superadmin|admin')->group(function () {
            Route::get('/pending',       [HotelManageController::class, 'pending']);
            Route::post('/',             [HotelManageController::class, 'store']);
            // Hapus massal akomodasi (HARD DELETE + booking history) — gate KERAS per-email
            // di controller (khusus aldeftech@gmail.com). Superadmin biasa tidak bisa hapus.
            Route::post('/bulk-delete',  [HotelManageController::class, 'bulkDestroy']);
            Route::put('/{id}',          [HotelManageController::class, 'update']);
            Route::post('/{id}/approve', [HotelManageController::class, 'approve']);
            Route::post('/{id}/block',   [HotelManageController::class, 'block']);
            Route::put('/{id}/commission', [HotelManageController::class, 'updateCommission'])
                ->middleware('role:superadmin');
        });
        // Campaigns (CRUD admin only)
        Route::prefix('campaigns')->group(function () {
            Route::get('/',       [CampaignController::class, 'index']);
            Route::post('/',      [CampaignController::class, 'store']);
            Route::put('/{id}',   [CampaignController::class, 'update']);
            Route::delete('/{id}',[CampaignController::class, 'destroy']);
        });

        // Wishlist config (superadmin)
        Route::get('/settings/wishlist',  [SettingController::class, 'getWishlistConfig'])
            ->middleware('role:superadmin');
        Route::post('/settings/wishlist', [SettingController::class, 'setWishlistConfig'])
            ->middleware('role:superadmin');

        Route::get('/settings/payment-gateways',  [SettingController::class, 'getGateways'])
            ->middleware('role:superadmin');
        Route::post('/settings/payment-gateways', [SettingController::class, 'setGateway'])
            ->middleware('role:superadmin');

        // Manual bank settings (rekening pembayaran transfer manual)
        Route::get('/settings/payment-mode',     [SettingController::class, 'getPaymentMode'])
            ->middleware('role:superadmin');
        Route::post('/settings/payment-mode',    [SettingController::class, 'setPaymentMode'])
            ->middleware('role:superadmin');
        Route::get('/settings/payment-manual',   [SettingController::class, 'getPaymentManual'])
            ->middleware('role:superadmin');
        Route::post('/settings/payment-manual',  [SettingController::class, 'setPaymentManual'])
            ->middleware('role:superadmin');

        // Maintenance mode (superadmin only)
        Route::get('/settings/maintenance',  [SettingController::class, 'getMaintenanceMode'])
            ->middleware('role:superadmin');
        Route::post('/settings/maintenance', [SettingController::class, 'setMaintenanceMode'])
            ->middleware('role:superadmin');

        // PPN tax toggle (superadmin only)
        Route::get('/settings/ppn-tax',  [SettingController::class, 'getPpnTax'])
            ->middleware('role:superadmin');
        Route::post('/settings/ppn-tax', [SettingController::class, 'setPpnTax'])
            ->middleware('role:superadmin');

        // Markup travel (superadmin only)
        Route::get('/settings/travel-markup',  [SettingController::class, 'getTravelMarkup'])
            ->middleware('role:superadmin');
        Route::post('/settings/travel-markup', [SettingController::class, 'setTravelMarkup'])
            ->middleware('role:superadmin');

        // Biaya layanan akomodasi (superadmin only)
        Route::get('/settings/accommodation-service-fee',  [SettingController::class, 'getAccommodationServiceFee'])
            ->middleware('role:superadmin');
        Route::post('/settings/accommodation-service-fee', [SettingController::class, 'setAccommodationServiceFee'])
            ->middleware('role:superadmin');

        // Biaya penanganan tiket per moda (superadmin only)
        Route::get('/settings/travel-service-fee',  [SettingController::class, 'getTravelServiceFee'])
            ->middleware('role:superadmin');
        Route::post('/settings/travel-service-fee', [SettingController::class, 'setTravelServiceFee'])
            ->middleware('role:superadmin');

        // Nomor WA konsultasi Design Interior (superadmin/admin/design_interior)
        Route::get('/settings/interior-wa',  [SettingController::class, 'getInteriorWa'])
            ->middleware('role:superadmin|admin|design_interior');
        Route::post('/settings/interior-wa', [SettingController::class, 'setInteriorWa'])
            ->middleware('role:superadmin|admin|design_interior');
    });

    // ── Interior Designs ─────────────────────────────────────────────
    Route::prefix('admin/interior-designs')->middleware('role:superadmin|admin|owner|design_interior')->group(function () {
        Route::get('/',                  [InteriorDesignController::class, 'index']);
        Route::post('/',                 [InteriorDesignController::class, 'store']);
        Route::post('/{id}',             [InteriorDesignController::class, 'update']); // POST karena multipart/form-data
        Route::delete('/{id}',           [InteriorDesignController::class, 'destroy']);
        Route::post('/{id}/approve',     [InteriorDesignController::class, 'approve'])->middleware('role:superadmin');
        Route::post('/{id}/reject',      [InteriorDesignController::class, 'reject'])->middleware('role:superadmin');
    });

    // ── Interior Inquiries — admin list & status (auth required) ────────
    Route::prefix('interior-inquiries')->middleware('role:superadmin|admin')->group(function () {
        Route::get('/',           [InteriorInquiryController::class, 'index']);
        Route::put('/{id}/status',[InteriorInquiryController::class, 'updateStatus']);
    });

    // ── XAS Travel (authenticated user) ──────────────────────────────
    // Customer request credential untuk embed Travel webview (pesawat/kereta/dlu/pelni)
    Route::post('/xas/credential', [XasController::class, 'createCredential']);

    // ── PPOB (authenticated user) ────────────────────────────────────
    Route::prefix('ppob')->group(function () {
        Route::post('/purchase',                    [PpobController::class, 'purchase']);
        Route::post('/inquiry',                     [PpobController::class, 'inquiry']);
        Route::post('/transactions/{trxCode}/confirm-pay', [PpobController::class, 'confirmPay']);
        Route::get('/my-transactions',              [PpobController::class, 'myTransactions']);
        Route::get('/transactions/{trxCode}',       [PpobController::class, 'show']);
        Route::get('/transactions/{trxCode}/receipt', [PpobController::class, 'downloadReceipt']);
        Route::get('/transactions/{trxCode}/invoice', [PpobController::class, 'downloadInvoice']);
    });

    // ── Travel KERETA (authenticated: booking flow) ──────────────────
    Route::prefix('travel/train')->group(function () {
        Route::post('/book',         [\App\Http\Controllers\TravelController::class, 'book']);
        Route::post('/change-seat',  [\App\Http\Controllers\TravelController::class, 'changeSeat']);
        Route::post('/cancel',       [\App\Http\Controllers\TravelController::class, 'cancel']);
        Route::get('/status/{bookCode}', [\App\Http\Controllers\TravelController::class, 'status']);
    });

    // ── Travel PESAWAT (authenticated: booking) ──────────────────────
    Route::prefix('travel/flight')->group(function () {
        Route::post('/book', [\App\Http\Controllers\TravelController::class, 'bookFlight']);
    });

    // ── Travel BOOKING + PAYMENT (checkout → pay → e-tiket) ───────────
    Route::prefix('travel')->group(function () {
        Route::post('/checkout',           [\App\Http\Controllers\TravelBookingController::class, 'checkout']);
        Route::post('/promo/validate',     [\App\Http\Controllers\TravelBookingController::class, 'validatePromo']);
        Route::get('/bookings',            [\App\Http\Controllers\TravelBookingController::class, 'myBookings']);
        Route::get('/bookings/{id}',       [\App\Http\Controllers\TravelBookingController::class, 'show']);
        Route::get('/bookings/{id}/etiket', [\App\Http\Controllers\TravelBookingController::class, 'downloadEtiket']);
    });

    // ── Admin: verifikasi pembayaran travel → terbitkan e-tiket ──────
    Route::prefix('admin/travel')->middleware('role:superadmin|admin|finance')->group(function () {
        Route::get('/bookings',             [\App\Http\Controllers\TravelBookingController::class, 'adminBookings']);
        // Hapus massal — gate KERAS per-email di controller (khusus aldeftech@gmail.com)
        Route::post('/bookings/bulk-delete', [\App\Http\Controllers\TravelBookingController::class, 'adminBulkDestroy']);
        Route::post('/bookings/{id}/issue', [\App\Http\Controllers\TravelBookingController::class, 'adminIssue']);
        Route::post('/bookings/{id}/cancel',[\App\Http\Controllers\TravelBookingController::class, 'adminCancel']);
    });

    Route::prefix('admin/ppob')->middleware('role:superadmin|admin|finance')->group(function () {
        Route::get('/transactions',                            [PpobController::class, 'adminIndex']);
        // Hapus massal — gate KERAS per-email di controller (khusus aldeftech@gmail.com)
        Route::post('/transactions/bulk-delete',               [PpobController::class, 'adminBulkDestroy']);
        Route::post('/transactions/{trxCode}/mark-paid',       [PpobController::class, 'adminMarkPaid']);
        Route::post('/transactions/{trxCode}/cancel',          [PpobController::class, 'adminCancel']);
        Route::post('/transactions/{trxCode}/refund',          [PpobController::class, 'adminRefund']);
        Route::post('/transactions/{trxCode}/retry',           [PpobController::class, 'adminRetry']);
        Route::get('/balance',                                 [PpobController::class, 'adminBalance']);
        Route::get('/categories',                              [PpobController::class, 'adminCategories']);
        Route::put('/categories/{id}',                         [PpobController::class, 'adminUpdateCategory'])->middleware('role:superadmin|admin');
        Route::post('/sync-catalog',                           [PpobController::class, 'adminSyncCatalog'])->middleware('role:superadmin');
    });
});
