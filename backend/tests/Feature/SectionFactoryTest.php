<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\ClassSection;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_thirty_sections_in_one_class_never_collide(): void
    {
        $class = Classes::factory()->create();

        $sections = Section::factory()->count(30)->create(['class_id' => $class->id, 'shift_id' => \App\Models\Shift::factory()->create()->id]);

        $this->assertCount(30, $sections->pluck('code')->unique());
        $this->assertCount(30, $sections->pluck('name')->unique());
        $this->assertSame(1, Classes::query()->count());
    }

    public function test_thirty_class_section_rows_never_collide_and_follow_their_section(): void
    {
        $class = Classes::factory()->create();
        $shift = \App\Models\Shift::factory()->create();

        foreach (range(1, 30) as $i) {
            $section = Section::factory()->create(['class_id' => $class->id, 'shift_id' => $shift->id]);
            $row = ClassSection::factory()->create(['section_id' => $section->id]);

            $this->assertSame($class->id, $row->class_id);
        }

        $this->assertSame(30, ClassSection::query()->count());
        $this->assertSame(30, Section::query()->count());
        $this->assertSame(1, Classes::query()->count());
    }

    public function test_a_class_section_without_overrides_builds_one_section_and_its_class(): void
    {
        $row = ClassSection::factory()->create();

        $this->assertSame($row->section->class_id, $row->class_id);
        $this->assertSame(1, Section::query()->count());
    }
}
