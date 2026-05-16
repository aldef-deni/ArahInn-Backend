<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InteriorInquiry extends Model
{
    protected $fillable = [
        'nama',
        'no_hp',
        'proyek',
        'desain_referensi',
        'status',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
