<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpobCategory extends Model
{
    protected $fillable = [
        'code', 'name', 'group', 'type', 'icon', 'color',
        'markup_amount', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'markup_amount' => 'decimal:2',
        'sort_order'    => 'integer',
        'is_active'     => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(PpobProduct::class, 'category_id');
    }

    public function transactions()
    {
        return $this->hasMany(PpobTransaction::class, 'category_id');
    }

    public function scopeActive($q) { return $q->where('is_active', true); }
    public function scopeOrdered($q) { return $q->orderBy('sort_order')->orderBy('name'); }
}
