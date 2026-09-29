<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateInstituteSettingsRequest;
use App\Http\Resources\InstituteSettingsResource;
use App\Services\InstituteSettingsService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Arr;

class InstituteSettingsController extends Controller implements HasMiddleware
{
    public function __construct(private InstituteSettingsService $institute) {}

    public static function middleware(): array
    {
        // "settings" already carries the view-settings/edit-settings permissions
        // (see AcademicYearController), so show -> view-settings, update -> edit-settings.
        return static::resourcePermissions('settings');
    }

    public function show()
    {
        return new InstituteSettingsResource($this->institute->all());
    }

    public function update(UpdateInstituteSettingsRequest $request)
    {
        $validated = $request->validated();

        $settings = $this->institute->update(
            Arr::except($validated, ['logo', 'favicon', 'remove_logo', 'remove_favicon']),
            $request->file('logo'),
            $request->file('favicon'),
            $request->boolean('remove_logo'),
            $request->boolean('remove_favicon'),
        );

        return (new InstituteSettingsResource($settings))
            ->additional(['message' => 'Institute settings saved']);
    }
}
