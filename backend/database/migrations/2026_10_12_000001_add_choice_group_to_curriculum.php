<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `choice_group` marks an either-or pair in a curriculum (a Science student takes Biology
 * or Higher Mathematics as compulsory, the other being the 4th subject). Rows of one class
 * and group sharing a slug form the pair; `exam_subjects` snapshots it like `paper_group`.
 * See App\Services\CurriculumService and App\Models\StudentEnrolment.
 *
 * Existing curricula are backfilled in PHP (the same on SQLite and MySQL): only a Class 9+
 * Science pair of Biology (BIO, compulsory) and Higher Mathematics (HMATH, optional), both
 * rows without a paper group or a choice group, gets `science-4th`. Anything else
 * is left alone, and a second run changes nothing. Existing exam snapshots and results are
 * not touched; an exam needs its schedule regenerated and its results reprocessed.
 */
return new class extends Migration
{
    private const SLUG = 'science-4th';

    public function up(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->string('choice_group', 50)->nullable()->after('paper_group');
        });

        Schema::table('exam_subjects', function (Blueprint $table) {
            $table->string('choice_group', 50)->nullable()->after('paper_group');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('exam_subjects', function (Blueprint $table) {
            $table->dropColumn('choice_group');
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropColumn('choice_group');
        });
    }

    private function backfill(): void
    {
        $rows = DB::table('class_subjects')
            ->join('classes', 'classes.id', '=', 'class_subjects.class_id')
            ->join('subjects', 'subjects.id', '=', 'class_subjects.subject_id')
            ->where('classes.number', '>=', 9)
            ->where('class_subjects.group', 'science')
            ->whereNull('class_subjects.paper_group')
            ->whereNull('class_subjects.choice_group')
            ->where(function ($q) {
                $q->where(fn ($q) => $q->where('subjects.code', 'BIO')->where('class_subjects.type', 'compulsory'))
                    ->orWhere(fn ($q) => $q->where('subjects.code', 'HMATH')->where('class_subjects.type', 'optional'));
            })
            ->get(['class_subjects.id', 'class_subjects.class_id', 'subjects.code']);

        foreach ($rows->groupBy('class_id') as $classRows) {
            $codes = $classRows->pluck('code')->all();

            // Exactly one of each; anything ambiguous is left alone.
            if (count($codes) === 2 && in_array('BIO', $codes, true) && in_array('HMATH', $codes, true)) {
                DB::table('class_subjects')->whereIn('id', $classRows->pluck('id')->all())->update(['choice_group' => self::SLUG]);
            }
        }
    }
};
