<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bis_profiles', function (Blueprint $table) {
            // Whose BiS list this row is: simc, wowhead, manual. One row per
            // class+spec+hero_talent per source, so sources sit side by side.
            $table->string('source', 16)->default('simc');
            $table->dropUnique('bis_profiles_class_spec_hero_unique');
            $table->unique(['class', 'spec', 'hero_talent', 'source'], 'bis_profiles_class_spec_hero_source_unique');
            $table->index('source');
        });

        // SimC rows point at a .simc file; bis:seed-healers stamps its JSON
        // data file's path, so those are the healer stubs.
        DB::table('bis_profiles')->where('source_path', 'like', '%.json')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('bis_profiles', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropUnique('bis_profiles_class_spec_hero_source_unique');
            $table->dropColumn('source');
            $table->unique(['class', 'spec', 'hero_talent'], 'bis_profiles_class_spec_hero_unique');
        });
    }
};
