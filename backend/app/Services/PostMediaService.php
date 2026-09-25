<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores images uploaded from the post body editor. No repository: it writes to disk,
 * not to a database table.
 */
class PostMediaService
{
    public function storeImage(UploadedFile $file): string
    {
        $directory = sprintf('posts/%s/%s', now()->format('Y'), now()->format('m'));
        $name = sprintf('%s.%s', (string) Str::uuid(), $file->extension());

        return $file->storeAs($directory, $name, 'public');
    }
}
