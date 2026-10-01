<?php

namespace App\Repositories\Contracts;

use App\Models\FeeHead;
use Illuminate\Database\Eloquent\Collection;

interface FeeHeadRepositoryInterface extends RepositoryInterface
{
    public function hasRates(FeeHead $head): bool;

    public function hasDues(FeeHead $head): bool;

    public function hasWaivers(FeeHead $head): bool;

    /**
     * Every active head, for generating dues.
     */
    public function active(): Collection;
}
