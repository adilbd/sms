<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuItem\IndexMenuItemRequest;
use App\Http\Requests\MenuItem\ReorderMenuItemsRequest;
use App\Http\Requests\MenuItem\StoreMenuItemRequest;
use App\Http\Requests\MenuItem\UpdateMenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use App\Services\MenuService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class MenuItemController extends Controller implements HasMiddleware
{
    public function __construct(private MenuService $menu) {}

    public static function middleware(): array
    {
        // Same as pages and posts: role:admin for every action, including reorder.
        return [new Middleware('role:admin')];
    }

    public function index(IndexMenuItemRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return MenuItemResource::collection(
            $this->menu->list($request->safe()->only(['location', 'is_active', 'parent_id']), $perPage)
        );
    }

    public function store(StoreMenuItemRequest $request)
    {
        $menuItem = $this->menu->create($request->validated());

        return (new MenuItemResource($menuItem))
            ->additional(['message' => 'Menu item created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(MenuItem $menuItem)
    {
        return new MenuItemResource($this->menu->find($menuItem));
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $menuItem)
    {
        $menuItem = $this->menu->update($menuItem, $request->validated());

        return (new MenuItemResource($menuItem))->additional(['message' => 'Menu item updated successfully']);
    }

    public function destroy(MenuItem $menuItem)
    {
        $this->menu->delete($menuItem);

        return response()->noContent();
    }

    public function reorder(ReorderMenuItemsRequest $request)
    {
        $this->menu->reorder($request->validated()['items']);

        return response()->json(['message' => 'Menu reordered successfully']);
    }
}
