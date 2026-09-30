<?php

namespace App\Providers;

use App\Repositories\Contracts\GalleryRepositoryInterface;
use App\Repositories\Contracts\MediaRepositoryInterface;
use App\Repositories\Contracts\MenuItemRepositoryInterface;
use App\Repositories\Contracts\PageRepositoryInterface;
use App\Repositories\Contracts\PostRepositoryInterface;
use App\Repositories\Contracts\SettingRepositoryInterface;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use App\Repositories\Eloquent\GalleryRepository;
use App\Repositories\Eloquent\MediaRepository;
use App\Repositories\Eloquent\MenuItemRepository;
use App\Repositories\Eloquent\PageRepository;
use App\Repositories\Eloquent\PostRepository;
use App\Repositories\Eloquent\SettingRepository;
use App\Repositories\Eloquent\ShiftRepository;
use App\Repositories\Eloquent\StaffRepository;
use App\Repositories\Eloquent\SubjectRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds every repository interface to its implementation.
 * Add one line per new repository.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        SubjectRepositoryInterface::class => SubjectRepository::class,
        PostRepositoryInterface::class => PostRepository::class,
        PageRepositoryInterface::class => PageRepository::class,
        MenuItemRepositoryInterface::class => MenuItemRepository::class,
        SettingRepositoryInterface::class => SettingRepository::class,
        MediaRepositoryInterface::class => MediaRepository::class,
        GalleryRepositoryInterface::class => GalleryRepository::class,
        StaffRepositoryInterface::class => StaffRepository::class,
        ShiftRepositoryInterface::class => ShiftRepository::class,
    ];
}
