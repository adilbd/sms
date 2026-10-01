<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DashboardController extends Controller implements HasMiddleware
{
    public function __construct(private DashboardService $dashboard) {}

    /**
     * Any signed-in staff role may open it; students and guardians are refused. What each
     * role sees is decided by the service.
     */
    public static function middleware(): array
    {
        return [new Middleware('role:admin|office|teacher')];
    }

    public function index(Request $request)
    {
        return new DashboardResource($this->dashboard->forUser($request->user()));
    }
}
