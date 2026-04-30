<?php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;
use App\Models\ChatRoom;

// Private channel untuk chat per room
// Hanya user yang terlibat atau admin yang bisa subscribe
Broadcast::channel('chat.{roomId}', function ($user, int $roomId) {
    $room     = ChatRoom::find($roomId);
    $adminRoles = ['superadmin', 'admin', 'admin_property'];

    if (!$room) return false;

    return $room->user_id === $user->id
        || $user->hasAnyRole($adminRoles)
        || $room->hotel?->owner_id === $user->id;
});
