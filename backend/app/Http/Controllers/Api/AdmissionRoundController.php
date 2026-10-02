<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdmissionRound\IndexAdmissionRoundRequest;
use App\Http\Requests\AdmissionRound\StoreAdmissionRoundRequest;
use App\Http\Requests\AdmissionRound\UpdateAdmissionRoundRequest;
use App\Http\Resources\AdmissionRoundResource;
use App\Models\AdmissionRound;
use App\Services\AdmissionRoundService;
use Illuminate\Routing\Controllers\HasMiddleware;

class AdmissionRoundController extends Controller implements HasMiddleware
{
    public function __construct(private AdmissionRoundService $rounds) {}

    public static function middleware(): array
    {
        // Admissions are part of the Students area: the office role can run them.
        return static::resourcePermissions('students');
    }

    public function index(IndexAdmissionRoundRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return AdmissionRoundResource::collection(
            $this->rounds->list($request->safe()->only(['academic_year_id', 'is_published', 'search']), $perPage)
        );
    }

    public function store(StoreAdmissionRoundRequest $request)
    {
        return (new AdmissionRoundResource($this->rounds->create($request->validated())))
            ->additional(['message' => 'Admission round created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(AdmissionRound $admissionRound)
    {
        return new AdmissionRoundResource($this->rounds->find($admissionRound));
    }

    public function update(UpdateAdmissionRoundRequest $request, AdmissionRound $admissionRound)
    {
        return (new AdmissionRoundResource($this->rounds->update($admissionRound, $request->validated())))
            ->additional(['message' => 'Admission round updated successfully']);
    }

    public function destroy(AdmissionRound $admissionRound)
    {
        $this->rounds->delete($admissionRound);

        return response()->noContent();
    }
}
