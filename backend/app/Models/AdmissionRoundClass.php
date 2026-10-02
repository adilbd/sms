<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A class offered in an admission round, with its seats (null = no limit). Whether the
 * application asks for a group is derived from the class (Classes::hasGroups(), Class 9
 * and above), not stored. Managed through the round's payload only.
 */
class AdmissionRoundClass extends Model
{
    protected $fillable = [
        'round_id',
        'class_id',
        'seats',
    ];

    protected $casts = [
        'seats' => 'integer',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class, 'round_id');
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function requiresGroup(): bool
    {
        return $this->class->hasGroups();
    }
}
