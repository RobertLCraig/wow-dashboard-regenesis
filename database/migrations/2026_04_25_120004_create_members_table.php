<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('guild_key')->index();
            // "Char-Realm" e.g. "Totemtaeven-Silvermoon". GRM uses this
            // as the SavedVariables table key and that's the only stable
            // identity across snapshots (GUIDs change on transfer).
            $table->string('name');
            $table->string('guid')->nullable();

            $table->string('class', 32)->nullable();
            $table->string('race', 32)->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->string('sex', 16)->nullable();
            $table->string('faction', 16)->nullable();

            $table->string('rank_name')->nullable();
            $table->unsignedTinyInteger('rank_index')->nullable();

            $table->date('join_date')->nullable();
            $table->boolean('join_date_unknown')->default(false);
            $table->timestamp('last_online_at')->nullable()->index();
            $table->boolean('is_online')->default(false);
            $table->boolean('is_mobile')->default(false);

            // 'active' (in roster), 'left' (kicked themselves or removed),
            // 'banned' (in PlayersThatLeftHistory with bannedInfo[1]=true)
            $table->string('status', 16)->default('active')->index();

            $table->unsignedInteger('achievement_points')->nullable();
            $table->unsignedTinyInteger('guild_rep')->nullable();
            $table->boolean('hardcore_is_dead')->default(false);

            $table->unsignedSmallInteger('profession_1_id')->nullable();
            $table->unsignedSmallInteger('profession_1_skill')->nullable();
            $table->unsignedSmallInteger('profession_2_id')->nullable();
            $table->unsignedSmallInteger('profession_2_skill')->nullable();

            // Self-FK: nullable means "this member IS a main".
            $table->unsignedBigInteger('main_member_id')->nullable()->index();
            $table->foreignId('alt_group_id')->nullable()->constrained('alt_groups')->nullOnDelete();
            // Mirror of GRM's altGroup string ID for traceability.
            $table->string('alt_group_label')->nullable()->index();

            $table->text('public_note')->nullable();
            $table->text('officer_note')->nullable();
            $table->text('custom_note')->nullable();
            $table->date('birthday')->nullable();
            $table->string('country', 64)->nullable();
            $table->string('zone')->nullable();

            // Officer-actionable flags GRM tracks. We mirror them so the
            // dashboard can render the action queue widget.
            $table->boolean('recommend_promote')->default(false)->index();
            $table->boolean('recommend_demote')->default(false)->index();
            $table->boolean('recommend_kick')->default(false)->index();
            $table->boolean('recommend_special')->default(false);

            // Ban metadata copied from PlayersThatLeftHistory.bannedInfo.
            $table->text('reason_banned')->nullable();
            $table->timestamp('banned_at')->nullable();

            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['guild_key', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
