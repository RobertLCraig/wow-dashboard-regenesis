<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user Google OAuth + the id of the dedicated "Regenesis Officers"
 * calendar this user owns. Only one user is ever the "connector" at a
 * time: identified by google_calendar_connected_at being non-null. The
 * OAuth callback null-clears any other row's google fields before
 * persisting the new connection so the invariant holds.
 *
 * Refresh + access tokens are encrypted at rest via Attribute casts on
 * the User model, mirroring the discord_refresh_token shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Long-lived refresh token. Encrypted at rest by the
            // googleRefreshToken Attribute cast on the User model.
            $table->text('google_refresh_token')->nullable()->after('discord_refresh_token');
            // Short-lived access token cached so the queue worker
            // doesn't re-exchange on every job. Encrypted at rest.
            $table->text('google_access_token')->nullable()->after('google_refresh_token');
            $table->timestamp('google_token_expires_at')->nullable()->after('google_access_token');
            // Calendar id Google returns from calendars.insert. The
            // connecting officer's primary calendar is never used; we
            // always create a dedicated one so it can be shared cleanly
            // and rebuilt without touching personal data.
            $table->string('google_calendar_id', 255)->nullable()->after('google_token_expires_at');
            // Sentinel + timestamp. Non-null on exactly one row at a
            // time; that row is the connector.
            $table->timestamp('google_calendar_connected_at')->nullable()->after('google_calendar_id');
            // Display-only Gmail address pulled from Google's userinfo
            // endpoint at connect time. Used in the admin UI ("connected
            // as foo@gmail.com"); never used to identify the user.
            $table->string('google_email', 255)->nullable()->after('google_calendar_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'google_refresh_token',
                'google_access_token',
                'google_token_expires_at',
                'google_calendar_id',
                'google_calendar_connected_at',
                'google_email',
            ]);
        });
    }
};
