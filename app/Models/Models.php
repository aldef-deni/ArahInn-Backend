<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'booking_id','amount','method','gateway',
        'gateway_trx_id','status','paid_at','expired_at','payload',
    ];
    protected $casts = [
        'amount'     => 'decimal:2',
        'payload'    => 'array',
        'paid_at'    => 'datetime',
        'expired_at' => 'datetime',
    ];
    public function booking() { return $this->belongsTo(Booking::class); }
}

// ─────────────────────────────────────────────────────

namespace App\Models;

class Promo extends Model
{
    protected $fillable = [
        'code','type','name','description','discount_type','discount_value',
        'min_purchase','max_discount','quota','used_count',
        'start_date','end_date','is_active','created_by',
    ];
    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_purchase'   => 'decimal:2',
        'max_discount'   => 'decimal:2',
        'is_active'      => 'boolean',
        'start_date'     => 'datetime',
        'end_date'       => 'datetime',
    ];
    protected $attributes = ['used_count' => 0, 'is_active' => true, 'min_purchase' => 0];

    public function creator()  { return $this->belongsTo(User::class, 'created_by'); }
    public function bookings() { return $this->hasMany(Booking::class); }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)
                 ->where('start_date', '<=', now())
                 ->where('end_date', '>=', now());
    }

    public function calculateDiscount(float $amount): float
    {
        if ($amount < $this->min_purchase) return 0;
        $discount = $this->discount_type === 'percent'
            ? $amount * ($this->discount_value / 100)
            : $this->discount_value;
        return $this->max_discount ? min($discount, $this->max_discount) : $discount;
    }
}

// ─────────────────────────────────────────────────────

namespace App\Models;

class LoyaltyPoint extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id','points','type','description','booking_id','expires_at','created_at'];
    protected $casts = ['expires_at' => 'datetime', 'created_at' => 'datetime'];

    public function user()    { return $this->belongsTo(User::class); }
    public function booking() { return $this->belongsTo(Booking::class); }
}

// ─────────────────────────────────────────────────────

namespace App\Models;

class ActivityLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id','action','entity','entity_id','ip_address','user_agent','payload','created_at'];
    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}

// ─────────────────────────────────────────────────────

namespace App\Models;

class ChatRoom extends Model
{
    protected $fillable = ['booking_id','user_id','hotel_id','is_closed'];
    protected $casts    = ['is_closed' => 'boolean'];

    public function booking()  { return $this->belongsTo(Booking::class); }
    public function user()     { return $this->belongsTo(User::class); }
    public function hotel()    { return $this->belongsTo(Hotel::class); }
    public function messages() { return $this->hasMany(ChatMessage::class, 'room_id'); }
}

// ─────────────────────────────────────────────────────

namespace App\Models;

class ChatMessage extends Model
{
    public $timestamps = false;
    protected $fillable = ['room_id','sender_id','message','is_read','created_at'];
    protected $casts    = ['is_read' => 'boolean', 'created_at' => 'datetime'];

    public function room()   { return $this->belongsTo(ChatRoom::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
}
