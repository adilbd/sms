<?php

namespace App\Repositories\Eloquent;

use App\Models\Setting;
use App\Repositories\Contracts\SettingRepositoryInterface;

class SettingRepository extends EloquentRepository implements SettingRepositoryInterface
{
    protected string $model = Setting::class;

    public function valuesFor(array $keys): array
    {
        return $this->query()->whereIn('key', $keys)->pluck('value', 'key')->all();
    }

    public function upsertMany(array $pairs): void
    {
        if (! $pairs) {
            return;
        }

        $now = now();

        $rows = collect($pairs)
            ->map(fn ($value, $key) => [
                'key' => $key,
                'value' => $value === null ? null : (string) $value,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        $this->model::query()->upsert($rows, ['key'], ['value', 'updated_at']);
    }
}
