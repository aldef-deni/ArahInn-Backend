<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;
    protected $fillable = [
        'booking_code','user_id','hotel_id','room_id','rate_plan_id',
        'check_in','check_out','total_nights','stay_type','stay_plan_label','guests','room_count',
        'base_price','markup_amount','promo_discount','loyalty_discount',
        'discount_arahinn','discount_owner','owner_payout','commission_profit',
        'tax_amount','total_price','price_suffix',
        'status','promo_id','voucher_code','notes',
        'guest_name','guest_email','guest_phone',
        'expires_at','issued_at','canceled_at',
        'voucher_sent_at','voucher_error',
    ];
    protected $casts = [
        'check_in'         => 'date',
        'check_out'        => 'date',
        'base_price'       => 'float',
        'markup_amount'    => 'float',
        'promo_discount'   => 'float',
        'loyalty_discount' => 'float',
        'discount_arahinn' => 'float',
        'discount_owner'   => 'float',
        'owner_payout'     => 'float',
        'commission_profit'=> 'float',
        'tax_amount'       => 'float',
        'total_price'      => 'float',
        'expires_at'       => 'datetime',
        'issued_at'        => 'datetime',
        'canceled_at'      => 'datetime',
        'voucher_sent_at'  => 'datetime',
    ];

    // Label menginap tampil di voucher, email, API, & laporan (akomodasi).
    protected $appends = ['stay_label'];
    public function getStayLabelAttribute(): string
    {
        return match ($this->stay_type) {
            'weekly'  => 'Mingguan (7 malam)',
            'monthly' => 'Bulanan (30 malam)',
            default   => 'Harian',
        };
    }

    public function user()     { return $this->belongsTo(User::class); }
    public function hotel()    { return $this->belongsTo(Hotel::class); }
    public function room()     { return $this->belongsTo(Room::class); }
    public function ratePlan() { return $this->belongsTo(RatePlan::class); }
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

    /**
     * Auto-trigger BookingService::issue() saat status berubah jadi 'paid'.
     *
     * Defensive: kalau payment confirmed via path apa pun (DOKU webhook,
     * admin manual update, console command, dll), voucher email tetap terkirim.
     * Tidak masalah kalau ter-trigger berbarengan dengan call manual karena
     * issue() sekarang idempotent (skip kalau sudah issued).
     */
    protected static function booted(): void
    {
        static::updated(function (Booking $booking) {
            if (!$booking->wasChanged('status')) return;
            if ($booking->status !== 'paid') return;

            try {
                app(\App\Services\BookingService::class)->issue($booking);
            } catch (\Throwable $e) {
                logger()->error('Booking::booted auto-issue failed', [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'error'        => $e->getMessage(),
                ]);
            }
        });
    }
}
