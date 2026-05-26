<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpobTransaction extends Model
{
    protected $fillable = [
        'trx_code', 'user_id', 'product_id', 'category_id', 'payment_id',
        'product_name', 'product_code', 'customer_number', 'customer_name',
        'price_buy', 'price_sell', 'admin_fee', 'total_amount',
        'status', 'raja_biller_ref', 'serial_number', 'raja_biller_payload',
        'failure_reason', 'refunded_by', 'refunded_at', 'refund_notes',
        'paid_at', 'executed_at', 'completed_at',
    ];

    protected $casts = [
        'price_buy'           => 'decimal:2',
        'price_sell'          => 'decimal:2',
        'admin_fee'           => 'decimal:2',
        'total_amount'        => 'decimal:2',
        'raja_biller_payload' => 'array',
        'paid_at'             => 'datetime',
        'executed_at'         => 'datetime',
        'completed_at'        => 'datetime',
        'refunded_at'         => 'datetime',
    ];

    public function user()     { return $this->belongsTo(User::class); }
    public function product()  { return $this->belongsTo(PpobProduct::class, 'product_id'); }
    public function category() { return $this->belongsTo(PpobCategory::class, 'category_id'); }
    public function payment()  { return $this->belongsTo(Payment::class, 'payment_id'); }
    public function refundedBy() { return $this->belongsTo(User::class, 'refunded_by'); }

    public static function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = 'PPOB';
        for ($i = 0; $i < 7; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        return $code;
    }

    public function scopeByStatus($q, $status) { return $status ? $q->where('status', $status) : $q; }
    public function scopeForUser($q, $userId)  { return $q->where('user_id', $userId); }
}
