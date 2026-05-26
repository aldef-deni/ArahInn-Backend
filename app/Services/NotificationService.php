<?php

namespace App\Services;

use App\Models\OtaNotification;
use App\Models\User;

class NotificationService
{
    public static function send(int $userId, string $type, string $title, string $body, array $data = []): void
    {
        try {
            OtaNotification::create([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body,
                'data'    => $data,
            ]);

            // Push notification (silent fail jika gagal — tidak boleh memblok flow)
            ExpoPushService::sendToUser($userId, $title, $body, array_merge($data, ['type' => $type]));
        } catch (\Exception $e) {
            logger()->error('NotificationService::send failed: ' . $e->getMessage());
        }
    }

    public static function sendToMany(array $userIds, string $type, string $title, string $body, array $data = []): void
    {
        foreach (array_unique($userIds) as $userId) {
            static::send((int) $userId, $type, $title, $body, $data);
        }
    }

    public static function sendToRole(string $role, string $type, string $title, string $body, array $data = []): void
    {
        try {
            $userIds = User::role($role)->pluck('id')->toArray();
            static::sendToMany($userIds, $type, $title, $body, $data);
        } catch (\Exception $e) {
            logger()->error('NotificationService::sendToRole failed: ' . $e->getMessage());
        }
    }

    public static function sendToRoles(array $roles, string $type, string $title, string $body, array $data = []): void
    {
        try {
            $userIds = User::role($roles)->pluck('id')->toArray();
            static::sendToMany($userIds, $type, $title, $body, $data);
        } catch (\Exception $e) {
            logger()->error('NotificationService::sendToRoles failed: ' . $e->getMessage());
        }
    }
}
