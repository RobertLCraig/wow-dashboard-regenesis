<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            // Synthetic events (anniversaries, became_inactive_30d) may
            // not originate from a snapshot diff; nullable snapshot_id.

            // Enum of derived signals. See GrmSnapshotDiffer for the
            // detection rules.
            //   joined, returned, left, kicked, banned,
            //   promoted, demoted, level_up, note_changed,
            //   marked_for_promote, marked_for_demote, marked_for_kick,
            //   became_inactive_30d, anniversary
            $table->string('type', 32)->index();
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['member_id', 'type']);
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_events');
    }
};
