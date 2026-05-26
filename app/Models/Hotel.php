<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Hotel extends Model
{
    use HasFactory;
    protected $fillable = [
        // Core
        'owner_id','name','alias','category','slug','description',
        'is_brand_chain','currency','commission_percent','star_rating',
        // Lokasi
        'address','city','district','village','province','country',
        'postal_code','latitude','longitude',
        // Tamu
        'guest_types',
        // PIC
        'pic_position','pic_phone','property_phone','fax',
        // Legalitas
        'company_name','company_address','company_country',
        'agree_name','agree_position','agree_email','agree_phone',
        'voucher_emails',
        // Platform
        'platforms',
        // Fasilitas & Foto
        'facilities','images',
        // Info check-in
        'booking_min_age','check_in_24h',
        'check_in_start','check_in_end','check_out_start','check_out_end',
        // Kebijakan
        'gender_policy','marriage_book','deposit_required','all_ages_allowed','min_age',
        'breakfast_available','breakfast_start','breakfast_end',
        'smoking_allowed','alcohol_allowed','pets_allowed',
        // Pembayaran
        'cancellation_policy','payment_method',
        'bank_name','bank_branch','bank_account_name','bank_account_number',
        'vcc_accepted_types','vcc_email','vcc_account_name',
        // NPWP
        'npwp_type','npwp_number','npwp_name','npwp_doc',
        'nitku_number','nitku_name','nitku_doc','npwp_support_doc',
        // Meta
        'registration_source','status','approved_by','approved_at',
        // Harga
        'pricing_model','child_policy',
    ];
    protected $casts = [
        'facilities'         => 'array',
        'images'             => 'array',
        'guest_types'        => 'array',
        'platforms'          => 'array',
        'vcc_accepted_types' => 'array',
        'voucher_emails'     => 'array',
        'is_brand_chain'     => 'boolean',
        'gender_policy'      => 'boolean',
        'marriage_book'      => 'boolean',
        'deposit_required'   => 'boolean',
        'all_ages_allowed'   => 'boolean',
        'breakfast_available'=> 'boolean',
        'smoking_allowed'    => 'boolean',
        'alcohol_allowed'    => 'boolean',
        'pets_allowed'       => 'boolean',
        'check_in_24h'       => 'boolean',
        'booking_min_age'    => 'integer',
        'star_rating'        => 'integer',
        'commission_percent' => 'decimal:2',
        'latitude'           => 'decimal:8',
        'longitude'          => 'decimal:8',
        'approved_at'        => 'datetime',
        'child_policy'       => 'array',
    ];
    protected $attributes = ['country' => 'Indonesia', 'status' => 'pending', 'facilities' => '[]', 'images' => '[]'];

    public function owner()    { return $this->belongsTo(User::class, 'owner_id'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rooms()    { return $this->hasMany(Room::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
    public function chatRooms(){ return $this->hasMany(ChatRoom::class); }
    public function reviews()   { return $this->hasMany(Review::class); }

    public function scopeApproved($q) { return $q->where('status', 'approved'); }

    /**
     * Generate slug yang unik dari nama hotel.
     * Kalau slug sudah dipakai, append -2, -3, dst.
     * Pass $excludeId saat update agar tidak collision dengan dirinya sendiri.
     */
    public static function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name) ?: 'hotel';
        $slug = $base;
        $i    = 2;
        while (
            static::where('slug', $slug)
                ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
