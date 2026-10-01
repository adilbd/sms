<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class 1-12 (NCTB curriculum). The level and has_groups are derived from `number` and
 * not stored (see docs/architecture-guidelines.md; this module follows the Subjects
 * pattern). "Class" is a reserved word, hence the model name.
 */
class Classes extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'classes';

    public const MIN_NUMBER = 1;

    public const MAX_NUMBER = 12;

    public const LEVEL_PRIMARY = 'primary';

    public const LEVEL_JUNIOR_SECONDARY = 'junior_secondary';

    public const LEVEL_SECONDARY = 'secondary';

    public const LEVEL_HIGHER_SECONDARY = 'higher_secondary';

    public const LEVELS = [
        self::LEVEL_PRIMARY, self::LEVEL_JUNIOR_SECONDARY, self::LEVEL_SECONDARY, self::LEVEL_HIGHER_SECONDARY,
    ];

    /** Inclusive [min, max] class number range for each level. */
    public const LEVEL_RANGES = [
        self::LEVEL_PRIMARY => [1, 5],
        self::LEVEL_JUNIOR_SECONDARY => [6, 8],
        self::LEVEL_SECONDARY => [9, 10],
        self::LEVEL_HIGHER_SECONDARY => [11, 12],
    ];

    /** From Class 9, students are grouped into Science/Business Studies/Humanities. */
    public const GROUPS_FROM_NUMBER = 9;

    protected $fillable = [
        'number',
        'name',
        'name_bn',
        'code',
        'description',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'number' => 'integer',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'class_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class, 'class_id');
    }

    public function attendances(): HasManyThrough
    {
        // Through the class's sections (attendance is recorded per section), including
        // soft-deleted ones: their attendance rows still hold the foreign key.
        return $this->hasManyThrough(Attendance::class, Section::class, 'class_id')->withTrashedParents();
    }

    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class, 'class_id');
    }

    /** Fee dues of the class's enrolments (a due belongs to an enrolment, which has the class). */
    public function feeDues(): HasManyThrough
    {
        return $this->hasManyThrough(FeeDue::class, StudentEnrolment::class, 'class_id', 'enrolment_id');
    }

    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class, 'class_id');
    }

    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class, 'class_id');
    }

    /** The class's curriculum rows (see App\Models\ClassSubject). */
    public function curriculum(): HasMany
    {
        return $this->hasMany(ClassSubject::class, 'class_id');
    }

    /**
     * primary (1-5), junior_secondary (6-8), secondary (9-10, SSC) or
     * higher_secondary (11-12, HSC). Null when `number` isn't set yet (legacy rows).
     */
    public function level(): ?string
    {
        return $this->number ? self::levelForNumber((int) $this->number) : null;
    }

    public static function levelForNumber(int $number): string
    {
        foreach (self::LEVEL_RANGES as $level => [$min, $max]) {
            if ($number >= $min && $number <= $max) {
                return $level;
            }
        }

        // Unreachable given the 1-12 validation range, but keeps this total.
        return self::LEVEL_HIGHER_SECONDARY;
    }

    /** From Class 9, a student belongs to a group (see App\Support\AcademicGroup). */
    public function hasGroups(): bool
    {
        return $this->number !== null && (int) $this->number >= self::GROUPS_FROM_NUMBER;
    }
}
