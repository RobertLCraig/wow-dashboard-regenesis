<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bis_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('class', 32);          // 'death_knight', 'demon_hunter', etc.
            $table->string('spec', 32);           // 'frost', 'balance', etc.
            $table->string('hero_talent', 32)->nullable();  // 'rider', 'void_scarred', null for default profile
            $table->string('profile_name');       // 'MID1_Death_Knight_Frost_Rider'
            $table->string('source_path');        // 'profiles/MID1/MID1_Death_Knight_Frost_Rider.simc'
            $table->json('parsed_data');          // gear/consumables/etc - see SimcProfileParser
            $table->timestamp('captured_at');
            $table->timestamps();

            // One profile per class+spec+hero_talent. Hero-talent variants
            // get their own row; the default (no hero in filename) is a
            // distinct row with hero_talent=NULL.
            $table->unique(['class', 'spec', 'hero_talent'], 'bis_profiles_class_spec_hero_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bis_profiles');
    }
};
