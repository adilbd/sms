<?php

namespace Tests\Concerns;

use App\Models\Classes;
use App\Models\Period;
use App\Models\RoutineSlot;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use App\Models\User;

/**
 * Class routine test data on top of BuildsFees (use both): the morning shift has periods
 * 1 08:00-08:45, 2 08:45-09:30, 3 a 09:30-09:45 break and 4 09:45-10:30; a second "Day"
 * shift has period 1 08:30-09:15 (overlapping the morning's 1 and 2) and 2 10:30-11:15; the
 * Class 9 Section A is in the morning shift, so is Class 10 Section A ($section10). Bangla
 * is in both curricula.
 */
trait BuildsRoutines
{
    /** @var array<int, Period> morning periods keyed by number */
    protected array $morning = [];

    protected Shift $dayShift;

    /** @var array<int, Period> */
    protected array $dayPeriods = [];

    protected Section $dayShiftSection;

    protected function setUpRoutines(): void
    {
        foreach ([[1, '08:00:00', '08:45:00', false], [2, '08:45:00', '09:30:00', false], [3, '09:30:00', '09:45:00', true], [4, '09:45:00', '10:30:00', false]] as [$n, $from, $to, $break]) {
            $this->morning[$n] = Period::factory()->create([
                'shift_id' => $this->shift->id, 'number' => $n, 'start_time' => $from, 'end_time' => $to,
                'is_break' => $break, 'name_en' => $break ? 'Tiffin' : "Period {$n}", 'name_bn' => $break ? 'টিফিন' : "পিরিয়ড {$n}",
            ]);
        }

        $this->dayShift = Shift::factory()->create();
        foreach ([[1, '08:30:00', '09:15:00'], [2, '10:30:00', '11:15:00']] as [$n, $from, $to]) {
            $this->dayPeriods[$n] = Period::factory()->create(['shift_id' => $this->dayShift->id, 'number' => $n, 'start_time' => $from, 'end_time' => $to]);
        }

        $this->dayShiftSection = Section::factory()->create([
            'class_id' => Classes::factory()->create(['number' => 8, 'name' => 'Class 8'])->id,
            'shift_id' => $this->dayShift->id,
        ]);
        $this->curriculum($this->dayShiftSection->class, $this->bangla, null, 'compulsory');
    }

    /** A teacher (staff) in the section's shift, assigned to the subject in the section this year. */
    protected function teacherFor(Section $section, Subject $subject, array $staff = [], ?User $login = null): Staff
    {
        $member = Staff::factory()->create(['user_id' => $login?->id, ...$staff]);
        $member->shifts()->attach($section->shift_id);
        $this->assign($member, $section, $subject);

        return $member;
    }

    protected function assign(Staff $member, Section $section, Subject $subject): void
    {
        $member->shifts()->syncWithoutDetaching([$section->shift_id]);

        SubjectAssignment::factory()->create([
            'staff_id' => $member->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'class_id' => $section->class_id, 'academic_year_id' => $this->year->id,
        ]);
    }

    /**
     * One routine cell as the PUT payload sends it.
     *
     * @return array<string, mixed>
     */
    protected function cell(Period $period, string $day, Subject $subject, ?Staff $staff = null, ?string $room = null): array
    {
        return ['day' => $day, 'period_id' => $period->id, 'subject_id' => $subject->id, 'staff_id' => $staff?->id, 'room' => $room];
    }

    /** Saves a section's grid through the API as the admin. */
    protected function saveRoutine(Section $section, array $cells, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->as($as ?? $this->admin)->putJson("/api/routines/sections/{$section->id}", [
            'academic_year_id' => $this->year->id,
            'slots' => $cells,
        ]);
    }

    protected function slotCount(Section $section): int
    {
        return RoutineSlot::where('section_id', $section->id)->count();
    }
}
