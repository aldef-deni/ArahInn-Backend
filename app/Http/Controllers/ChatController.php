<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function myRooms(Request $request)
    {
        $rooms = ChatRoom::where('user_id', $request->user()->id)
            ->with(['hotel:id,name,images', 'messages' => fn($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn($q) => $q->where('is_read', false)->where('sender_id', '!=', $request->user()->id)])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $rooms]);
    }

    public function createRoom(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|integer',
            'hotel_id' => 'required|integer',
        ]);

        $room = ChatRoom::firstOrCreate(
            ['booking_id' => $data['booking_id']],
            ['hotel_id' => $data['hotel_id'], 'user_id' => $request->user()->id]
        );

        return response()->json(['success' => true, 'data' => $room], $room->wasRecentlyCreated ? 201 : 200);
    }

    public function showRoom(string $id)
    {
        $room = ChatRoom::with(['hotel:id,name', 'booking:id,booking_code,check_in,check_out'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $room]);
    }

    public function messages(Request $request, string $id)
    {
        $messages = ChatMessage::where('room_id', $id)
            ->with('sender:id,name,avatar,roles')
            ->orderBy('created_at', 'asc')
            ->paginate($request->limit ?? 50);

        ChatMessage::where('room_id', $id)
            ->where('sender_id', '!=', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true, 'data' => $messages->items()]);
    }

    public function sendMessage(Request $request, string $id)
    {
        $data = $request->validate(['message' => 'required|string|max:2000']);

        $room = ChatRoom::findOrFail($id);
        if ($room->is_closed) {
            return response()->json(['success' => false, 'message' => 'Chat sudah ditutup.'], 400);
        }

        $message = ChatMessage::create([
            'room_id' => $id,
            'sender_id' => $request->user()->id,
            'message' => $data['message'],
            'is_read' => false,
            'created_at' => now(),
        ]);

        $message->load('sender:id,name,avatar');
        $room->touch();

        broadcast(new ChatMessageSent($message))->toOthers();

        return response()->json(['success' => true, 'data' => $message]);
    }

    public function allRooms(Request $request)
    {
        $rooms = ChatRoom::with(['user:id,name,email', 'hotel:id,name', 'messages' => fn($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn($q) => $q->where('is_read', false)])
            ->when($request->is_closed !== null, fn($q) => $q->where('is_closed', $request->boolean('is_closed')))
            ->orderBy('updated_at', 'desc')
            ->paginate($request->limit ?? 20);

        return response()->json(['success' => true, 'data' => $rooms->items()]);
    }

    public function ownerRooms(Request $request)
    {
        $hotel = \App\Models\Hotel::where('owner_id', $request->user()->id)->first();

        if (!$hotel) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $rooms = ChatRoom::where('hotel_id', $hotel->id)
            ->with(['user:id,name,email', 'booking:id,booking_code,check_in,check_out', 'messages' => fn($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn($q) => $q->where('is_read', false)->where('sender_id', '!=', $request->user()->id)])
            ->orderBy('updated_at', 'desc')
            ->paginate($request->limit ?? 50);

        return response()->json(['success' => true, 'data' => $rooms->items()]);
    }
}
