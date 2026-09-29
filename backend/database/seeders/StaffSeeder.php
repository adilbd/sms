<?php

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\Staff;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds current and former staff from a one-off scrape of vhbub.edu.bd.shongket.com
 * (see docs/tasks/staff-module.md). The scrape and this seeder never touch the network:
 * the fixture (staff.json) and photos (staff-photos/{source_id}.jpg) were captured once
 * and are committed to the repo. Matched on employee_id = VHBUB-{source_id}, so
 * reruns update rows instead of duplicating them. Bypasses StaffService entirely
 * (writes through the model directly), so it isn't bound by the service's validation
 * rules (e.g. a leaving_date is not required for every former record if the source
 * didn't show one).
 */
class StaffSeeder extends Seeder
{
    public function run(?string $jsonPath = null, ?string $photosPath = null): void
    {
        $jsonPath ??= database_path('seeders/data/staff.json');
        $photosPath ??= database_path('seeders/data/staff-photos');

        if (! is_file($jsonPath)) {
            return;
        }

        $people = json_decode(file_get_contents($jsonPath), true) ?: [];
        $morning = Shift::where('slug', 'morning')->first();

        foreach ($people as $person) {
            $employeeId = 'VHBUB-'.$person['source_id'];

            $staff = Staff::withTrashed()->firstOrNew(['employee_id' => $employeeId]);

            $staff->fill([
                'name_en' => $person['name_en'] ?? null,
                'name_bn' => $person['name_bn'] ?? null,
                'category' => $person['category'],
                'position' => $person['position'],
                'designation' => $person['designation'] ?? null,
                'subject' => $person['subject'] ?? null,
                'joining_date' => $person['joining_date'] ?? null,
                'leaving_date' => $person['leaving_date'] ?? null,
                'status' => $person['status'],
                'is_published' => true,
            ]);

            // Only copy the photo once: a rerun must not orphan a freshly-generated
            // uuid file every time while leaving the previous one on disk.
            if (! $staff->exists || ! $staff->photo) {
                $staff->photo = $this->storePhoto($photosPath, $person['photo'] ?? null);
            }

            $staff->save();

            if ($morning) {
                $staff->shifts()->syncWithoutDetaching([$morning->id]);
            }

            $this->syncEducations($staff, $person['educations'] ?? []);
            $this->syncTrainings($staff, $person['trainings'] ?? []);
        }
    }

    private function storePhoto(string $photosPath, ?string $filename): ?string
    {
        if (! $filename) {
            return null;
        }

        $source = rtrim($photosPath, '/').'/'.$filename;
        if (! is_file($source)) {
            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg';
        $path = 'staff/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, file_get_contents($source));

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncEducations(Staff $staff, array $rows): void
    {
        $staff->educations()->delete();

        foreach ($rows as $index => $row) {
            if (empty($row['degree'])) {
                continue;
            }

            $staff->educations()->create([
                'degree' => $row['degree'],
                'institution' => $row['institution'] ?? null,
                'board_university' => $row['board_university'] ?? null,
                'passing_year' => $row['passing_year'] ?? null,
                'result' => $row['result'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncTrainings(Staff $staff, array $rows): void
    {
        $staff->trainings()->delete();

        foreach ($rows as $index => $row) {
            if (empty($row['title'])) {
                continue;
            }

            $staff->trainings()->create([
                'title' => $row['title'],
                'organizer' => $row['organizer'] ?? null,
                'duration' => $row['duration'] ?? null,
                'year' => $row['year'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }
}
