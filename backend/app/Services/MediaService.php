<?php

namespace App\Services;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The reusable media library: upload, alt-text edits and deletes for images picked
 * into galleries (App\Models\GalleryItem::media_id). See docs/architecture-guidelines.md.
 */
class MediaService
{
    public function __construct(private MediaRepositoryInterface $media) {}

    /**
     * @param  array{search?: string}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->media->paginate($filters, $perPage);
    }

    public function upload(UploadedFile $file): Media
    {
        $directory = sprintf('media/%s/%s', now()->format('Y'), now()->format('m'));
        $name = sprintf('%s.%s', (string) Str::uuid(), $file->extension());
        $path = $file->storeAs($directory, $name, 'public');

        [$width, $height] = $this->dimensions($file);

        return $this->media->create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);
    }

    public function update(Media $media, array $data): Media
    {
        return $this->media->update($media, $data);
    }

    public function delete(Media $media): void
    {
        // Foreign keys don't protect soft-deleted rows, and this table isn't
        // soft-deletable anyway, so check the reference explicitly.
        abort_if(
            $this->media->isUsedInGalleryItems($media),
            409,
            'Image is used in a gallery and cannot be deleted.'
        );

        $disk = $media->disk;
        $path = $media->path;

        DB::transaction(function () use ($media, $disk, $path) {
            $this->media->delete($media);

            // Deferred with afterCommit() so a rolled-back transaction never deletes a
            // file whose row is still there (see InstituteSettingsService::update()).
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        return $size ? [$size[0], $size[1]] : [null, null];
    }
}
