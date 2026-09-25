<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores images uploaded from the post body editor. No repository: it writes to disk,
 * not to a database table.
 */
class PostMediaService
{
    public function storeImage(UploadedFile $file): string
    {
        $path = sprintf(
            'posts/%s/%s/%s.%s',
            now()->format('Y'),
            now()->format('m'),
            (string) Str::uuid(),
            $file->extension() ?: $file->getClientOriginalExtension()
        );

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }
}
