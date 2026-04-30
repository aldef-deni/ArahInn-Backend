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
Route::get('/hotels/my-hotel', [HotelController::class, 'myHotel'])
    ->middleware(['auth:sanctum', 'role:owner']);

// ── Hotels (Public) ───────────────────────────────────
Route::prefix('hotels')->group(function () {
    Route::get('/search',       [HotelController::class, 'search']);
    Route::get('/cities',       [HotelController::class, 'cities']);
    Route::get('/{id}',         [HotelController::class, 'show']);
    Route::get('/{id}/rooms',   [RoomController::class, 'byHotel']);
    Route::get('/{id}/availability', [RoomController::class, 'availability']);
});

// ── Promos (Public) ───────────────────────────────────
Route::prefix('promos')->group(function () {
    Route::get('/active',      [PromoController::class, 'active']);
    Route::get('/flash-sales', [PromoController::class, 'flashSales']);
});

// ── Payment Webhooks (Public — no auth) ──────────────
Route::post('/payments/webhook/midtrans', [PaymentController::class, 'webhookMidtrans']);

// =====================================================
// AUTHENTICATED ROUTES
// =====================================================
Route::middleware('auth:sanctum')->group(function () {

    // ── Auth ─────────────────────────────────────────
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // ── Hotels (Owner / Admin) ────────────────────────
    Route::prefix('hotels')->middleware('role:owner|admin|superadmin')->group(function () {
        Route::post('/',                    [HotelController::class, 'store']);
        Route::put('/{id}',                 [HotelController::class, 'update']);
        Route::post('/{id}/rooms',          [RoomController::class, 'store']);
        Route::put('/{hotelId}/rooms/{roomId}',   [RoomController::class, 'update']);
        Route::delete('/{hotelId}/rooms/{roomId}', [RoomController::class, 'destroy']);
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

        Route::middleware('role:admin|superadmin')->group(function () {
            Route::get('/',       [PromoController::class, 'index']);
            Route::post('/',      [PromoController::class, 'store']);
            Route::put('/{id}',   [PromoController::class, 'update']);
            Route::delete('/{id}',[PromoController::class, 'destroy']);
        });

        // Loyalty
        Route::get('/loyalty/balance',  [LoyaltyController::class, 'balance']);
        Route::get('/loyalty/history',  [LoyaltyController::class, 'history']);
        Route::post('/loyalty/redeem',  [LoyaltyController::class, 'redeem']);
    });

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
        Route::prefix('hotels')->middleware('role:superadmin|admin')->group(function () {
            Route::get('/',              [HotelManageController::class, 'index']);
            Route::get('/pending',       [HotelManageController::class, 'pending']);
            Route::post('/',             [HotelManageController::class, 'store']);
            Route::put('/{id}',          [HotelManageController::class, 'update']);
            Route::delete('/{id}',       [HotelManageController::class, 'destroy']);
            Route::post('/{id}/approve', [HotelManageController::class, 'approve']);
            Route::post('/{id}/block',   [HotelManageController::class, 'block']);
        });
        Route::get('/settings/payment-gateways',  [SettingController::class, 'getGateways'])
            ->middleware('role:superadmin');
        Route::post('/settings/payment-gateways', [SettingController::class, 'setGateway'])
            ->middleware('role:superadmin');
    });
});
