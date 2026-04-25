<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('discord_id')->nullable()->unique()->after('id');
            $table->string('discord_username')->nullable()->after('discord_id');
            $table->string('avatar_url')->nullable()->after('discord_username');
            // Highest matched Discord role at last sign-in. v1 treats all
            // three the same (any grants full access); v2 may diverge per
            // feature via Laravel Gates.
            $table->string('tier', 16)->nullable()->after('avatar_url');
            $table->text('discord_refresh_token')->nullable()->after('tier');
            $table->timestamp('last_role_check_at')->nullable()->after('discord_refresh_token');
            // Random per-user token used to authorise the personal webcal
            // subscription URL. Regeneratable from the user settings page.
            $table->string('calendar_token', 64)->nullable()->unique()->after('last_role_check_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'discord_id',
                'discord_username',
                'avatar_url',
                'tier',
                'discord_refresh_token',
                'last_role_check_at',
                'calendar_token',
            ]);
        });
    }
};
