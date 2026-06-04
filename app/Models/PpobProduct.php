<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpobProduct extends Model
{
    protected $fillable = [
        'category_id', 'raja_biller_code', 'name', 'operator',
        'nominal', 'price_buy', 'price_sell',
        'admin_fee', 'komisi',
        'description',
        'status', 'status_label',
        'meta',
        'synced_at', 'last_synced_at', 'last_callback_at',
    ];

    protected $casts = [
        'nominal'           => 'integer',
        'price_buy'         => 'decimal:2',
        'price_sell'        => 'decimal:2',
        'admin_fee'         => 'decimal:2',
        'komisi'            => 'decimal:2',
        'meta'              => 'array',
        'synced_at'         => 'datetime',
        'last_synced_at'    => 'datetime',
        'last_callback_at'  => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(PpobCategory::class, 'category_id');
    }

    public function transactions()
    {
        return $this->hasMany(PpobTransaction::class, 'product_id');
    }

    /**
     * Apakah produk siap dijual ke customer.
     * Hanya status_label "AKTIF" yang langsung available.
     * "AKTIF (*Need Request)" perlu pengajuan ke Rajabiller dulu.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active' && $this->status_label === 'AKTIF';
    }

    public function isPrepaid(): bool
    {
        return $this->category?->type === 'prabayar';
    }

    public function isPostpaid(): bool
    {
        return $this->category?->type === 'pascabayar';
    }

    public function scopeActive($q) { return $q->where('status', 'active'); }
    public function scopeAvailable($q) { return $q->where('status', 'active')->where('status_label', 'AKTIF'); }
}
