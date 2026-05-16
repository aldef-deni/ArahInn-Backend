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
use App\Http\Controllers\PropertyListingController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RatePlanController;
use App\Http\Controllers\RoomPriceController;
use App\Http\Controllers\HotelFeeController;
use App\Http\Controllers\HotelSettingsController;
use App\Http\Controllers\OwnerDashboardController;
use App\Http\Controllers\InteriorInquiryController;
use App\Http\Controllers\InteriorDesignController;

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

// ── Auth (Public) ─────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register',       [AuthController::class, 'register']);
    Route::post('/login',          [AuthController::class, 'login']);
    Route::post('/refresh-token',  [AuthController::class, 'refresh']);
    Route::post('/forgot-password',[AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // OAuth Google
    Route::get('/google',          [SocialAuthController::class, 'redirectGoogle']);
    Route::get('/google/callback', [SocialAuthController::class, 'callbackGoogle']);

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
});

// ── Property Listings (Owner - must be before public /{id} wildcard) ────
Route::get('/properties/my-listings', [PropertyListingController::class, 'myListings'])
    ->middleware(['auth:sanctum', 'role:owner']);

// ── Property Listings (Public) ────────────────────────
Route::prefix('properties')->group(function () {
    Route::get('/',           [PropertyListingController::class, 'index']);
    Route::get('/pending',    [PropertyListingController::class, 'pending'])
        ->middleware(['auth:sanctum', 'role:superadmin|admin']);
    Route::get('/{id}',       [PropertyListingController::class, 'show']);
    Route::post('/{id}/approve', [PropertyListingController::class, 'approve'])
        ->middleware(['auth:sanctum', 'role:superadmin']);
    Route::post('/{id}/reject',  [PropertyListingController::class, 'reject'])
        ->middleware(['auth:sanctum', 'role:superadmin']);
});

// ── Interior Designs (Public — gallery untuk customer & owner) ───────
Route::get('/interior-designs', [InteriorDesignController::class, 'publicIndex']);

// ── Interior Inquiries — submit publik (no auth required) ────────────
Route::post('/interior-inquiries', [InteriorInquiryController::class, 'store']);

// ── Payment Webhooks (Public — no auth) ──────────────
Route::post('/payments/webhook/doku', [PaymentController::class, 'webhookDoku']);

// =====================================================
// AUTHENTICATED ROUTES
// =====================================================
Route::middleware('auth:sanctum')->group(function () {

    // ── Auth ─────────────────────────────────────────
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

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
        Route::post('/{id}/refund',    [BookingController::class, 'refund'])
            ->middleware('role:superadmin|admin|finance');
        // Admin: all bookings
        Route::get('/', [BookingController::class, 'index'])
            ->middleware('role:superadmin|admin|finance|owner');
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
        Route::get('/', [PaymentController::class, 'index'])
            ->middleware('role:superadmin|admin|finance');
    });

    // ── Promos ────────────────────────────────────────
    Route::prefix('promos')->group(function () {
        Route::post('/validate', [PromoController::class, 'validate']);

        // Owner: manage own promos + view own promo list
        Route::middleware('role:owner')->group(function () {
            Route::get('/my',       [PromoController::class, 'myPromos']);
            Route::get('/platform', [PromoController::class, 'platformPromos']);
            Route::post('/',        [PromoController::class, 'store']);
            Route::put('/{id}',     [PromoController::class, 'update']);
            Route::delete('/{id}',  [PromoController::class, 'destroy']);
        });

        // Admin: manage all promos + owners list for dropdown
        Route::middleware('role:admin|superadmin')->group(function () {
            Route::get('/',           [PromoController::class, 'index']);
            Route::post('/',          [PromoController::class, 'store']);
            Route::put('/{id}',       [PromoController::class, 'update']);
            Route::delete('/{id}',    [PromoController::class, 'destroy']);
            Route::get('/owners-list',[PromoController::class, 'ownersList']);
        });

        // Loyalty
        Route::get('/loyalty/balance',  [LoyaltyController::class, 'balance']);
        Route::get('/loyalty/history',  [LoyaltyController::class, 'history']);
        Route::post('/loyalty/redeem',  [LoyaltyController::class, 'redeem']);
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

    // ── Campaigns (Owner: view campaigns targeting them) ──────────────────
    Route::get('/campaigns/my', [CampaignController::class, 'myList'])
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
    });

    // ── Admin ─────────────────────────────────────────
    Route::prefix('admin')->middleware('role:superadmin|admin|finance')->group(function () {
        Route::get('/dashboard',           [DashboardController::class, 'index']);
        Route::get('/reports/revenue',     [ReportController::class, 'revenue']);
        Route::get('/reports/bookings',    [ReportController::class, 'bookings']);
        Route::get('/reports/canceled',    [ReportController::class, 'canceled']);
        Route::get('/logs',                [DashboardController::class, 'logs'])
            ->middleware('role:superadmin');
        // Finance can read hotels list (for report filters)
        Route::get('/hotels',         [HotelManageController::class, 'index']);
        Route::prefix('hotels')->middleware('role:superadmin|admin')->group(function () {
            Route::get('/pending',       [HotelManageController::class, 'pending']);
            Route::post('/',             [HotelManageController::class, 'store']);
            Route::put('/{id}',          [HotelManageController::class, 'update']);
            Route::delete('/{id}',       [HotelManageController::class, 'destroy']);
            Route::post('/{id}/approve', [HotelManageController::class, 'approve']);
            Route::post('/{id}/block',   [HotelManageController::class, 'block']);
        });
        // Campaigns (CRUD admin only)
        Route::prefix('campaigns')->group(function () {
            Route::get('/',       [CampaignController::class, 'index']);
            Route::post('/',      [CampaignController::class, 'store']);
            Route::put('/{id}',   [CampaignController::class, 'update']);
            Route::delete('/{id}',[CampaignController::class, 'destroy']);
        });

        Route::get('/settings/payment-gateways',  [SettingController::class, 'getGateways'])
            ->middleware('role:superadmin');
        Route::post('/settings/payment-gateways', [SettingController::class, 'setGateway'])
            ->middleware('role:superadmin');
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
});
