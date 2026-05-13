<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = [
        'title', 'type', 'target', 'status',
        'start_date', 'end_date', 'budget', 'description',
        'owner_id', 'created_by', 'views', 'clicks',
    ];

    protected $casts = [
        'budget'     => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'end_date'   => 'date:Y-m-d',
        'views'      => 'integer',
        'clicks'     => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
