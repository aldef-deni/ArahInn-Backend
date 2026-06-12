<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value store untuk pengaturan platform (PPN, maintenance, payment mode,
 * rekening manual, gateway, markup travel). Disimpan permanen di DB supaya
 * TIDAK hilang saat cache di-clear / deploy. Cache hanya lapisan baca cepat.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType     = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'json',  // scalar maupun array di-handle (json_encode/decode)
    ];
}
