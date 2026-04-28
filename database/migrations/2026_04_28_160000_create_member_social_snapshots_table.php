<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character "social/cosmetic" data: character media, achievements,
 * collections (mounts/pets/toys/transmogs).
 *
 * Powers three dashboard surfaces:
 *   - Avatars / 3D renders for the social pages and transmog review
 *   - Achievement gap analysis ("X members still missing AOTC")
 *   - Collection gap analysis ("plan a farm event for mount Y")
 *
 * Stored as raw JSON per endpoint with a few denormalised counts
 * (mounts/pets/toys totals + achievement points + total
 * quantity_collected) for cheap roster sorting/filtering. Snapshot
 * dedupe via payload_hash means unchanged collections (the common
 * case - drops are infrequent) reuse a single row across pulls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_social_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('snapshots')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();

            $table->json('character_media')->nullable();
            $table->json('achievements')->nullable();
            $table->json('mounts')->nullable();
            $table->json('pets')->nullable();
            $table->json('toys')->nullable();
            $table->json('transmogs')->nullable();

            $table->unsignedInteger('achievement_points')->nullable();
            $table->unsignedInteger('total_mounts')->nullable();
            $table->unsignedInteger('total_pets')->nullable();
            $table->unsignedInteger('total_toys')->nullable();

            $table->timestamps();

            $table->unique(['snapshot_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_social_snapshots');
    }
};
