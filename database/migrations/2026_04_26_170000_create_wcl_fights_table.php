<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per encounter pull within a WCL report. `fight_id` is WCL's
 * within-report sequence (1..N), so the unique key is (report_id,
 * fight_id) - the same fight ID can repeat across reports.
 *
 * `kill` is true when the encounter was a kill (best_percentage = 0
 * server-side). Trash + non-encounter pulls (encounter_id = 0) are
 * filtered out by the importer; only real boss attempts land here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wcl_fights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wcl_report_id')->constrained('wcl_reports')->cascadeOnDelete();
            // WCL's per-report fight sequence number. Stable within a
            // report so the unique key + the report give us a global PK.
            $table->unsignedInteger('fight_id');
            $table->unsignedInteger('encounter_id')->index();
            $table->string('name');
            // 1=LFR, 3=Normal, 4=Heroic, 5=Mythic. Null on synthetic
            // pulls where WCL doesn't surface a difficulty.
            $table->unsignedTinyInteger('difficulty')->nullable()->index();
            $table->boolean('kill')->default(false)->index();
            // Best % so far across this raid night for the encounter.
            // 0 = killed; ~30 = pulled and got it to 30%.
            $table->decimal('best_percentage', 5, 2)->nullable();
            // Pull duration in milliseconds (so we can rank wipes by length).
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['wcl_report_id', 'fight_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wcl_fights');
    }
};
