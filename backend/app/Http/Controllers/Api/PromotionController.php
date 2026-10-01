<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\ApplyPromotionRequest;
use App\Http\Requests\Promotion\PreviewPromotionRequest;
use App\Http\Resources\PromotionPreviewResource;
use App\Http\Resources\PromotionResultResource;
use App\Services\PromotionService;
use Illuminate\Routing\Controllers\HasMiddleware;

class PromotionController extends Controller implements HasMiddleware
{
    public function __construct(private PromotionService $promotions) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('promotions', [
            'preview' => 'edit-students',
            'store' => 'edit-students',
        ]);
    }

    public function preview(PreviewPromotionRequest $request)
    {
        return new PromotionPreviewResource($this->promotions->preview($request->validated()));
    }

    /** An action rather than a created resource, so 200 with a summary. */
    public function store(ApplyPromotionRequest $request)
    {
        return (new PromotionResultResource($this->promotions->apply($request->validated())))
            ->additional(['message' => 'Promotion applied successfully']);
    }
}
