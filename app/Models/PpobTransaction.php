<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PpobTransaction extends Model
{
    protected $fillable = [
        'trx_code', 'ref1', 'rc',
        'user_id', 'product_id', 'category_id', 'payment_id',
        'product_name', 'product_code', 'customer_number', 'customer_name',
        'extra_request',
        'price_buy', 'price_sell', 'admin_fee', 'total_amount',
        'status', 'raja_biller_ref', 'serial_number',
        'raja_biller_payload', 'inquiry_response', 'payment_response', 'callback_data',
        'template_struk', 'struk_url', 'saldo_akhir_rajabiller',
        'failure_reason', 'refunded_by', 'refunded_at', 'refund_notes',
        'paid_at', 'paid_by', 'paid_notes', 'executed_at', 'completed_at',
        'inquired_at', 'callback_received_at', 'expires_at',
    ];

    protected $casts = [
        'price_buy'              => 'decimal:2',
        'price_sell'             => 'decimal:2',
        'admin_fee'              => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'saldo_akhir_rajabiller' => 'decimal:2',
        'extra_request'          => 'array',
        'raja_biller_payload'    => 'array',
        'inquiry_response'       => 'array',
        'payment_response'       => 'array',
        'callback_data'          => 'array',
        'template_struk'         => 'array',
        'paid_at'                => 'datetime',
        'executed_at'            => 'datetime',
        'completed_at'           => 'datetime',
        'refunded_at'            => 'datetime',
        'inquired_at'            => 'datetime',
        'callback_received_at'   => 'datetime',
        'expires_at'             => 'datetime',
    ];

    public function user()     { return $this->belongsTo(User::class); }
    public function product()  { return $this->belongsTo(PpobProduct::class, 'product_id'); }
    public function category() { return $this->belongsTo(PpobCategory::class, 'category_id'); }
    public function payment()  { return $this->belongsTo(Payment::class, 'payment_id'); }
    public function refundedBy() { return $this->belongsTo(User::class, 'refunded_by'); }

    /**
     * Generate internal trx_code (PPOBXXXXXXX).
     */
    public static function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = 'PPOB';
        for ($i = 0; $i < 7; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        return $code;
    }

    /**
     * Generate ref1 unik untuk dikirim ke Rajabiller.
     * Format: ARH-{YmdHis}-{random8}
     * Max 100 char.
     */
    public static function generateRef1(): string
    {
        return 'ARH-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(8));
    }

    /* ── Status helpers ─────────────────────────────────────────── */
    public function isPending()    { return in_array($this->status, ['pending', 'paid', 'processing'], true); }
    public function isCompleted()  { return $this->status === 'success'; }
    public function isFailed()     { return in_array($this->status, ['failed', 'refundable', 'refunded'], true); }
    public function isInquired()   { return !empty($this->inquired_at); }
    public function isExpired()    { return $this->expires_at && $this->expires_at->isPast(); }

    /* ── Scopes ─────────────────────────────────────────────────── */
    public function scopeByStatus($q, $status) { return $status ? $q->where('status', $status) : $q; }
    public function scopeForUser($q, $userId)  { return $q->where('user_id', $userId); }
    public function scopePending($q)           { return $q->whereIn('status', ['pending', 'paid', 'processing']); }
}
