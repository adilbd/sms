<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One subject of an exam for one class: a snapshot of the class's curriculum row (group,
 * type, paper_group and the part marks) taken when the schedule was generated, plus the
 * exam date and times (Asia/Dhaka wall clock, nullable). Marks are entered against it.
 */
class ExamSubject extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'class_id',
        'subject_id',
        'group',
        'type',
        'paper_group',
        'choice_group',
        'written_full',
        'written_pass',
        'mcq_full',
        'mcq_pass',
        'practical_full',
        'practical_pass',
        'exam_date',
        'start_time',
        'end_time',
        'sort_order',
    ];

    /** @var list<int>|null */
    private ?array $choiceSubjectIdsCache = null;

    protected $attributes = [
        'type' => ClassSubject::TYPE_COMPULSORY,
        'sort_order' => 0,
    ];

    protected $casts = [
        'written_full' => 'integer',
        'written_pass' => 'integer',
        'mcq_full' => 'integer',
        'mcq_pass' => 'integer',
        'practical_full' => 'integer',
        'practical_pass' => 'integer',
        'exam_date' => 'date',
        'sort_order' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(ExamMark::class);
    }

    /**
     * The subject ids of this subject's choice pair (itself and its partner in the same
     * exam, class and group), or [] when it is in none. Read once per instance.
     *
     * @return list<int>
     */
    public function choiceSubjectIds(): array
    {
        if ($this->choice_group === null) {
            return [];
        }

        return $this->choiceSubjectIdsCache ??= self::query()
            ->where('exam_id', $this->exam_id)
            ->where('class_id', $this->class_id)
            ->where('choice_group', $this->choice_group)
            ->where('group', $this->group)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The parts (written, mcq, practical) this subject is marked in. */
    public function parts(): array
    {
        return array_values(array_filter(
            ClassSubject::PARTS,
            fn (string $part) => $this->{"{$part}_full"} !== null,
        ));
    }
}
