<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdmissionApplication\ConvertAdmissionApplicationRequest;
use App\Http\Requests\AdmissionApplication\IndexAdmissionApplicationRequest;
use App\Http\Requests\AdmissionApplication\UpdateAdmissionApplicationStatusRequest;
use App\Http\Resources\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use App\Services\AdmissionApplicationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;

class AdmissionApplicationController extends Controller implements HasMiddleware
{
    public function __construct(private AdmissionApplicationService $applications) {}

    public static function middleware(): array
    {
        // Anyone who can view students sees the (masked) list; reviewing and the
        // documents need edit-students, and converting creates a student, so it needs
        // create-students. A teacher holds view-students only.
        return static::resourcePermissions('students', [
            'counts' => 'view-students',
            'status' => 'edit-students',
            'file' => 'edit-students',
            'convert' => 'create-students',
        ]);
    }

    public function index(IndexAdmissionApplicationRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $sensitive = $this->canSeeSensitive($request);

        // search_sensitive comes from the caller's permission, never from input.
        $filters = $request->safe()->only(['round_id', 'class_id', 'status', 'search']) + ['search_sensitive' => $sensitive];

        return AdmissionApplicationResource::collectionFor($this->applications->list($filters, $perPage), $sensitive);
    }

    public function counts(IndexAdmissionApplicationRequest $request)
    {
        $filters = $request->safe()->only(['round_id', 'class_id', 'search']) + ['search_sensitive' => $this->canSeeSensitive($request)];

        return response()->json(['data' => $this->applications->counts($filters)]);
    }

    public function show(Request $request, AdmissionApplication $admissionApplication)
    {
        return (new AdmissionApplicationResource($this->applications->find($admissionApplication)))
            ->withSensitive($this->canSeeSensitive($request));
    }

    public function status(UpdateAdmissionApplicationStatusRequest $request, AdmissionApplication $admissionApplication)
    {
        return (new AdmissionApplicationResource($this->applications->changeStatus($admissionApplication, $request->validated(), $request->user())))
            ->withSensitive($this->canSeeSensitive($request))
            ->additional(['message' => 'Application status updated successfully']);
    }

    public function convert(ConvertAdmissionApplicationRequest $request, AdmissionApplication $admissionApplication)
    {
        return (new AdmissionApplicationResource($this->applications->convert($admissionApplication, $request->validated())))
            ->withSensitive($this->canSeeSensitive($request))
            ->additional(['message' => 'Student created from the application']);
    }

    /**
     * Streams one stored document from the private disk. The only way to read a file:
     * there is no public URL for it.
     */
    public function file(AdmissionApplication $admissionApplication, string $kind)
    {
        $file = $this->applications->file($admissionApplication, $kind);

        return Storage::disk($file['disk'])->response($file['path'], $file['name'], [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function canSeeSensitive(Request $request): bool
    {
        return $request->user()->can('edit-students');
    }
}
