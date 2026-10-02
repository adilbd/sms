<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * A window in which families may apply online for an academic year, with the classes on
 * offer and their seats (AdmissionRoundClass). `opens_at`/`closes_at` are Asia/Dhaka
 * calendar dates, both inclusive; a round is open when it is published and today (in
 * Dhaka) falls between them. Written through App\Services\AdmissionRoundService.
 */
class AdmissionRound extends Model
{
    use HasFactory, SoftDeletes;

    public const TIMEZONE = 'Asia/Dhaka';

    protected $fillable = [
        'academic_year_id',
        'name_en',
        'name_bn',
        'opens_at',
        'closes_at',
        'is_published',
        'instructions_bn',
        'instructions_en',
    ];

    protected $attributes = [
        'is_published' => false,
    ];

    protected $casts = [
        'opens_at' => 'date',
        'closes_at' => 'date',
        'is_published' => 'boolean',
    ];

    protected static function booted(): void
    {
        // The sitemap lists the apply pages of open rounds.
        static::saved(fn () => Cache::forget('sitemap.xml'));
        static::deleted(fn () => Cache::forget('sitemap.xml'));
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(AdmissionRoundClass::class, 'round_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'round_id');
    }

    /** Published rounds whose window includes today in Asia/Dhaka. */
    public function scopeOpen(Builder $query): Builder
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();

        return $query->where('is_published', true)
            ->whereDate('opens_at', '<=', $today)
            ->whereDate('closes_at', '>=', $today);
    }

    public function isOpen(): bool
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();

        return $this->is_published
            && $this->opens_at->toDateString() <= $today
            && $this->closes_at->toDateString() >= $today;
    }

    public function displayName(): string
    {
        return (string) ($this->name_bn ?: $this->name_en);
    }

    public function url(): string
    {
        return route('admissions.apply', $this);
    }
}
