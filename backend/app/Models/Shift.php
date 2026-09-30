<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school shift (Morning, Day, ...) that every Staff member belongs to at least one
 * of. See docs/architecture-guidelines.md; this module follows the Subjects pattern.
 */
class Shift extends Model
{
    /** @use HasFactory<\Database\Factories\ShiftFactory> */
    use HasFactory;

    protected $table = 'shifts';

    protected $fillable = [
        'name_en',
        'name_bn',
        'slug',
        'start_time',
        'end_time',
        'sort_order',
        'is_active',
    ];

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'shift_staff');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
