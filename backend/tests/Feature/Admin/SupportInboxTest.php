<?php

namespace Tests\Feature\Admin;

use App\Models\Hotel;
use App\Models\Plan;
use App\Models\User;
use App\Services\HotelDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportInboxTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Hotel $other;

    private User $admin;

    private User $clerk;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::factory()->create();
        $this->hotel = Hotel::factory()->create(['plan_id' => $plan->id, 'status' => 'active']);
        $this->other = Hotel::factory()->create(['plan_id' => $plan->id, 'status' => 'active']);
        app(HotelDefaults::class)->apply($this->hotel);
        app(HotelDefaults::class)->apply($this->other);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->clerk = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->support = User::factory()->platformRole('support')->create();
    }

    private function ask(User $user, string $subject = 'Printer does not print'): int
    {
        return $this->actingAs($user)->postJson('/api/v1/support', ['subject' => $subject, 'body' => 'The receipt is blank.'])->assertCreated()->json('data.id');
    }

    public function test_a_hotel_asks_and_hotelms_answers(): void
    {
        $id = $this->ask($this->clerk);

        $this->actingAs($this->support)->getJson('/api/v1/admin/support?status=open')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.hotel.id', $this->hotel->id)->assertJsonPath('counts.open', 1);
        $this->postJson("/api/v1/admin/support/{$id}/messages", ['kind' => 'note', 'body' => 'Probably the 80mm setting.'])->assertOk();
        $this->postJson("/api/v1/admin/support/{$id}/messages", ['kind' => 'reply', 'body' => 'Choose 80mm in Settings → Printing.'])->assertOk()
            ->assertJsonPath('data.status', 'waiting');

        // The hotel sees the reply, never the internal note.
        $messages = $this->actingAs($this->clerk)->getJson("/api/v1/support/{$id}")->assertOk()->assertJsonPath('data.status', 'waiting')->json('data.messages');
        $this->assertSame(['message', 'reply'], array_column($messages, 'kind'));
        $this->assertNull($messages[1]['author']);

        // Their answer puts it back in the open list.
        $this->postJson("/api/v1/support/{$id}/messages", ['body' => 'It works now, thanks.'])->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_hotels_never_see_each_others_requests(): void
    {
        $id = $this->ask($this->clerk);
        $outsider = User::factory()->withRole($this->other, 'hotel_admin')->create();

        $this->actingAs($outsider)->getJson('/api/v1/support')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/support/{$id}")->assertNotFound();
        $this->postJson("/api/v1/support/{$id}/messages", ['body' => 'hi'])->assertNotFound();
    }

    public function test_staff_see_their_own_requests_and_the_hotel_admin_sees_all(): void
    {
        $this->ask($this->clerk, 'From the clerk');
        $this->ask($this->admin, 'From the admin');
        $other = User::factory()->withRole($this->hotel, 'receptionist')->create();

        $this->actingAs($other)->getJson('/api/v1/support')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->clerk)->getJson('/api/v1/support')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/support')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_answering_roles_open_the_inbox_and_a_phone_call_can_be_logged(): void
    {
        $finance = User::factory()->platformRole('finance')->create();
        $this->actingAs($finance)->getJson('/api/v1/admin/support')->assertForbidden();

        $id = $this->actingAs($this->support)->postJson('/api/v1/admin/support', [
            'hotel_id' => $this->hotel->id, 'channel' => 'phone', 'subject' => 'Called about prices', 'body' => 'Wants the Pro plan.',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/support/{$id}", ['status' => 'solved'])->assertOk()->assertJsonPath('data.status', 'solved')
            ->assertJsonPath('data.messages.1.kind', 'system');
        $this->actingAs($this->admin)->getJson('/api/v1/support')->assertJsonPath('data.0.channel', 'phone');
    }

    public function test_a_suspended_hotel_can_still_ask_for_help(): void
    {
        $this->hotel->update(['status' => 'suspended']);
        $this->ask($this->admin);
    }
}
