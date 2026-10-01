<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's status on one school day, linked to their enrolment in that academic
 * year. Written a whole section at a time through App\Services\AttendanceService.
 *
 * `date` is an Asia/Dhaka calendar date kept as a plain `Y-m-d` string (no date cast), so
 * it compares equal to the same string on every database.
 */
class Attendance extends Model
{
    use HasFactory;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const STATUS_LEAVE = 'leave';

    public const STATUSES = [self::STATUS_PRESENT, self::STATUS_ABSENT, self::STATUS_LATE, self::STATUS_LEAVE];

    protected $fillable = [
        'student_id',
        'enrolment_id',
        'section_id',
        'academic_year_id',
        'date',
        'status',
        'remarks',
        'marked_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrolment::class, 'enrolment_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
