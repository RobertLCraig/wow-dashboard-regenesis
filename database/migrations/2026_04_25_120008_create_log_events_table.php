<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_events', function (Blueprint $table) {
            $table->id();
            $table->string('guild_key')->index();
            $table->timestamp('occurred_at')->index();

            // GRM_LogReport_Save uses an integer type code per row. Some
            // observed: 1=PROMOTED, 2=DEMOTED, 4=PUBLIC note change,
            // 5=OFFICER note change, 14=came-online-after-inactive.
            // Full mapping derived in GrmNormalizer from GRM_Log.lua.
            $table->unsignedSmallInteger('type_code')->index();
            $table->string('type_name', 64)->nullable();

            $table->string('actor')->nullable();
            $table->string('target')->nullable();
            // The rendered string GRM stores at index [2] of the row, with
            // |c|r colour codes still embedded. UI-side code strips them.
            $table->text('message_raw')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            // Idempotency: re-ingesting the same snapshot must not
            // duplicate log rows. Hash these three for the unique constraint.
            $table->string('dedup_hash', 64)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_events');
    }
};
