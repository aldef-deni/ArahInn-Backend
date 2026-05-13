<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Room extends Model
{
    use HasFactory;
    protected $fillable = [
        'hotel_id','name','type','description',
        'smoking_policy','has_bedrooms','bed_configs',
        'max_guests','base_price','facilities','images',
        'total_units','is_active',
    ];
    protected $casts = [
        'facilities'     => 'array',
        'images'         => 'array',
        'bed_configs'    => 'array',
        'base_price'     => 'float',
        'is_active'      => 'boolean',
        'smoking_policy' => 'boolean',
        'has_bedrooms'   => 'boolean',
    ];
    protected $attributes = ['max_guests' => 2, 'total_units' => 1, 'is_active' => true, 'facilities' => '[]', 'images' => '[]'];

    public function hotel()    { return $this->belongsTo(Hotel::class); }
    public function bookings() { return $this->hasMany(Booking::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
