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
        'enrolled_on',
        'status',
    ];

    protected $casts = [
        'roll_number' => 'integer',
        // The day the enrolment began (Asia/Dhaka calendar date); null means the start of the year.
        'enrolled_on' => 'date:Y-m-d',
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
     * group's row, the student is in that group). The optional row of a choice pair is
     * also taken by a student who chose its partner as the 4th subject. A class's
     * curriculum row (ClassSubject) follows the same rule as an exam's snapshot of it.
     */
    public function scopeTakingSubject(Builder $query, ExamSubject|ClassSubject $subject): Builder
    {
        return $query->where(function (Builder $q) use ($subject) {
            foreach (self::subjectRequirements($subject) as $column => $value) {
                is_array($value)
                    ? $q->whereIn("student_enrolments.{$column}", $value)
                    : $q->where("student_enrolments.{$column}", $value);
            }
        });
    }

    /**
     * The same rule as scopeTakingSubject(), decided in memory from this enrolment's own
     * group and 4th subject, for callers that already hold the enrolment.
     */
    public function takes(ExamSubject|ClassSubject $subject): bool
    {
        foreach (self::subjectRequirements($subject) as $column => $value) {
            // Compared as strings: the driver may hand back the id as a string.
            $matches = is_array($value)
                ? in_array((string) $this->{$column}, array_map('strval', $value), true)
                : (string) $this->{$column} === (string) $value;

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the subject is this student's 4th (optional) subject, which is how it is
     * graded. A plain optional row is the 4th subject of whoever chose it; a member of a
     * choice pair (Biology or Higher Mathematics) is the 4th subject of the student who
     * chose it, and the pair's other member is then compulsory, whichever row is optional
     * in the curriculum.
     */
    public function hasAsFourth(ExamSubject $subject): bool
    {
        if ($subject->choice_group !== null) {
            return $this->optional_subject_id !== null
                && (string) $this->optional_subject_id === (string) $subject->subject_id;
        }

        return $subject->type === ClassSubject::TYPE_OPTIONAL;
    }

    /**
     * The enrolment columns that must equal a value for the student to take the subject:
     * `optional_subject_id` for an optional subject, `group` for a group's row. Empty for a
     * compulsory row common to the class. The one place the rule is written down.
     *
     * @return array<string, int|string|list<int>>
     */
    private static function subjectRequirements(ExamSubject|ClassSubject $subject): array
    {
        $requirements = [];

        if ($subject->type === ClassSubject::TYPE_OPTIONAL) {
            // The optional row of a choice pair is taken by a student whose 4th subject is
            // either member (the compulsory member is taken by everyone in the group).
            $requirements['optional_subject_id'] = $subject->choice_group !== null
                ? $subject->choiceSubjectIds()
                : $subject->subject_id;
        }

        if ($subject->group !== null) {
            $requirements['group'] = $subject->group;
        }

        return $requirements;
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

    /** Daily attendance rows of this enrolment (see App\Models\Attendance). */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'enrolment_id');
    }

    /** Marks entered under this enrolment (see App\Models\ExamMark). */
    public function examMarks(): HasMany
    {
        return $this->hasMany(ExamMark::class, 'enrolment_id');
    }
}
