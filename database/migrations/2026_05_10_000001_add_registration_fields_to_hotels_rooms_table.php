<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Tambahan kolom hotels ──────────────────────────
        Schema::table('hotels', function (Blueprint $table) {

            // Step 2 – Info Umum
            $table->string('alias')->nullable()->after('name');
            $table->boolean('is_brand_chain')->default(false)->after('alias');
            $table->string('currency', 10)->default('IDR')->after('is_brand_chain');
            $table->string('postal_code', 10)->nullable()->after('country');
            $table->json('guest_types')->nullable()->after('postal_code');

            // Step 3 – Kontak PIC
            $table->string('pic_position')->nullable()->after('guest_types');
            $table->string('pic_phone', 30)->nullable()->after('pic_position');
            $table->string('property_phone', 30)->nullable()->after('pic_phone');
            $table->string('fax', 30)->nullable()->after('property_phone');

            // Step 4 – Perjanjian / Legalitas
            $table->string('company_name')->nullable()->after('fax');
            $table->text('company_address')->nullable()->after('company_name');
            $table->string('company_country', 100)->default('Indonesia')->after('company_address');
            $table->string('agree_name')->nullable()->after('company_country');
            $table->string('agree_position')->nullable()->after('agree_name');
            $table->string('agree_email')->nullable()->after('agree_position');
            $table->string('agree_phone', 30)->nullable()->after('agree_email');

            // Step 5 – Platform lain
            $table->json('platforms')->nullable()->after('agree_phone');

            // Step 8 – Kebijakan Umum
            $table->boolean('gender_policy')->nullable()->after('platforms');
            $table->boolean('marriage_book')->nullable()->after('gender_policy');
            $table->boolean('deposit_required')->nullable()->after('marriage_book');
            $table->boolean('all_ages_allowed')->nullable()->after('deposit_required');
            $table->boolean('breakfast_available')->nullable()->after('all_ages_allowed');
            $table->string('breakfast_start', 5)->nullable()->after('breakfast_available'); // "06:00"
            $table->string('breakfast_end', 5)->nullable()->after('breakfast_start');
            $table->boolean('smoking_allowed')->nullable()->after('breakfast_end');
            $table->boolean('alcohol_allowed')->nullable()->after('smoking_allowed');
            $table->boolean('pets_allowed')->nullable()->after('alcohol_allowed');

            // Step 11 – Pembayaran
            $table->string('cancellation_policy')->nullable()->after('pets_allowed');
            $table->string('payment_method', 20)->nullable()->after('cancellation_policy');
            $table->string('bank_name')->nullable()->after('payment_method');
            $table->string('bank_branch')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_branch');
            $table->string('bank_account_number', 30)->nullable()->after('bank_account_name');
            $table->json('vcc_accepted_types')->nullable()->after('bank_account_number');
            $table->string('vcc_email')->nullable()->after('vcc_accepted_types');
            $table->string('vcc_account_name')->nullable()->after('vcc_email');
            $table->string('npwp_type', 20)->nullable()->after('vcc_account_name');
            $table->string('npwp_number', 30)->nullable()->after('npwp_type');
            $table->string('npwp_name')->nullable()->after('npwp_number');
            $table->string('npwp_doc')->nullable()->after('npwp_name');
            $table->string('nitku_number', 30)->nullable()->after('npwp_doc');
            $table->string('nitku_name')->nullable()->after('nitku_number');
            $table->string('nitku_doc')->nullable()->after('nitku_name');
            $table->string('npwp_support_doc')->nullable()->after('nitku_doc');

            // Step 12 – Sumber registrasi
            $table->string('registration_source')->nullable()->after('npwp_support_doc');
        });

        // ── Tambahan kolom rooms ───────────────────────────
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('smoking_policy')->nullable()->after('description');
            $table->boolean('has_bedrooms')->nullable()->after('smoking_policy');
            $table->json('bed_configs')->nullable()->after('has_bedrooms');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn([
                'alias','is_brand_chain','currency','postal_code','guest_types',
                'pic_position','pic_phone','property_phone','fax',
                'company_name','company_address','company_country',
                'agree_name','agree_position','agree_email','agree_phone',
                'platforms',
                'gender_policy','marriage_book','deposit_required','all_ages_allowed',
                'breakfast_available','breakfast_start','breakfast_end',
                'smoking_allowed','alcohol_allowed','pets_allowed',
                'cancellation_policy',
                'payment_method','bank_name','bank_branch','bank_account_name','bank_account_number',
                'vcc_accepted_types','vcc_email','vcc_account_name',
                'npwp_type','npwp_number','npwp_name','npwp_doc',
                'nitku_number','nitku_name','nitku_doc','npwp_support_doc',
                'registration_source',
            ]);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['smoking_policy','has_bedrooms','bed_configs']);
        });
    }
};
