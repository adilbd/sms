<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's place in one academic year. `class_id` is always the section's class.
 * Written through App\Services\EnrolmentService, which enforces the rules.
 */
class StudentEnrolment extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PROMOTED = 'promoted';

    public const STATUS_RETAINED = 'retained';

    public const STATUS_LEFT = 'left';

    public const STATUS_GRADUATED = 'graduated';

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_PROMOTED, self::STATUS_RETAINED, self::STATUS_LEFT, self::STATUS_GRADUATED,
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'class_id',
        'section_id',
        'group',
        'optional_subject_id',
        'roll_number',
        'status',
    ];

    protected $casts = [
        'roll_number' => 'integer',
    ];

    /** Newest academic year first (by the calendar year, not by row id). */
    public function scopeNewestYearFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc(AcademicYear::query()->select('year')->whereColumn('academic_years.id', 'student_enrolments.academic_year_id'))
            ->orderByDesc('student_enrolments.id');
    }

    /**
     * The enrolments that count for an academic year's exams: active, in that year, and of
     * a student who has not been deleted.
     */
    public function scopeActiveIn(Builder $query, int $academicYearId): Builder
    {
        return $query
            ->where('student_enrolments.academic_year_id', $academicYearId)
            ->where('student_enrolments.status', self::STATUS_ACTIVE)
            ->whereHas('student');
    }

    /**
     * The enrolments whose student takes an exam subject, the one rule for "who takes
     * which subject" (the mark sheets and result processing both use it). A subject is
     * taken when it is compulsory and common to the class (no group) or for the student's
     * group, or when it is optional and is the student's chosen 4th subject (and, for a
     * group's row, the student is in that group).
     */
    public function scopeTakingSubject(Builder $query, ExamSubject $subject): Builder
    {
        return $query->where(function (Builder $q) use ($subject) {
            if ($subject->type === ClassSubject::TYPE_OPTIONAL) {
                $q->where('student_enrolments.optional_subject_id', $subject->subject_id);
            }

            if ($subject->group !== null) {
                $q->where('student_enrolments.group', $subject->group);
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function optionalSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'optional_subject_id');
    }

    /** Marks entered under this enrolment (see App\Models\ExamMark). */
    public function examMarks(): HasMany
    {
        return $this->hasMany(ExamMark::class, 'enrolment_id');
    }
}
