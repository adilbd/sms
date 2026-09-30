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

        $this->seed([SubjectSeeder::class, CurriculumSeeder::class]);

        $this->assertSame($subjects, Subject::count());
        $this->assertSame($rows, ClassSubject::count());
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
