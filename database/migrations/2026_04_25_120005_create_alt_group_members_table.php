<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alt_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alt_group_id')->constrained('alt_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->unique(['alt_group_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alt_group_members');
    }
};
