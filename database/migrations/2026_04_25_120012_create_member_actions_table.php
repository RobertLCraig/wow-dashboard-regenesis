<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Officer review state for the action queue widgets. GRM's
        // recommend_* flags surface a member as "needs attention"; this
        // table records what an officer did about it (accepted, dismissed,
        // snoozed) so the queue doesn't keep nagging.
        Schema::create('member_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // 'promote' | 'demote' | 'kick' | 'special'
            $table->string('action_type', 16)->index();
            // 'accepted' | 'dismissed' | 'snoozed'
            $table->string('decision', 16);
            $table->text('notes')->nullable();
            $table->timestamp('snooze_until')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_actions');
    }
};
