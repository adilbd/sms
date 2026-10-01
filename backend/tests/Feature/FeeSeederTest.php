<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\FeeDue;
use App\Models\FeeHead;
use App\Models\FeePayment;
use App\Models\FeeRate;
use Database\Seeders\AcademicYearSeeder;
use Database\Seeders\ClassSeeder;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\ExamSeeder;
use Database\Seeders\FeeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SectionSeeder;
use Database\Seeders\ShiftSeeder;
use Database\Seeders\StudentSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrerequisites(): void
    {
        $this->travelTo('2026-10-15 06:00:00');
        $this->seed([
            RolePermissionSeeder::class, ShiftSeeder::class, ClassSeeder::class, SubjectSeeder::class,
            CurriculumSeeder::class, AcademicYearSeeder::class, SectionSeeder::class, StudentSeeder::class,
            ExamSeeder::class,
        ]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'heads' => FeeHead::count(),
            'rates' => FeeRate::count(),
            'dues' => FeeDue::count(),
            'payments' => FeePayment::count(),
            'allocations' => DB::table('fee_payment_allocations')->count(),
            'counter' => (int) DB::table('fee_receipt_counters')->value('last_number'),
        ];
    }

    public function test_it_seeds_heads_rates_dues_and_two_demo_payments(): void
    {
        $this->seedPrerequisites();

        $this->seed(FeeSeeder::class);

        $this->assertSame(['TUITION', 'SESSION', 'EXAM', 'ADMISSION'], FeeHead::orderBy('id')->pluck('code')->all());
        $this->assertSame(['monthly', 'one_time', 'per_exam', 'one_time'], FeeHead::orderBy('id')->pluck('kind')->all());

        // Four heads for 12 classes, plus a Science rate per head for Classes 9-12.
        $this->assertSame(4 * 12 + 4 * 4, FeeRate::count());
        $tuition = fn (int $number, ?string $group = null) => FeeRate::whereHas('head', fn ($q) => $q->where('code', 'TUITION'))
            ->where('class_id', Classes::where('number', $number)->value('id'))
            ->where('group', $group)->value('amount');
        $this->assertSame('500.00', $tuition(1));
        $this->assertSame('700.00', $tuition(7));
        $this->assertSame('900.00', $tuition(10));
        $this->assertSame('1100.00', $tuition(10, 'science'));
        $this->assertSame('1200.00', $tuition(12, 'science'));

        // Tuition dues January to October (the current month) for every seeded student.
        $students = \App\Models\StudentEnrolment::count();
        $this->assertGreaterThan(0, $students);
        $this->assertSame($students * 10, FeeDue::whereHas('head', fn ($q) => $q->where('code', 'TUITION'))->count());
        $this->assertSame($students, FeeDue::whereHas('head', fn ($q) => $q->where('code', 'SESSION'))->count());
        // The exam fee goes to the exam's two classes only.
        $this->assertSame(\App\Models\StudentEnrolment::whereIn('class_id', Classes::whereIn('number', [9, 10])->pluck('id'))->count(), FeeDue::whereHas('head', fn ($q) => $q->where('code', 'EXAM'))->count());

        $payments = FeePayment::orderBy('id')->get();
        $this->assertSame(['cash', 'bkash'], $payments->pluck('method')->all());
        $this->assertSame([null, 'DEMO0001'], $payments->pluck('transaction_id')->all());
        $this->assertSame(['2026-000001', '2026-000002'], $payments->pluck('receipt_no')->all());
        $this->assertSame(['1000.00', '900.00'], $payments->pluck('amount')->all());
        $this->assertSame(2, FeePayment::where('note', FeeSeeder::DEMO_NOTE)->count());
    }

    public function test_running_it_twice_creates_no_duplicates(): void
    {
        $this->seedPrerequisites();

        $this->seed(FeeSeeder::class);
        $first = $this->counts();
        $this->seed(FeeSeeder::class);

        $this->assertSame($first, $this->counts());
    }

    public function test_it_does_not_overwrite_an_edited_rate(): void
    {
        $this->seedPrerequisites();
        $this->seed(FeeSeeder::class);
        $rate = FeeRate::firstOrFail();
        $rate->update(['amount' => '12345.00']);

        $this->seed(FeeSeeder::class);

        $this->assertSame('12345.00', $rate->fresh()->amount);
    }

    public function test_it_does_nothing_without_the_2026_academic_year(): void
    {
        $this->seed(FeeSeeder::class);

        $this->assertSame(0, FeeHead::count());
    }

    public function test_the_database_seeder_runs_it(): void
    {
        $this->travelTo('2026-10-15 06:00:00');

        $this->seed();

        $this->assertSame(4, FeeHead::count());
        $this->assertSame(2, FeePayment::count());
    }
}
