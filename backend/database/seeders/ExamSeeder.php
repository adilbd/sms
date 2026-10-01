<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\User;
use App\Repositories\Contracts\ExamMarkRepositoryInterface;
use App\Services\ExamService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One 2026 Half-Yearly exam for Classes 9 and 10, open for mark entry, with marks for
 * Section A of the Morning shift: deterministic (a hash of the student and subject, no
 * randomness), varied from clear passes down to a part below its pass mark, and with two
 * absences per class. Task 3 uses this data for its GPA checks.
 *
 * Matched by the exam's (academic year, code): when that exam already exists nothing is
 * touched, so re-running adds nothing and never overwrites marks an admin has entered.
 * Needs the class, subject, curriculum, academic year, section and student seeders first.
 * The schedule goes through ExamService (the same snapshot as the API); marks are written
 * through the models directly.
 */
class ExamSeeder extends Seeder
{
    public const CODE = 'HY-2026';

    private const CLASS_NUMBERS = [9, 10];

    public function run(): void
    {
        $year = AcademicYear::where('year', 2026)->first();
        $classes = Classes::whereIn('number', self::CLASS_NUMBERS)->orderBy('number')->get();

        if (! $year || $classes->count() !== count(self::CLASS_NUMBERS)
            || Exam::withTrashed()->where('academic_year_id', $year->id)->where('code', self::CODE)->exists()
            || $classes->contains(fn (Classes $class) => ! ClassSubject::where('class_id', $class->id)->exists())) {
            return;
        }

        DB::transaction(function () use ($year, $classes) {
            $service = app(ExamService::class);

            $exam = $service->create([
                'academic_year_id' => $year->id,
                'name_en' => 'Half Yearly Exam 2026',
                'name_bn' => 'অর্ধবার্ষিক পরীক্ষা ২০২৬',
                'code' => self::CODE,
                'type' => Exam::TYPE_HALF_YEARLY,
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-30',
                'class_ids' => $classes->pluck('id')->all(),
            ]);

            $this->scheduleSubjects($exam);
            $service->openMarksEntry($exam);

            $enteredBy = User::where('email', 'admin@sms.com')->value('id');

            foreach ($classes as $class) {
                $section = Section::where('class_id', $class->id)
                    ->where('code', 'A')
                    ->whereHas('shift', fn ($q) => $q->where('slug', 'morning'))
                    ->first();

                if ($section) {
                    $this->enterMarks($exam, $section, $class, $enteredBy);
                }
            }
        });
    }

    /**
     * One subject a day from the exam's start date, skipping Fridays (a school holiday in
     * Bangladesh), 10:00 to 13:00 Asia/Dhaka.
     */
    private function scheduleSubjects(Exam $exam): void
    {
        foreach ($exam->examSubjects()->orderBy('class_id')->orderBy('sort_order')->orderBy('id')->get()->groupBy('class_id') as $subjects) {
            $date = $exam->start_date->copy();

            foreach ($subjects as $subject) {
                while ($date->isFriday()) {
                    $date->addDay();
                }

                $subject->update(['exam_date' => $date->toDateString(), 'start_time' => '10:00', 'end_time' => '13:00']);
                $date->addDay();
            }
        }
    }

    private function enterMarks(Exam $exam, Section $section, Classes $class, ?int $enteredBy): void
    {
        $sheets = app(ExamMarkRepositoryInterface::class);

        foreach ($exam->examSubjects()->where('class_id', $class->id)->orderBy('sort_order')->get() as $subject) {
            foreach ($sheets->sheetEnrolments($exam, $section, $subject) as $enrolment) {
                $absent = $this->isAbsent((int) $enrolment->roll_number, $subject);

                ExamMark::updateOrCreate(
                    ['exam_subject_id' => $subject->id, 'student_id' => $enrolment->student_id],
                    [
                        'enrolment_id' => $enrolment->id,
                        'is_absent' => $absent,
                        'entered_by' => $enteredBy,
                        ...$this->parts($subject, $enrolment->student_id, $absent),
                    ],
                );
            }
        }
    }

    /**
     * Roll 3 misses the first subject and roll 5 the fifth, in every class.
     */
    private function isAbsent(int $roll, ExamSubject $subject): bool
    {
        return ($roll === 3 && $subject->sort_order === 0) || ($roll === 5 && $subject->sort_order === 4);
    }

    /**
     * A base percentage of 28 to 94 from a hash of the student and subject, nudged by up
     * to 5 points per part, rounded to half marks and never above the part's full mark. The
     * low end dips below a part's pass mark for some students, so there are failures too.
     *
     * @return array<string, float|null>
     */
    private function parts(ExamSubject $subject, int $studentId, bool $absent): array
    {
        $marks = ['written' => null, 'mcq' => null, 'practical' => null];

        if ($absent) {
            return $marks;
        }

        $base = 28 + crc32("{$studentId}-{$subject->subject_id}") % 67;

        foreach (array_keys($marks) as $position => $part) {
            $full = $subject->{"{$part}_full"};

            if ($full === null) {
                continue;
            }

            $percent = $base + (crc32("{$studentId}-{$subject->subject_id}-{$part}") % 11) - 5;
            $marks[$part] = min((float) $full, max(0.0, round($full * $percent / 100 * 2) / 2));
        }

        return $marks;
    }
}
