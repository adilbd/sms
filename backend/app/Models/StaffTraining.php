<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a Staff member's training history. Managed entirely through the staff
 * store/update payload (see StaffService), like GalleryItem inside a Gallery.
 */
class StaffTraining extends Model
{
    /** @use HasFactory<\Database\Factories\StaffTrainingFactory> */
    use HasFactory;

    protected $table = 'staff_trainings';

    protected $fillable = [
        'staff_id',
        'title',
        'organizer',
        'duration',
        'year',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
