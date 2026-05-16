<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InteriorDesign extends Model
{
    protected $fillable = [
        'title',
        'description',
        'images',
        'videos',
        'status',
        'owner_id',
        'wa_number',
    ];

    protected $casts = [
        'images'   => 'array',
        'videos'   => 'array',
        'owner_id' => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
