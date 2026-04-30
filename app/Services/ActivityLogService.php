<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogService
{
    public static function log(
        int $userId,
        string $action,
        string $entityType,
        mixed $entityId,
        Request $request
    ): void {
        try {
            Log::info('activity', [
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'ip'          => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'at'          => now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            // log failure must not interrupt request
        }
    }
}
