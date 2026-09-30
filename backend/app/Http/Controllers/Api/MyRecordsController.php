<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Services\StudentService;
use Illuminate\Http\Request;

/**
 * Scoped own-record reads for the student and guardian roles. Routes are guarded by
 * `role:` middleware in routes/api.php; there is no broad view-students permission for
 * these roles (it would list every student).
 */
class MyRecordsController extends Controller
{
    public function __construct(private StudentService $students) {}

    public function student(Request $request)
    {
        return new StudentResource($this->students->findOwn($request->user()));
    }

    public function children(Request $request)
    {
        return StudentResource::collection($this->students->childrenOf($request->user()));
    }
}
