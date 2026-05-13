<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Serve uploaded files — two-segment route avoids slash-in-parameter matching issues
// Handles paths like /uploads/hotels/file.jpg, /uploads/avatars/file.jpg, etc.
Route::get('/uploads/{type}/{filename}', function (string $type, string $filename) {
    // Validate segments — prevent path traversal
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $type) ||
        str_contains($filename, '/') ||
        str_contains($filename, '..') ||
        str_contains($filename, "\0")) {
        abort(400);
    }

    // Check storage location first, fall back to legacy public location
    $filePath = storage_path("app/public/uploads/{$type}/{$filename}");
    if (!file_exists($filePath)) {
        $filePath = public_path("uploads/{$type}/{$filename}");
    }
    if (!file_exists($filePath)) {
        abort(404);
    }

    // Fix permissions — silently ignored if process doesn't own the file
    @chmod($filePath, 0644);

    // Derive MIME from extension — avoids fileinfo/finfo dependency entirely
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    // Read and return — avoids all streaming/buffering issues and finfo dependency
    $content = @file_get_contents($filePath);
    if ($content === false) {
        abort(403);
    }

    return response()->make($content, 200, [
        'Content-Type'        => $mime,
        'Cache-Control'       => 'public, max-age=31536000',
        'Content-Disposition' => 'inline',
    ]);
})->where(['type' => '[a-zA-Z0-9_-]+', 'filename' => '[^/]+']);

// TEMPORARY — preview email template. Remove after testing.
Route::get('/test-email', function () {
    $booking = \App\Models\Booking::with(['hotel', 'room'])->latest()->first();
    return new \App\Mail\BookingIssuedMail($booking);
});
