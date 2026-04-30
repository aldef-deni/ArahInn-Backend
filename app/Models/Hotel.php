<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Hotel extends Model
{
    use HasFactory;
    protected $fillable = [
        'owner_id','name','slug','description','address','city','province',
        'country','latitude','longitude','star_rating','facilities','images',
        'status','approved_by','approved_at',
    ];
    protected $casts = [
        'facilities'  => 'array',
        'images'      => 'array',
        'latitude'    => 'decimal:8',
        'longitude'   => 'decimal:8',
        'approved_at' => 'datetime',
    ];
    protected $attributes = ['country' => 'Indonesia', 'status' => 'pending', 'facilities' => '[]', 'images' => '[]'];

    public function owner()    { return $this->belongsTo(User::class, 'owner_id'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rooms()    { return $this->hasMany(Room::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
    public function chatRooms(){ return $this->hasMany(ChatRoom::class); }

    public function scopeApproved($q) { return $q->where('status', 'approved'); }
}
