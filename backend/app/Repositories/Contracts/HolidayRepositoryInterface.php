<?php

namespace App\Repositories\Contracts;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Collection;

interface HolidayRepositoryInterface extends RepositoryInterface
{
    /**
     * The holiday on a `Y-m-d` date, or null.
     */
    public function findByDate(string $date): ?Holiday;

    /**
     * The holidays from $from to $to (`Y-m-d`, inclusive), oldest first.
     */
    public function between(string $from, string $to): Collection;
}
