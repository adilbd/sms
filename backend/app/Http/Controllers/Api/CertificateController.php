<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Certificate\CancelCertificateRequest;
use App\Http\Requests\Certificate\IndexCertificateRequest;
use App\Http\Requests\Certificate\StoreCertificateRequest;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * The certificate register. Reading needs `view-students` (a teacher is limited to their
 * own sections in CertificateService), issuing `edit-students` (admin and office) and
 * cancelling `delete-students` (admin only).
 */
class CertificateController extends Controller implements HasMiddleware
{
    public function __construct(private CertificateService $certificates) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('students', [
            'store' => 'edit-students',
            'cancel' => 'delete-students',
        ]);
    }

    public function index(IndexCertificateRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return CertificateResource::collection($this->certificates->list(
            $request->safe()->only(['type', 'student_id', 'academic_year_id', 'from', 'to', 'status', 'search']),
            $perPage,
            $request->user(),
        ));
    }

    public function store(StoreCertificateRequest $request)
    {
        $certificate = $this->certificates->issue($request->validated(), $request->user());

        return (new CertificateResource($certificate))
            ->withData()
            ->additional(['message' => 'Certificate issued successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Certificate $certificate)
    {
        return (new CertificateResource($this->certificates->find($certificate, $request->user())))->withData();
    }

    public function cancel(CancelCertificateRequest $request, Certificate $certificate)
    {
        $cancelled = $this->certificates->cancel($certificate, $request->validated('reason'), $request->user());

        return (new CertificateResource($cancelled))
            ->withData()
            ->additional(['message' => 'Certificate cancelled successfully']);
    }
}
