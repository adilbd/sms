<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a class's curriculum. A null `group` applies to the whole class (all groups
 * from Class 9); an `optional` row is a 4th-subject choice. Managed through
 * App\Services\CurriculumService, not a standalone CRUD endpoint.
 */
class ClassSubject extends Model
{
    use HasFactory;

    public const TYPE_COMPULSORY = 'compulsory';

    public const TYPE_OPTIONAL = 'optional';

    public const TYPES = [self::TYPE_COMPULSORY, self::TYPE_OPTIONAL];

    /** Mirrors the column defaults in the class_subjects migration. */
    protected $attributes = [
        'type' => self::TYPE_COMPULSORY,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'class_id',
        'subject_id',
        'group',
        'type',
        'sort_order',
        'written_full',
        'written_pass',
        'mcq_full',
        'mcq_pass',
        'practical_full',
        'practical_pass',
        'paper_group',
        'choice_group',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'written_full' => 'integer',
        'written_pass' => 'integer',
        'mcq_full' => 'integer',
        'mcq_pass' => 'integer',
        'practical_full' => 'integer',
        'practical_pass' => 'integer',
    ];

    /** The marked parts, in order. Each has a `{part}_full` and a `{part}_pass` column. */
    public const PARTS = ['written', 'mcq', 'practical'];

    /** Every marks-scheme column that can be sent for a row. */
    public const MARK_FIELDS = [
        'written_full', 'written_pass', 'mcq_full', 'mcq_pass', 'practical_full', 'practical_pass',
    ];

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
