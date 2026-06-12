<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PropertyListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'approved_by', 'title', 'description', 'category',
        'listing_type', 'price', 'price_negotiable', 'address', 'city',
        'province', 'latitude', 'longitude', 'land_area', 'building_area', 'bedrooms', 'bathrooms',
        'certificate', 'facilities', 'images', 'contact_phone', 'contact_email',
        'status', 'rejection_reason', 'approved_at', 'views_count',
    ];

    protected $attributes = [
        'images'     => '[]',
        'facilities' => '[]',
    ];

    protected $casts = [
        'facilities'       => 'array',
        'images'           => 'array',
        'price_negotiable' => 'boolean',
        'approved_at'      => 'datetime',
        'price'            => 'integer',
        'views_count'      => 'integer',
        'latitude'         => 'float',
        'longitude'        => 'float',
    ];

    public function owner()    { return $this->belongsTo(User::class, 'owner_id'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }

    public function scopeApproved($q) { return $q->where('status', 'approved'); }
    public function scopePending($q)  { return $q->where('status', 'pending'); }
}
