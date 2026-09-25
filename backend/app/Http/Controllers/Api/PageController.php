<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Page\IndexPageRequest;
use App\Http\Requests\Page\StorePageRequest;
use App\Http\Requests\Page\UpdatePageRequest;
use App\Http\Resources\PageResource;
use App\Models\Page;
use App\Services\PageService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PageController extends Controller implements HasMiddleware
{
    public function __construct(private PageService $pages) {}

    public static function middleware(): array
    {
        return [new Middleware('role:admin')];
    }

    public function index(IndexPageRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return PageResource::collection(
            $this->pages->list($request->safe()->only(['search', 'is_published']), $perPage)
        );
    }

    public function store(StorePageRequest $request)
    {
        $page = $this->pages->create($request->validated());

        return (new PageResource($page))
            ->withBody()
            ->additional(['message' => 'Page created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Page $page)
    {
        return (new PageResource($page))->withBody();
    }

    public function update(UpdatePageRequest $request, Page $page)
    {
        $page = $this->pages->update($page, $request->validated());

        return (new PageResource($page))->withBody()->additional(['message' => 'Page updated successfully']);
    }

    public function destroy(Page $page)
    {
        $this->pages->delete($page);

        return response()->noContent();
    }
}
