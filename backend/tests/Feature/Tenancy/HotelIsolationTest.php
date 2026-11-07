<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A data leak between hotels is the worst possible bug. These tests guard it.
 */
class HotelIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotelA;

    private Hotel $hotelB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotelA = Hotel::factory()->create();
        $this->hotelB = Hotel::factory()->create();
        Branch::factory()->for($this->hotelA)->create(['name' => 'A Bole']);
        Branch::factory()->for($this->hotelA)->create(['name' => 'A Piassa']);
        Branch::factory()->for($this->hotelB)->create(['name' => 'B Kazanchis']);
    }

    public function test_staff_only_see_their_own_hotels_branches(): void
    {
        $user = User::factory()->withRole($this->hotelA, 'hotel_admin')->create();

        $this->actingAs($user)->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'B Kazanchis']);

        $this->getJson('/api/v1/me')
            ->assertJsonCount(2, 'branches')
            ->assertJsonMissing(['name' => 'B Kazanchis']);
    }

    public function test_queries_return_nothing_when_no_hotel_is_set(): void
    {
        $this->assertSame(0, Branch::count());
    }

    public function test_queries_are_scoped_to_the_current_hotel(): void
    {
        $tenant = app(TenantContext::class);

        $this->assertSame(2, $tenant->runAs($this->hotelA, fn () => Branch::count()));
        $this->assertSame(1, $tenant->runAs($this->hotelB, fn () => Branch::count()));
        $this->assertSame(3, $tenant->acrossAllHotels(fn () => Branch::count()));
    }

    public function test_finding_another_hotels_record_by_id_fails(): void
    {
        $other = Branch::withoutGlobalScope('hotel')->where('hotel_id', $this->hotelB->id)->first();

        $found = app(TenantContext::class)->runAs($this->hotelA, fn () => Branch::find($other->id));

        $this->assertNull($found);
    }

    public function test_another_hotels_record_says_not_found_in_plain_words(): void
    {
        $user = User::factory()->withRole($this->hotelA, 'hotel_admin')->create();
        $other = Branch::withoutGlobalScope('hotel')->where('hotel_id', $this->hotelB->id)->first();

        // Same answer as a record that never existed: nothing tells the other hotel's data exists, and no class names.
        foreach ([$other->id, 999999] as $id) {
            $res = $this->actingAs($user)->putJson("/api/v1/settings/branches/{$id}", ['name' => 'X'])->assertNotFound();
            $res->assertJson(['code' => 'not_found', 'message' => 'Not found. It may have been removed, or the link is wrong.']);
            $this->assertStringNotContainsString('App\\Models', $res->getContent());
        }
    }

    public function test_new_records_get_the_current_hotel_automatically(): void
    {
        $branch = app(TenantContext::class)->runAs($this->hotelB, fn () => Branch::create(['name' => 'B CMC']));

        $this->assertSame($this->hotelB->id, $branch->hotel_id);
    }

    public function test_a_wrong_branch_assignment_never_exposes_another_hotel(): void
    {
        $user = User::factory()->forHotel($this->hotelA)->create();
        $foreign = Branch::withoutGlobalScope('hotel')->where('hotel_id', $this->hotelB->id)->first();
        $user->branches()->attach($foreign);

        $names = $user->accessibleBranches()->pluck('name');

        $this->assertNotContains('B Kazanchis', $names);
    }

    public function test_super_admin_sees_no_hotel_data_by_default(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
