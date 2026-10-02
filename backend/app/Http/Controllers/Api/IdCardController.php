<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Certificate\IdCardRequest;
use App\Http\Resources\IdCardSheetResource;
use App\Services\IdCardService;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * ID card print data for a section or one student. `view-students`; a teacher is limited
 * to their own sections in IdCardService.
 */
class IdCardController extends Controller implements HasMiddleware
{
    public function __construct(private IdCardService $idCards) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('students');
    }

    public function index(IdCardRequest $request)
    {
        $sheet = filled($request->validated('section_id'))
            ? $this->idCards->forSection((int) $request->validated('section_id'), $request->user())
            : $this->idCards->forStudent((int) $request->validated('student_id'), $request->user());

        return new IdCardSheetResource($sheet);
    }
}
