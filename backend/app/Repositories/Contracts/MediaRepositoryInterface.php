<?php

namespace App\Repositories\Contracts;

use App\Models\Media;

interface MediaRepositoryInterface extends RepositoryInterface
{
    public function isUsedInGalleryItems(Media $media): bool;
}
