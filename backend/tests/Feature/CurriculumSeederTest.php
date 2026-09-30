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
        $this->assertSame(['BGS'], $this->codes(10, 'business_studies', 'compulsory', ['BGS']));
        $this->assertSame(['SCI'], $this->codes(10, 'humanities', 'compulsory', ['SCI']));
        $this->assertSame([], $this->codes(10, 'science', 'compulsory', ['SCI']));

        // A re-run updates a renamed subject, matched on its code, and keeps the rows.
        Subject::where('code', 'GEO')->update(['name' => 'Geography']);

        $this->seed([SubjectSeeder::class, CurriculumSeeder::class]);

        $this->assertSame('Geography & Environment', Subject::where('code', 'GEO')->value('name'));

        $this->assertSame($subjects, Subject::count());
        $this->assertSame($rows, ClassSubject::count());
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
