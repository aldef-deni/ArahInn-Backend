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

        // Paksa unread_count jadi integer agar FE tidak terjebak string concat
        $rooms->each(fn($r) => $r->unread_count = (int) $r->unread_count);

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
            ->with(['sender:id,name,avatar', 'sender.roles:id,name'])
            ->orderBy('created_at', 'asc')
            ->paginate($request->limit ?? 100);

        ChatMessage::where('room_id', $id)
            ->where('sender_id', '!=', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true, 'data' => $messages->items()]);
    }

    public function sendMessage(Request $request, string $id)
    {
        $data = $request->validate(['message' => 'required|string|max:2000']);

        $room = ChatRoom::with('hotel:id,owner_id,name')->findOrFail($id);
        if ($room->is_closed) {
            return response()->json(['success' => false, 'message' => 'Chat sudah ditutup.'], 400);
        }

        $sender   = $request->user();
        $isFirst  = $room->messages()->count() === 0;

        $message = ChatMessage::create([
            'room_id' => $id,
            'sender_id' => $sender->id,
            'message' => $data['message'],
            'is_read' => false,
            'created_at' => now(),
        ]);

        $message->load('sender:id,name,avatar');
        $room->touch();

        broadcast(new ChatMessageSent($message))->toOthers();

        // ── In-app notification ──────────────────────────────────────────
        $this->dispatchChatNotification($room, $sender, $data['message'], $isFirst);

        return response()->json(['success' => true, 'data' => $message]);
    }

    /**
     * Kirim notifikasi ke pihak lawan saat ada pesan masuk.
     * Routing berdasarkan room.type + sender.
     */
    private function dispatchChatNotification(ChatRoom $room, $sender, string $msg, bool $isFirst): void
    {
        $type     = $room->type ?: 'booking';
        $senderId = (int) $sender->id;
        $isCustomer = $senderId === (int) $room->user_id;

        // Snippet pesan (60 char) untuk body notif
        $snippet = mb_strimwidth(strip_tags($msg), 0, 60, '…');

        $baseData = [
            'room_id'   => $room->id,
            'room_type' => $type,
            'hotel_id'  => $room->hotel_id,
            'sender_id' => $senderId,
            'sender_name' => $sender->name ?? null,
        ];

        if ($isCustomer) {
            // Customer kirim pesan → notify pihak lawan
            if ($type === 'support') {
                \App\Services\NotificationService::sendToRoles(
                    ['superadmin', 'admin'],
                    'chat_message',
                    'Pesan baru dari ' . ($sender->name ?? 'customer'),
                    $snippet,
                    $baseData + ['channel' => 'support']
                );
            } elseif ($type === 'inquiry') {
                $ownerId = $room->hotel?->owner_id;
                if ($ownerId) {
                    \App\Services\NotificationService::send(
                        $ownerId,
                        $isFirst ? 'inquiry_new' : 'chat_message',
                        $isFirst
                            ? 'Tamu baru menanyakan penginapan'
                            : 'Pesan baru dari ' . ($sender->name ?? 'tamu'),
                        $snippet,
                        $baseData + ['hotel_name' => $room->hotel?->name, 'channel' => 'inquiry']
                    );
                }
            } else {
                // booking
                $ownerId = $room->hotel?->owner_id;
                if ($ownerId) {
                    \App\Services\NotificationService::send(
                        $ownerId,
                        'chat_message',
                        'Pesan baru dari tamu ' . ($sender->name ?? ''),
                        $snippet,
                        $baseData + ['hotel_name' => $room->hotel?->name, 'channel' => 'booking']
                    );
                }
            }
        } else {
            // Pihak lawan (CS/owner) balas → notify customer
            $targetId = (int) $room->user_id;
            if ($targetId) {
                $title = match ($type) {
                    'support' => 'Balasan dari tim ArahInn',
                    'inquiry' => 'Balasan dari ' . ($room->hotel?->name ?? 'penginapan'),
                    default   => 'Balasan untuk booking Anda',
                };
                \App\Services\NotificationService::send(
                    $targetId,
                    'chat_message',
                    $title,
                    $snippet,
                    $baseData + ['hotel_name' => $room->hotel?->name, 'channel' => $type]
                );
            }
        }
    }

    public function allRooms(Request $request)
    {
        $rooms = ChatRoom::with(['user:id,name,email,avatar', 'hotel:id,name', 'messages' => fn($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn($q) => $q->where('is_read', false)])
            ->when($request->is_closed !== null, fn($q) => $q->where('is_closed', $request->boolean('is_closed')))
            ->orderBy('updated_at', 'desc')
            ->paginate($request->limit ?? 20);

        return response()->json(['success' => true, 'data' => $rooms->items()]);
    }

    public function ownerRooms(Request $request)
    {
        // Owner bisa punya multi-hotel. Sebelumnya pakai first() → cuma 1 hotel.
        $hotelIds = \App\Models\Hotel::where('owner_id', $request->user()->id)->pluck('id');

        if ($hotelIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $rooms = ChatRoom::whereIn('hotel_id', $hotelIds)
            ->where(fn($q) => $q->where('type', 'booking')->orWhereNull('type'))
            ->with(['user:id,name,email,avatar', 'hotel:id,name', 'booking:id,booking_code,check_in,check_out', 'messages' => fn($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn($q) => $q->where('is_read', false)->where('sender_id', '!=', $request->user()->id)])
            ->orderBy('updated_at', 'desc')
            ->paginate($request->limit ?? 50);

        // Paksa unread_count jadi integer
        $data = collect($rooms->items())->map(function ($r) {
            $r->unread_count = (int) $r->unread_count;
            return $r;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ── Customer: get-or-create support room (chat dengan tim Arahinn) ──
    public function mySupportRoom(Request $request)
    {
        $user = $request->user();
        $room = ChatRoom::firstOrCreate(
            ['user_id' => $user->id, 'type' => 'support'],
            ['booking_id' => null, 'hotel_id' => null, 'is_closed' => false]
        );
        $room->load(['messages' => fn($q) => $q->orderBy('created_at', 'asc')->limit(100)]);
        return response()->json(['success' => true, 'data' => $room]);
    }

    // ── Customer: get-or-create inquiry room (pra-booking, chat dgn owner) ──
    public function inquiryRoom(Request $request)
    {
        $data = $request->validate(['hotel_id' => 'required|integer|exists:hotels,id']);

        $room = ChatRoom::firstOrCreate(
            [
                'user_id'  => $request->user()->id,
                'hotel_id' => $data['hotel_id'],
                'type'     => 'inquiry',
            ],
            ['booking_id' => null, 'is_closed' => false]
        );

        $room->load([
            'hotel:id,name,images',
            'messages' => fn($q) => $q->orderBy('created_at', 'asc')->limit(100),
        ]);

        return response()->json(['success' => true, 'data' => $room]);
    }

    // ── Customer: list semua inquiry room miliknya (riwayat tanya2) ──────
    public function myInquiries(Request $request)
    {
        $rooms = ChatRoom::where('user_id', $request->user()->id)
            ->where('type', 'inquiry')
            ->with(['hotel:id,name,images', 'messages' => fn($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn($q) =>
                $q->where('is_read', false)->where('sender_id', '!=', $request->user()->id)
            ])
            ->orderBy('updated_at', 'desc')
            ->get();

        $rooms->each(fn($r) => $r->unread_count = (int) $r->unread_count);

        return response()->json(['success' => true, 'data' => $rooms]);
    }

    // ── Owner: list inquiry rooms untuk semua hotel miliknya ──────────────
    public function ownerInquiries(Request $request)
    {
        $hotelIds = \App\Models\Hotel::where('owner_id', $request->user()->id)->pluck('id');

        if ($hotelIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $rooms = ChatRoom::whereIn('hotel_id', $hotelIds)
            ->where('type', 'inquiry')
            ->with([
                'user:id,name,email,avatar',
                'hotel:id,name',
                'messages' => fn($q) => $q->latest()->limit(1),
            ])
            ->withCount(['messages as unread_count' => fn($q) =>
                $q->where('is_read', false)->where('sender_id', '!=', $request->user()->id)
            ])
            ->orderBy('updated_at', 'desc')
            ->paginate($request->limit ?? 50);

        // Paksa unread_count jadi integer (cegah string concat di FE)
        $data = collect($rooms->items())->map(function ($r) {
            $r->unread_count = (int) $r->unread_count;
            return $r;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ── Admin/Superadmin: list semua support rooms ───────────────────────
    public function adminSupportRooms(Request $request)
    {
        $rooms = ChatRoom::where('type', 'support')
            ->with(['user:id,name,email,avatar', 'messages' => fn($q) => $q->latest()->limit(1)])
            ->withCount(['messages as unread_count' => fn($q) =>
                $q->where('is_read', false)->whereHas('sender', fn($s) => $s->role('user'))
            ])
            ->when($request->search, fn($q) => $q->whereHas('user', fn($u) =>
                $u->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
            ))
            ->orderBy('updated_at', 'desc')
            ->paginate($request->limit ?? 30);

        // Paksa unread_count jadi integer
        $data = collect($rooms->items())->map(function ($r) {
            $r->unread_count = (int) $r->unread_count;
            return $r;
        });

        return response()->json(['success' => true, 'data' => $data, 'meta' => [
            'total' => $rooms->total(),
            'page'  => $rooms->currentPage(),
        ]]);
    }
}
