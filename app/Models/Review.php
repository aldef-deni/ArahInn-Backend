<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id', 'property_id', 'user_id', 'booking_id',
        'rating', 'comment', 'status', 'rejected_reason',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function hotel()    { return $this->belongsTo(Hotel::class); }
    public function property() { return $this->belongsTo(PropertyListing::class, 'property_id'); }
    public function user()     { return $this->belongsTo(User::class); }
    public function booking()  { return $this->belongsTo(Booking::class); }

    public function scopeApproved($q) { return $q->where('status', 'approved'); }
    public function scopePending($q)  { return $q->where('status', 'pending'); }
}
