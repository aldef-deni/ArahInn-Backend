<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'antifraud'  => \App\Http\Middleware\AntiFraud::class,
            'auth'       => \App\Http\Middleware\Authenticate::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON for API routes
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $status  = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                $message = $e->getMessage() ?: 'Terjadi kesalahan pada server.';

                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json(['success' => false, 'message' => 'Tidak terautentikasi.'], 401);
                }
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Data tidak valid.'], 422);
                }
                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
                }
                if ($e instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
                    return response()->json(['success' => false, 'message' => 'Akses ditolak. Anda tidak memiliki izin.'], 403);
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    ...(config('app.debug') ? ['debug' => get_class($e), 'trace' => $e->getTraceAsString()] : []),
                ], min($status, 500));
            }
        });
    })
    ->create();
