<?php

namespace Database\Seeders;

use App\Models\{User, Hotel, Room, Promo};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Buat Roles ────────────────────────────────────
        $roles = ['superadmin', 'owner', 'admin_property', 'admin', 'finance', 'user'];
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // ── Buat Users ────────────────────────────────────
        $password = Hash::make('Password123!');

        $superadmin = User::create([
            'name' => 'Super Admin', 'email' => 'superadmin@otasystem.id',
            'password' => $password, 'phone' => '081200000001', 'is_active' => true,
        ]);
        $superadmin->assignRole('superadmin');

        $owner = User::create([
            'name' => 'Hotel Owner', 'email' => 'owner@otasystem.id',
            'password' => $password, 'phone' => '081200000002', 'is_active' => true,
        ]);
        $owner->assignRole('owner');

        $adminProp = User::create([
            'name' => 'Admin Property', 'email' => 'adminproperty@otasystem.id',
            'password' => $password, 'phone' => '081200000003', 'is_active' => true,
        ]);
        $adminProp->assignRole('admin_property');

        $admin = User::create([
            'name' => 'Admin OTA', 'email' => 'admin@otasystem.id',
            'password' => $password, 'phone' => '081200000004', 'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $finance = User::create([
            'name' => 'Finance Staff', 'email' => 'finance@otasystem.id',
            'password' => $password, 'phone' => '081200000005', 'is_active' => true,
        ]);
        $finance->assignRole('finance');

        $user1 = User::create([
            'name' => 'Budi Santoso', 'email' => 'user@otasystem.id',
            'password' => $password, 'phone' => '081200000006', 'is_active' => true,
        ]);
        $user1->assignRole('user');

        // ── Hotel 1: Jakarta ─────────────────────────────
        $hotel1 = Hotel::create([
            'owner_id'    => $owner->id,
            'name'        => 'Grand Arahinn Hotel Jakarta',
            'slug'        => 'grand-arahinn-hotel-jakarta',
            'description' => 'Hotel bintang 5 premium di pusat Jakarta. Fasilitas lengkap, pemandangan kota yang menakjubkan, dan pelayanan kelas dunia.',
            'address'     => 'Jl. MH Thamrin No. 1, Jakarta Pusat',
            'city'        => 'Jakarta',
            'province'    => 'DKI Jakarta',
            'country'     => 'Indonesia',
            'latitude'    => -6.1944,
            'longitude'   => 106.8229,
            'star_rating' => 5,
            'facilities'  => ['wifi', 'parking', 'pool', 'gym', 'spa', 'restaurant', 'bar', 'concierge', 'room_service'],
            'images'      => ['https://images.unsplash.com/photo-1566073771259-6a8506099945?w=800'],
            'status'      => 'approved',
            'approved_by' => $superadmin->id,
            'approved_at' => now(),
        ]);

        Room::insert([
            ['hotel_id' => $hotel1->id, 'name' => 'Superior Room',      'type' => 'superior',  'base_price' => 850000,  'max_guests' => 2, 'total_units' => 10, 'facilities' => '["ac","tv","wifi","minibar"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['hotel_id' => $hotel1->id, 'name' => 'Deluxe Room',        'type' => 'deluxe',    'base_price' => 1200000, 'max_guests' => 2, 'total_units' => 8,  'facilities' => '["ac","tv","wifi","minibar","bathtub"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['hotel_id' => $hotel1->id, 'name' => 'Junior Suite',       'type' => 'suite',     'base_price' => 2500000, 'max_guests' => 3, 'total_units' => 5,  'facilities' => '["ac","tv","wifi","jacuzzi","living_room"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['hotel_id' => $hotel1->id, 'name' => 'Presidential Suite', 'type' => 'suite',     'base_price' => 8000000, 'max_guests' => 4, 'total_units' => 2,  'facilities' => '["ac","tv","wifi","jacuzzi","butler","kitchen"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Hotel 2: Bali ─────────────────────────────────
        $hotel2 = Hotel::create([
            'owner_id'    => $owner->id,
            'name'        => 'Arahinn Bali Resort & Spa',
            'slug'        => 'arahinn-bali-resort-spa',
            'description' => 'Resort mewah tepi pantai Seminyak, Bali. Kolam renang infinity, spa kelas dunia, dan pemandangan sunset yang memukau.',
            'address'     => 'Jl. Kayu Aya No. 8, Seminyak, Bali',
            'city'        => 'Bali',
            'province'    => 'Bali',
            'country'     => 'Indonesia',
            'latitude'    => -8.6905,
            'longitude'   => 115.1610,
            'star_rating' => 5,
            'facilities'  => ['wifi', 'pool', 'spa', 'restaurant', 'beachfront', 'water_sports', 'airport_shuttle'],
            'images'      => ['https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?w=800'],
            'status'      => 'approved',
            'approved_by' => $superadmin->id,
            'approved_at' => now(),
        ]);

        Room::insert([
            ['hotel_id' => $hotel2->id, 'name' => 'Garden View Room',  'type' => 'standard',  'base_price' => 1500000, 'max_guests' => 2, 'total_units' => 12, 'facilities' => '["ac","tv","wifi","minibar"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['hotel_id' => $hotel2->id, 'name' => 'Ocean View Room',   'type' => 'deluxe',    'base_price' => 2800000, 'max_guests' => 2, 'total_units' => 8,  'facilities' => '["ac","tv","wifi","balcony","minibar"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['hotel_id' => $hotel2->id, 'name' => 'Pool Villa',        'type' => 'villa',     'base_price' => 6500000, 'max_guests' => 4, 'total_units' => 6,  'facilities' => '["ac","tv","wifi","private_pool","kitchen","butler"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Hotel 3: Yogyakarta ───────────────────────────
        $hotel3 = Hotel::create([
            'owner_id'    => $owner->id,
            'name'        => 'Arahinn Heritage Yogyakarta',
            'slug'        => 'arahinn-heritage-yogyakarta',
            'description' => 'Hotel butik bersejarah dekat Keraton dan Malioboro. Arsitektur Jawa klasik dengan kenyamanan modern.',
            'address'     => 'Jl. Prawirotaman No. 15, Mergangsan, Yogyakarta',
            'city'        => 'Yogyakarta',
            'province'    => 'DI Yogyakarta',
            'country'     => 'Indonesia',
            'latitude'    => -7.8014,
            'longitude'   => 110.3649,
            'star_rating' => 4,
            'facilities'  => ['wifi', 'parking', 'pool', 'restaurant', 'tour_service', 'bicycle_rental'],
            'images'      => ['https://images.unsplash.com/photo-1551882547-ff40c4d89ce3?w=800'],
            'status'      => 'approved',
            'approved_by' => $superadmin->id,
            'approved_at' => now(),
        ]);

        Room::insert([
            ['hotel_id' => $hotel3->id, 'name' => 'Standard Room',     'type' => 'standard',  'base_price' => 450000,  'max_guests' => 2, 'total_units' => 15, 'facilities' => '["ac","tv","wifi"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['hotel_id' => $hotel3->id, 'name' => 'Deluxe Joglo',      'type' => 'deluxe',    'base_price' => 750000,  'max_guests' => 2, 'total_units' => 8,  'facilities' => '["ac","tv","wifi","bathtub"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['hotel_id' => $hotel3->id, 'name' => 'Family Suite',      'type' => 'suite',     'base_price' => 1200000, 'max_guests' => 4, 'total_units' => 4,  'facilities' => '["ac","tv","wifi","extra_bed","living_room"]', 'images' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Promos ────────────────────────────────────────
        Promo::insert([
            [
                'code'           => 'ARAHINN50',
                'type'           => 'voucher',
                'name'           => 'Diskon Rp 50.000 untuk Booking Pertama',
                'description'    => 'Promo untuk user baru',                
                'discount_type'  => 'fixed',
                'discount_value' => 50000,
                'min_purchase'   => 200000,
                'max_discount'   => null,                
                'quota'          => 100,
                'used_count'     => 0,
                'start_date'     => now(),
                'end_date'       => now()->addMonth(),
                'is_active'      => 1,
                'created_by'     => $admin->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'code'           => 'WEEKEND20',
                'type'           => 'voucher',
                'name'           => 'Diskon 20% untuk Weekend',
                'description'    => 'Promo Weekend',    
                'discount_type'  => 'percent',
                'discount_value' => 20,
                'min_purchase'   => 500000,
                'max_discount'   => 300000,
                'quota'          => 50,
                'used_count'     => 0,
                'start_date'     => now(),
                'end_date'       => now()->addWeeks(2),
                'is_active'      => 1,
                'created_by'     => $admin->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'code'           => null,
                'type'           => 'flash_sale',
                'name'           => 'Flash Sale — Diskon 30% Semua Hotel',
                'description'    => 'Promo Terbatas',    
                'discount_type'  => 'percent',
                'discount_value' => 30,
                'min_purchase'   => 300000,
                'max_discount'   => 500000,
                'quota'          => 30,
                'used_count'     => 0,
                'start_date'     => now(),
                'end_date'       => now()->addDays(3),
                'is_active'      => 1,
                'created_by'     => $admin->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);

        $this->command->info('');
        $this->command->info('✅ Database seeded berhasil!');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📋 Akun (semua password: Password123!)');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('🔴 superadmin@otasystem.id  — SuperAdmin');
        $this->command->info('🟠 owner@otasystem.id       — Owner Property');
        $this->command->info('🟡 adminproperty@otasystem.id — Admin Property');
        $this->command->info('🟢 admin@otasystem.id       — Admin');
        $this->command->info('🔵 finance@otasystem.id     — Finance');
        $this->command->info('⚪ user@otasystem.id        — End User');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('🎟️  Promo: ARAHINN50, WEEKEND20, Flash Sale');
    }
}
