<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of a section's weekly routine: a subject (and usually a teacher and a room) in
 * one period on one day of an academic year. Written only by App\Services\RoutineService,
 * which replaces a section's whole grid at once; `room_key` is the normalized room name used
 * for clash checks.
 */
class RoutineSlot extends Model
{
    use HasFactory;

    /** Saturday first, like the school week. Weekly holidays are removed by RoutineService. */
    public const DAYS = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    protected $fillable = [
        'academic_year_id',
        'section_id',
        'day',
        'period_id',
        'subject_id',
        'staff_id',
        'room',
        'room_key',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class)->withTrashed();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class)->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }
}
