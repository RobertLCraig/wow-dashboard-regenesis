<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_announcements', function (Blueprint $table) {
            // One entry per Discord attachment: id, filename, content_type,
            // size, width, height, url, proxy_url. The attachment id +
            // the row's discord_message_id are the durable identifiers -
            // Discord CDN urls carry an expiry signature, so the urls are
            // a cache the hourly pull refreshes, not a permanent address.
            $table->json('attachments')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('discord_announcements', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
