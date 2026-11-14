<?php

namespace Tests\Feature\Hotel;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Import;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\Imports\RoomImporter;
use App\Support\Spreadsheet;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::factory()->create(['max_rooms' => 10, 'max_branches' => 2]);
        $this->hotel = Hotel::factory()->create(['plan_id' => $plan->id]);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'code' => 'BL']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
    }

    private function row(array $v): array
    {
        return array_merge(array_fill_keys(RoomImporter::COLUMNS, ''), $v);
    }

    public function test_template_downloads_as_excel_and_csv(): void
    {
        $this->actingAs($this->admin);
        $xlsx = $this->get('/api/v1/settings/import/template')->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent()); // a zip = .xlsx
        $this->get('/api/v1/settings/import/template?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_excel_file_is_read_back(): void
    {
        $content = Spreadsheet::xlsx('Rooms', ['branch', 'room_type', 'room_number'], [['Bole', 'Standard', '101']]);
        $file = UploadedFile::fake()->createWithContent('rooms.xlsx', $content);

        $this->actingAs($this->admin)->post('/api/v1/settings/import/parse', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('headers', ['branch', 'room_type', 'room_number'])
            ->assertJsonPath('rows.0', ['Bole', 'Standard', '101'])
            ->assertJsonPath('file_name', 'rooms.xlsx');
    }

    public function test_preview_shows_errors_per_row(): void
    {
        app(TenantContext::class)->runAs($this->hotel, fn () => RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Suite', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '6000', 'currency' => 'ETB']));
        $rows = [
            $this->row(['branch' => 'bole', 'room_type' => 'Standard', 'room_number' => '101', 'nightly_price' => '2,500']),
            $this->row(['branch' => 'BL', 'room_type' => 'Standard', 'room_number' => '101']), // duplicate in file
            $this->row(['branch' => 'Piassa', 'room_type' => 'Standard', 'room_number' => '102']), // unknown branch
            $this->row(['branch' => 'Bole', 'room_type' => 'Deluxe', 'room_number' => '201']), // new type without price
            $this->row(['branch' => 'Bole', 'room_type' => 'Suite', 'room_number' => '301', 'max_guests' => 'two']),
        ];

        $res = $this->actingAs($this->admin)->postJson('/api/v1/settings/import/check', ['rows' => $rows])->assertOk();
        $res->assertJsonPath('data.errors.2.room_number', 'Room 101 is also on row 1.')
            ->assertJsonPath('data.errors.3.branch', 'Unknown branch "Piassa".')
            ->assertJsonPath('data.errors.5.max_guests', 'A whole number from 1 to 20.')
            ->assertJsonPath('data.summary.new_types', ['Standard (Bole)']);
        $this->assertArrayHasKey('nightly_price', $res->json('data.errors.4'));
        $this->assertArrayNotHasKey('1', $res->json('data.errors'));

        $this->postJson('/api/v1/settings/import', ['rows' => $rows])->assertJsonValidationErrors('rows');
        $this->assertSame(0, Room::count());
    }

    public function test_import_creates_types_and_rooms_and_can_be_undone(): void
    {
        $rows = [
            $this->row(['branch' => 'Bole', 'room_type' => 'Standard', 'room_number' => '101', 'floor' => '1', 'nightly_price' => '2500', 'guests_included' => '2', 'max_guests' => '3', 'amenities' => 'Wi-Fi, TV']),
            $this->row(['branch' => 'Bole', 'room_type' => 'standard', 'room_number' => '102', 'floor' => '1']),
            $this->row(['branch' => 'Bole', 'room_type' => 'Deluxe', 'room_number' => '', 'nightly_price' => '3800.5']), // a type with no rooms yet
        ];

        $this->actingAs($this->admin)->postJson('/api/v1/settings/import', ['rows' => $rows, 'file_name' => 'rooms.xlsx'])
            ->assertCreated()->assertJsonPath('data.rooms', 2)->assertJsonPath('data.room_types', 2)->assertJsonPath('data.can_undo', true);

        $standard = RoomType::where('name', 'Standard')->first();
        $this->assertSame(['Wi-Fi', 'TV'], $standard->amenities);
        $this->assertSame(3, $standard->max_capacity);
        $this->assertSame('3800.50', RoomType::where('name', 'Deluxe')->value('nightly_price'));
        $this->assertSame(2, Room::where('room_type_id', $standard->id)->count());

        $id = Import::first()->id;
        $this->getJson('/api/v1/settings/imports')->assertJsonPath('data.0.file_name', 'rooms.xlsx');
        $this->postJson("/api/v1/settings/imports/$id/undo")->assertOk()->assertJsonPath('data.can_undo', false);
        $this->assertSame(0, Room::count());
        $this->assertSame(0, RoomType::count());
        $this->postJson("/api/v1/settings/imports/$id/undo")->assertJsonValidationErrors('import');
    }

    public function test_undo_is_closed_after_24_hours(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/settings/import', ['rows' => [$this->row(['branch' => 'Bole', 'room_type' => 'Standard', 'room_number' => '101', 'nightly_price' => '1'])]])->assertCreated();
        $this->travel(25)->hours();
        $this->postJson('/api/v1/settings/imports/'.Import::first()->id.'/undo')->assertJsonValidationErrors('import');
        $this->assertSame(1, Room::count());
    }

    public function test_plan_room_limit_applies_to_imports(): void
    {
        $rows = array_map(fn ($n) => $this->row(['branch' => 'Bole', 'room_type' => 'Standard', 'room_number' => (string) (100 + $n), 'nightly_price' => '1']), range(1, 11));
        $this->actingAs($this->admin)->postJson('/api/v1/settings/import/check', ['rows' => $rows])
            ->assertJsonPath('data.general.0', 'Your plan allows 10 rooms (0 used); this file adds 11. Import fewer rooms. To upgrade your plan, contact us: info@kulmiss.com.');
    }

    public function test_imports_of_another_hotel_cannot_be_undone(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/settings/import', ['rows' => [$this->row(['branch' => 'Bole', 'room_type' => 'Standard', 'room_number' => '101', 'nightly_price' => '1'])]])->assertCreated();
        $id = Import::first()->id;

        $other = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        Branch::factory()->for($other)->create();
        $stranger = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($stranger)->getJson('/api/v1/settings/imports')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/settings/imports/$id/undo")->assertNotFound();
    }
}
