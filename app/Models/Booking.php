<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;
    protected $fillable = [
        'booking_code','user_id','hotel_id','room_id',
        'check_in','check_out','total_nights','guests',
        'base_price','markup_amount','promo_discount','loyalty_discount',
        'tax_amount','total_price','price_suffix',
        'status','promo_id','voucher_code','notes',
        'guest_name','guest_email','guest_phone',
        'expires_at','issued_at','canceled_at',
    ];
    protected $casts = [
        'check_in'         => 'date',
        'check_out'        => 'date',
        'base_price'       => 'decimal:2',
        'markup_amount'    => 'decimal:2',
        'promo_discount'   => 'decimal:2',
        'loyalty_discount' => 'decimal:2',
        'tax_amount'       => 'decimal:2',
        'total_price'      => 'decimal:2',
        'expires_at'       => 'datetime',
        'issued_at'        => 'datetime',
        'canceled_at'      => 'datetime',
    ];

    public function user()     { return $this->belongsTo(User::class); }
    public function hotel()    { return $this->belongsTo(Hotel::class); }
    public function room()     { return $this->belongsTo(Room::class); }
    public function promo()    { return $this->belongsTo(Promo::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function loyaltyPoints() { return $this->hasMany(LoyaltyPoint::class); }
    public function chatRoom() { return $this->hasOne(ChatRoom::class); }

    public function scopePending($q)   { return $q->where('status', 'pending'); }
    public function scopeActive($q)    { return $q->whereIn('status', ['paid','issued']); }
    public function scopeByStatus($q, $s) { return $s ? $q->where('status', $s) : $q; }

    public static function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = 'ARH';
        for ($i = 0; $i < 7; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        return $code;
    }
}
