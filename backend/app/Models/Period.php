<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One period (or break) of a shift's bell schedule. `start_time`/`end_time` are Asia/Dhaka
 * wall-clock times stored as `H:i:s`; periods of one shift never overlap, but periods of
 * different shifts can. Managed through App\Services\PeriodService.
 */
class Period extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'number',
        'name_en',
        'name_bn',
        'start_time',
        'end_time',
        'is_break',
    ];

    protected $attributes = [
        'is_break' => false,
    ];

    protected $casts = [
        'number' => 'integer',
        'is_break' => 'boolean',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function routineSlots(): HasMany
    {
        return $this->hasMany(RoutineSlot::class);
    }
}
