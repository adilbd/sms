<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Exam;
use App\Models\FeeHead;
use App\Models\FeePayment;
use App\Models\FeeRate;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Services\FeeDueService;
use App\Services\FeePaymentService;
use App\Support\AcademicGroup;
use Illuminate\Database\Seeder;

/**
 * Sample fees for the 2026 academic year: four heads (Tuition monthly, Session and
 * Admission one-time, Exam fee per exam), 2026 rates per class in realistic BDT (tuition
 * from ৳500 in the primary classes to ৳1,200 for Class 11-12 Science, with Science higher
 * than the other groups from Class 9), dues generated through FeeDueService up to the
 * current month, and two demo payments for the first Class 10 students: cash, and bKash
 * with the fake transaction ID DEMO0001 (collected through FeePaymentService, so they get
 * real receipts).
 *
 * Idempotent: heads are matched on code, rates on (head, class, year, group), dues on
 * their unique key (the service skips existing ones) and demo payments on the marker note,
 * so re-running adds nothing and never overwrites a rate an admin edited. Needs the
 * Role/Class/AcademicYear/Section/Student seeders (and the Exam seeder for exam dues) first.
 */
class FeeSeeder extends Seeder
{
    /** The note on a demo payment, which is how a re-run recognises it. */
    public const DEMO_NOTE = '[seed] demo payment';

    private const HEADS = [
        ['code' => 'TUITION', 'name_en' => 'Tuition Fee', 'name_bn' => 'বেতন', 'kind' => FeeHead::KIND_MONTHLY],
        ['code' => 'SESSION', 'name_en' => 'Session Fee', 'name_bn' => 'সেশন ফি', 'kind' => FeeHead::KIND_ONE_TIME],
        ['code' => 'EXAM', 'name_en' => 'Exam Fee', 'name_bn' => 'পরীক্ষার ফি', 'kind' => FeeHead::KIND_PER_EXAM],
        ['code' => 'ADMISSION', 'name_en' => 'Admission Fee', 'name_bn' => 'ভর্তি ফি', 'kind' => FeeHead::KIND_ONE_TIME],
    ];

    /**
     * Whole taka per class level: [primary 1-5, junior secondary 6-8, secondary 9-10,
     * higher secondary 11-12]. The Science rate (Class 9+) is the second number of a level.
     */
    private const AMOUNTS = [
        'TUITION' => [[500], [700], [900, 1100], [1000, 1200]],
        'SESSION' => [[1000], [1500], [2000, 2000], [2500, 2500]],
        'EXAM' => [[150], [200], [300, 300], [400, 400]],
        'ADMISSION' => [[2000], [3000], [4000, 4000], [5000, 5000]],
    ];

    public function run(FeeDueService $dues, FeePaymentService $payments): void
    {
        $year = AcademicYear::where('year', 2026)->first();

        if (! $year) {
            return;
        }

        $heads = [];

        foreach (self::HEADS as $head) {
            $heads[$head['code']] = FeeHead::withTrashed()->firstOrCreate(['code' => $head['code']], $head + ['is_active' => true]);
        }

        foreach (Classes::orderBy('number')->get() as $class) {
            $this->seedRates($heads, $class, $year);
        }

        $dues->generate(['academic_year_id' => $year->id]);

        foreach (Exam::where('academic_year_id', $year->id)->get() as $exam) {
            $dues->generate(['academic_year_id' => $year->id, 'exam_id' => $exam->id]);
        }

        $this->seedPayments($payments, $year);
    }

    /**
     * @param  array<string, FeeHead>  $heads
     */
    private function seedRates(array $heads, Classes $class, AcademicYear $year): void
    {
        $level = match (true) {
            $class->number <= 5 => 0,
            $class->number <= 8 => 1,
            $class->number <= 10 => 2,
            default => 3,
        };

        foreach (self::AMOUNTS as $code => $levels) {
            $amounts = $levels[$level];
            $dueDay = $code === 'TUITION' ? 10 : null;

            $this->rate($heads[$code], $class, $year, null, $amounts[0], $dueDay);

            if ($class->hasGroups()) {
                // Science pays the second amount; the other groups use the class-wide rate.
                $this->rate($heads[$code], $class, $year, AcademicGroup::SCIENCE, $amounts[1] ?? $amounts[0], $dueDay);
            }
        }
    }

    private function rate(FeeHead $head, Classes $class, AcademicYear $year, ?string $group, int $taka, ?int $dueDay): void
    {
        FeeRate::firstOrCreate(
            ['fee_head_id' => $head->id, 'class_id' => $class->id, 'academic_year_id' => $year->id, 'group' => $group],
            ['amount' => number_format($taka, 2, '.', ''), 'due_day' => $dueDay],
        );
    }

    private function seedPayments(FeePaymentService $payments, AcademicYear $year): void
    {
        $admin = User::where('email', 'admin@sms.com')->first();
        $class = Classes::where('number', 10)->first();

        if (! $admin || ! $class) {
            return;
        }

        $enrolments = StudentEnrolment::query()
            ->activeIn($year->id)
            ->where('class_id', $class->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        $demo = [
            ['amount' => '1000.00', 'method' => FeePayment::METHOD_CASH, 'transaction_id' => null],
            ['amount' => '900.00', 'method' => FeePayment::METHOD_BKASH, 'transaction_id' => 'DEMO0001'],
        ];

        foreach ($enrolments as $i => $enrolment) {
            if (FeePayment::where('student_id', $enrolment->student_id)->where('note', self::DEMO_NOTE)->exists()) {
                continue;
            }

            $payments->collect([
                'student_id' => $enrolment->student_id,
                'note' => self::DEMO_NOTE,
                ...$demo[$i],
            ], $admin);
        }
    }
}
