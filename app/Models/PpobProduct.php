<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpobProduct extends Model
{
    protected $fillable = [
        'category_id', 'raja_biller_code', 'name', 'operator',
        'nominal', 'price_buy', 'price_sell', 'description',
        'status', 'meta', 'synced_at',
    ];

    protected $casts = [
        'nominal'    => 'integer',
        'price_buy'  => 'decimal:2',
        'price_sell' => 'decimal:2',
        'meta'       => 'array',
        'synced_at'  => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(PpobCategory::class, 'category_id');
    }

    public function transactions()
    {
        return $this->hasMany(PpobTransaction::class, 'product_id');
    }

    public function scopeActive($q) { return $q->where('status', 'active'); }
}
