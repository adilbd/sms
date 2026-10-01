<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An exam of one academic year, held for one or more classes. Each class's subject
 * schedule is a snapshot of its curriculum (see ExamSubject). `status` moves draft →
 * marks_entry here; processed and published belong to the results task. Written through
 * App\Services\ExamService.
 */
class Exam extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_CLASS_TEST = 'class_test';

    public const TYPE_HALF_YEARLY = 'half_yearly';

    public const TYPE_TEST = 'test';

    public const TYPE_MODEL_TEST = 'model_test';

    public const TYPE_PRE_TEST = 'pre_test';

    public const TYPE_ANNUAL = 'annual';

    public const TYPES = [
        self::TYPE_CLASS_TEST, self::TYPE_HALF_YEARLY, self::TYPE_TEST,
        self::TYPE_MODEL_TEST, self::TYPE_PRE_TEST, self::TYPE_ANNUAL,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MARKS_ENTRY = 'marks_entry';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_MARKS_ENTRY, self::STATUS_PROCESSED, self::STATUS_PUBLISHED,
    ];

    /** Mirrors the column default in the exams migration. */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'academic_year_id',
        'name_en',
        'name_bn',
        'code',
        'type',
        'start_date',
        'end_date',
        'status',
        'published_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'published_at' => 'datetime',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class);
    }

    /** The classes the exam is held for: those with at least one scheduled subject. */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classes::class, 'exam_subjects', 'exam_id', 'class_id')
            ->distinct()
            ->orderBy('classes.number')
            ->orderBy('classes.id');
    }

    public function displayName(): string
    {
        return $this->name_en ?: (string) $this->name_bn;
    }
}
