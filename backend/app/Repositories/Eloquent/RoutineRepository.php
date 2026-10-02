<?php

namespace App\Repositories\Eloquent;

use App\Models\AcademicYear;
use App\Models\Period;
use App\Models\RoutineSlot;
use App\Models\Section;
use App\Repositories\Contracts\RoutineRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class RoutineRepository implements RoutineRepositoryInterface
{
    public function lockSection(int $sectionId): Section
    {
        $section = Section::query()->whereKey($sectionId)->lockForUpdate()->firstOrFail();

        return $section->load(['class', 'shift']);
    }

    public function lockAcademicYear(int $academicYearId): AcademicYear
    {
        return AcademicYear::query()->whereKey($academicYearId)->lockForUpdate()->firstOrFail();
    }

    public function forSectionAndYear(int $sectionId, int $academicYearId): Collection
    {
        return $this->ordered(
            RoutineSlot::query()
                ->where('section_id', $sectionId)
                ->where('academic_year_id', $academicYearId)
                ->with(['period', 'subject', 'staff'])
        )->get();
    }

    public function forStaffAndYear(int $staffId, int $academicYearId): Collection
    {
        return $this->ordered(
            RoutineSlot::query()
                ->where('staff_id', $staffId)
                ->where('academic_year_id', $academicYearId)
                ->with(['period', 'subject', 'section.class', 'section.shift'])
        )->get();
    }

    public function usingPeriod(Period $period): Collection
    {
        return RoutineSlot::query()->where('period_id', $period->id)->with('section')->get();
    }

    public function findTeacherClash(int $academicYearId, int $exceptSectionId, int $staffId, string $day, string $start, string $end, ?int $exceptPeriodId = null): ?RoutineSlot
    {
        return $this->clashQuery($academicYearId, $exceptSectionId, $day, $start, $end, $exceptPeriodId)
            ->where('staff_id', $staffId)
            ->first();
    }

    public function findRoomClash(int $academicYearId, int $exceptSectionId, string $roomKey, string $day, string $start, string $end, ?int $exceptPeriodId = null): ?RoutineSlot
    {
        return $this->clashQuery($academicYearId, $exceptSectionId, $day, $start, $end, $exceptPeriodId)
            ->where('room_key', $roomKey)
            ->first();
    }

    public function replaceForSection(Section $section, int $academicYearId, array $rows): void
    {
        RoutineSlot::query()
            ->where('section_id', $section->id)
            ->where('academic_year_id', $academicYearId)
            ->delete();

        foreach ($rows as $row) {
            RoutineSlot::create([...$row, 'section_id' => $section->id, 'academic_year_id' => $academicYearId]);
        }
    }

    /**
     * Slots of other sections on the day whose period's time range overlaps start-end
     * (each range starts before the other ends, so back-to-back periods don't clash).
     */
    private function clashQuery(int $academicYearId, int $exceptSectionId, string $day, string $start, string $end, ?int $exceptPeriodId): Builder
    {
        return RoutineSlot::query()
            ->where('academic_year_id', $academicYearId)
            ->where('section_id', '!=', $exceptSectionId)
            ->where('day', $day)
            ->when($exceptPeriodId !== null, fn (Builder $q) => $q->where('period_id', '!=', $exceptPeriodId))
            ->whereHas('period', fn (Builder $q) => $q->where('start_time', '<', $end)->where('end_time', '>', $start))
            ->with(['section.class', 'period'])
            ->orderBy('id');
    }

    /**
     * Day order first (the school week starts on Saturday), then the period's start time.
     */
    private function ordered(Builder $query): Builder
    {
        $case = 'case day';
        foreach (RoutineSlot::DAYS as $i => $day) {
            $case .= " when '{$day}' then {$i}";
        }
        $case .= ' else 99 end';

        return $query
            ->orderByRaw($case)
            ->orderBy(Period::query()->select('start_time')->whereColumn('periods.id', 'routine_slots.period_id'))
            ->orderBy('routine_slots.id');
    }
}
