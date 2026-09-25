<?php

namespace App\Providers;

use App\Repositories\Contracts\PostRepositoryInterface;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use App\Repositories\Eloquent\PostRepository;
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
    ];
}
