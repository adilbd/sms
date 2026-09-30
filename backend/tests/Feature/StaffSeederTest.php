<?php

namespace Tests\Feature;

use App\Models\Staff;
use Database\Seeders\ShiftSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureJson;

    private string $fixturePhotos;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->fixtureJson = base_path('tests/Fixtures/staff/staff.json');
        $this->fixturePhotos = base_path('tests/Fixtures/staff/photos');
    }

    public function test_running_twice_creates_no_duplicates_and_stores_no_private_data(): void
    {
        $this->seed(ShiftSeeder::class);

        (new StaffSeeder)->run($this->fixtureJson, $this->fixturePhotos);
        (new StaffSeeder)->run($this->fixtureJson, $this->fixturePhotos);

        $this->assertSame(2, Staff::count());

        $staff = Staff::where('employee_id', 'VHBUB-14')->firstOrFail();
        $this->assertSame('Md. Forhad Ali', $staff->name_en);
        $this->assertTrue($staff->shifts()->where('slug', 'morning')->exists());
        $this->assertNotNull($staff->photo);
        Storage::disk('public')->assertExists($staff->photo);

        foreach (Staff::all() as $member) {
            $this->assertNull($member->mobile);
            $this->assertNull($member->email);
            $this->assertNull($member->date_of_birth);
            $this->assertNull($member->present_address);
            $this->assertNull($member->nid);
        }

        $this->assertSame(1, $staff->educations()->count());
    }
}
