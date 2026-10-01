<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A kind of fee the school charges (Tuition, Session, Exam fee, Admission). `kind` decides
 * how its dues are generated: monthly (one per month), one_time (once per enrolment and
 * year) or per_exam (once per exam). The amount is a FeeRate per class and year. Written
 * through App\Services\FeeHeadService.
 */
class FeeHead extends Model
{
    use HasFactory, SoftDeletes;

    public const KIND_MONTHLY = 'monthly';

    public const KIND_ONE_TIME = 'one_time';

    public const KIND_PER_EXAM = 'per_exam';

    public const KINDS = [self::KIND_MONTHLY, self::KIND_ONE_TIME, self::KIND_PER_EXAM];

    /** Mirrors the column default in the fee_heads migration. */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name_en',
        'name_bn',
        'code',
        'kind',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    public function dues(): HasMany
    {
        return $this->hasMany(FeeDue::class);
    }

    public function displayName(): string
    {
        return $this->name_en ?: (string) $this->name_bn;
    }
}
