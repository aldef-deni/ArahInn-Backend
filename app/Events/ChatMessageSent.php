<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message)
    {
        $this->message->load('sender:id,name,avatar');
    }

    /**
     * Broadcast ke private channel per room
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->message->room_id}")];
    }

    public function broadcastAs(): string
    {
        return 'new-message';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->message->id,
            'room_id'    => $this->message->room_id,
            'sender_id'  => $this->message->sender_id,
            'message'    => $this->message->message,
            'is_read'    => $this->message->is_read,
            'created_at' => $this->message->created_at,
            'sender'     => $this->message->sender,
        ];
    }
}
