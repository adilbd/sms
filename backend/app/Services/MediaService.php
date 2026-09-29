<?php

namespace App\Services;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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

        try {
            return $this->media->create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
            ]);
        } catch (Throwable $e) {
            // Don't leave an orphaned file on disk when the row never made it in.
            Storage::disk('public')->delete($path);

            throw $e;
        }
    }

    public function update(Media $media, array $data): Media
    {
        return $this->media->update($media, $data);
    }

    public function delete(Media $media): void
    {
        $disk = $media->disk;
        $path = $media->path;

        DB::transaction(function () use ($media, $disk, $path) {
            // Foreign keys don't protect soft-deleted rows, and this table isn't
            // soft-deletable anyway, so check the reference explicitly. Done inside the
            // transaction, immediately before the delete, so a gallery item linked to
            // this media row between the check and the delete is still caught below.
            abort_if(
                $this->media->isUsedInGalleryItems($media),
                409,
                'Image is used in a gallery and cannot be deleted.'
            );

            try {
                $this->media->delete($media);
            } catch (QueryException $e) {
                // A concurrent request may have linked a gallery item to this media row
                // after the check above but before this delete reached the database.
                // gallery_items.media_id is restrictOnDelete(), so report that race the
                // same way the check does, instead of a 500.
                if (! $this->isIntegrityConstraintViolation($e)) {
                    throw $e;
                }

                abort(409, 'Image is used in a gallery and cannot be deleted.');
            }

            // Deferred with afterCommit() so a rolled-back transaction never deletes a
            // file whose row is still there (see InstituteSettingsService::update()).
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        });
    }

    private function isIntegrityConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
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
