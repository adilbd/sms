<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Homework\IndexHomeworkRequest;
use App\Http\Requests\Homework\StoreHomeworkRequest;
use App\Http\Requests\Homework\UpdateHomeworkRequest;
use App\Http\Resources\HomeworkResource;
use App\Models\Homework;
use App\Services\HomeworkService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;

/**
 * Homework for staff. The teacher role holds the `*-homework` permissions, so what a teacher
 * may touch (their own sections, and their own homework until its due date) is enforced in
 * HomeworkService.
 */
class HomeworkController extends Controller implements HasMiddleware
{
    public function __construct(private HomeworkService $homework) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('homework', ['attachment' => 'view-homework']);
    }

    public function index(IndexHomeworkRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return HomeworkResource::collection($this->homework->list(
            $request->safe()->only(['academic_year_id', 'section_id', 'subject_id', 'from', 'to', 'due']),
            $perPage,
            $request->user(),
        ));
    }

    public function store(StoreHomeworkRequest $request)
    {
        $homework = $this->homework->create(
            $request->safe()->except(['attachment']),
            $request->file('attachment'),
            $request->user(),
        );

        return (new HomeworkResource($homework))
            ->additional(['message' => 'Homework assigned successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Homework $homework)
    {
        return new HomeworkResource($this->homework->find($homework, $request->user()));
    }

    public function update(UpdateHomeworkRequest $request, Homework $homework)
    {
        $updated = $this->homework->update(
            $homework,
            $request->safe()->except(['attachment', 'remove_attachment']),
            $request->file('attachment'),
            $request->boolean('remove_attachment'),
            $request->user(),
        );

        return (new HomeworkResource($updated))->additional(['message' => 'Homework updated successfully']);
    }

    public function destroy(Request $request, Homework $homework)
    {
        $this->homework->delete($homework, $request->user());

        return response()->noContent();
    }

    /**
     * Streams the attachment from the private disk. The only way to read it: there is no
     * public URL. A PDF is a download, an image shows inline.
     */
    public function attachment(Request $request, Homework $homework)
    {
        $file = $this->homework->attachment($homework, $request->user());
        $isPdf = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) === 'pdf';

        return Storage::disk($file['disk'])->response($file['path'], $file['name'], [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $isPdf ? 'attachment' : 'inline');
    }
}
