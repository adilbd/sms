<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Subject;
use Database\Seeders\ClassSeeder;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_subjects_and_a_curriculum_for_every_class_and_is_idempotent(): void
    {
        $this->seed([ClassSeeder::class, SubjectSeeder::class, CurriculumSeeder::class]);

        $subjects = Subject::count();
        $rows = ClassSubject::count();

        $this->assertGreaterThan(0, $subjects);
        $this->assertSame('বাংলা', Subject::where('code', 'BAN')->value('name_bn'));

        foreach (Classes::all() as $class) {
            $this->assertTrue($class->curriculum()->exists(), "Class {$class->number} has no curriculum");
        }

        // Only Class 9+ carry groups and optional (4th) subjects.
        $this->assertFalse(ClassSubject::whereIn('class_id', Classes::where('number', '<', 9)->pluck('id'))
            ->where(fn ($q) => $q->whereNotNull('group')->orWhere('type', 'optional'))->exists());
        $this->assertTrue(ClassSubject::where('type', 'optional')->where('group', 'science')->exists());

        $this->assertSame('Geography & Environment', Subject::where('code', 'GEO')->value('name'));
        $this->assertSame(
            ['BIO', 'CHE', 'PHY'],
            $this->codes(9, 'science', 'compulsory', ['PHY', 'CHE', 'BIO']),
        );
        $this->assertSame(['AGR', 'HMATH'], $this->codes(9, 'science', 'optional', ['HMATH', 'AGR', 'BIO']));
        $this->assertSame(['BGS'], $this->codes(10, 'science', 'compulsory', ['BGS']));
        $this->assertSame([], $this->codes(10, 'business_studies', 'compulsory', ['BGS']));
        $this->assertSame(['SCI'], $this->codes(10, 'business_studies', 'compulsory', ['SCI']));
        $this->assertSame(['SCI'], $this->codes(10, 'humanities', 'compulsory', ['SCI']));
        $this->assertSame([], $this->codes(10, 'science', 'compulsory', ['SCI']));

        // SSC/HSC marks and paper pairs for Class 9-12; the subject's own marks below.
        $this->assertSame(
            [50, 17, 25, 8, 25, 8],
            $this->marks(9, 'PHY', 'science'),
        );
        $this->assertSame([70, 23, 30, 10, null, null], $this->marks(11, 'ACC', 'business_studies'));
        $this->assertSame([100, 33, null, null, null, null], $this->marks(1, 'BAN', null));
        $this->assertSame(['bangla', 'bangla'], $this->curriculumOf(10)->whereIn('subject.code', ['BAN1', 'BAN2'])->pluck('paper_group')->all());
        $this->assertSame(['english', 'english'], $this->curriculumOf(12)->whereIn('subject.code', ['ENG1', 'ENG2'])->pluck('paper_group')->all());
        $this->assertSame(0, ClassSubject::whereNotNull('paper_group')->whereIn('class_id', Classes::where('number', '<', 9)->pluck('id'))->count());
        $this->assertSame(0, ClassSubject::whereNull('written_full')->count());

        // A re-run updates a renamed subject, matched on its code, and keeps the rows.
        Subject::where('code', 'GEO')->update(['name' => 'Geography']);

        $this->seed([SubjectSeeder::class, CurriculumSeeder::class]);

        $this->assertSame('Geography & Environment', Subject::where('code', 'GEO')->value('name'));

        $this->assertSame($subjects, Subject::count());
        $this->assertSame($rows, ClassSubject::count());
    }

    private function curriculumOf(int $number)
    {
        return Classes::where('number', $number)->firstOrFail()->curriculum()->with('subject')->get();
    }

    /** @return list<?int> */
    private function marks(int $number, string $code, ?string $group): array
    {
        $row = $this->curriculumOf($number)->first(fn ($r) => $r->subject->code === $code && $r->group === $group);

        return [$row->written_full, $row->written_pass, $row->mcq_full, $row->mcq_pass, $row->practical_full, $row->practical_pass];
    }

    /** @return list<string> sorted codes of $codes present in the class's group/type */
    private function codes(int $number, string $group, string $type, array $codes): array
    {
        $found = Classes::where('number', $number)->firstOrFail()->curriculum()
            ->where('group', $group)->where('type', $type)
            ->whereIn('subject_id', Subject::whereIn('code', $codes)->pluck('id'))
            ->with('subject')->get()->pluck('subject.code')->sort()->values()->all();

        return $found;
    }

    public function test_never_overwrites_a_class_that_already_has_a_curriculum(): void
    {
        $this->seed([ClassSeeder::class, SubjectSeeder::class]);
        $class = Classes::where('number', 1)->firstOrFail();
        $only = Subject::where('code', 'MATH')->firstOrFail();
        ClassSubject::create(['class_id' => $class->id, 'subject_id' => $only->id]);

        $this->seed(CurriculumSeeder::class);

        $this->assertSame([$only->id], $class->curriculum()->pluck('subject_id')->all());
    }
}
