<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketManagerOwner extends Model
{
    public $timestamps = false;

    protected $table    = 'market_manager_owners';
    protected $fillable = ['market_manager_id', 'owner_id'];
}
