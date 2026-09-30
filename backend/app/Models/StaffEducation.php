<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a Staff member's educational background. Managed entirely through the
 * staff store/update payload (see StaffService), like GalleryItem inside a Gallery.
 */
class StaffEducation extends Model
{
    /** @use HasFactory<\Database\Factories\StaffEducationFactory> */
    use HasFactory;

    // "education" is uncountable to Eloquent's inflector, which would otherwise guess
    // the singular "staff_education" instead of the real table name.
    protected $table = 'staff_educations';

    protected $fillable = [
        'staff_id',
        'degree',
        'institution',
        'board_university',
        'passing_year',
        'result',
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
