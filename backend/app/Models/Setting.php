<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A generic key-value store. Only the "institute" group of keys (see
 * App\Support\InstituteSettings) is used today; other settings groups can reuse the
 * same table.
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];
}
