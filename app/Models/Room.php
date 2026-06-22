<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Room extends Model
{
    use HasFactory;
    protected $fillable = [
        'hotel_id','name','type','description',
        'smoking_policy','has_bedrooms','bed_configs',
        'max_guests','base_price','weekly_price','monthly_price','weekly_plans','monthly_plans','facilities','images',
        'total_units','is_active',
    ];
    protected $casts = [
        'facilities'     => 'array',
        'images'         => 'array',
        'bed_configs'    => 'array',
        'weekly_plans'   => 'array',
        'monthly_plans'  => 'array',
        'base_price'     => 'float',
        'weekly_price'   => 'float',
        'monthly_price'  => 'float',
        'is_active'      => 'boolean',
        'smoking_policy' => 'boolean',
        'has_bedrooms'   => 'boolean',
    ];
    protected $attributes = ['max_guests' => 2, 'total_units' => 1, 'is_active' => true, 'facilities' => '[]', 'images' => '[]'];

    public function hotel()    { return $this->belongsTo(Hotel::class); }
    public function bookings() { return $this->hasMany(Booking::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /**
     * Daftar opsi menginap lama untuk durasi tertentu (weekly|monthly).
     * Pakai weekly_plans/monthly_plans bila ada; fallback ke harga tunggal lama
     * sebagai 1 opsi "Standar". Tiap opsi: ['label','desc','price'].
     */
    public function longStayPlans(string $type): array
    {
        $plans = $type === 'weekly' ? $this->weekly_plans : $this->monthly_plans;
        $out = [];
        if (is_array($plans)) {
            foreach ($plans as $p) {
                $price = (float) ($p['price'] ?? 0);
                if ($price > 0) {
                    $out[] = [
                        'label' => (string) ($p['label'] ?? 'Opsi'),
                        'desc'  => (string) ($p['desc'] ?? ''),
                        'price' => $price,
                    ];
                }
            }
        }
        if ($out) return $out;
        // Fallback: harga tunggal lama → 1 opsi.
        $single = $type === 'weekly' ? (float) $this->weekly_price : (float) $this->monthly_price;
        return $single > 0 ? [['label' => 'Standar', 'desc' => '', 'price' => $single]] : [];
    }
}
