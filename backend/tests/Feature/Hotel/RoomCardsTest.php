<?php

namespace Tests\Feature\Hotel;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPage;
use App\Services\HotelDefaults;
use App\Services\HotelSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Restaurant menu link and printable room QR cards (owner, 14 Nov 2026). */
class RoomCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_cards_have_wifi_menu_and_whatsapp_and_the_booking_page_links_the_menu(): void
    {
        $hotel = Hotel::factory()->create(['name' => 'Sheger Hotel', 'status' => 'active', 'plan_id' => Plan::where('code', 'free')->value('id'), 'booking_slug' => 'sheger']);
        app(HotelDefaults::class)->apply($hotel);
        $branch = Branch::factory()->for($hotel)->create();
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        app(TenantContext::class)->runAs($hotel, function () use ($branch) {
            $type = RoomType::create(['branch_id' => $branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            foreach (['101', '102'] as $n) {
                Room::create(['branch_id' => $branch->id, 'room_type_id' => $type->id, 'number' => $n, 'status' => 'available']);
            }
            app(HotelSettings::class)->put([BookingPage::ENABLED => true]);
        });

        $this->actingAs($admin)->putJson('/api/v1/settings/guest-messages/info', ['wifi_name' => 'Sheger', 'wifi_password' => 'tea2026', 'whatsapp' => '+251 911 000 111', 'menu_url' => 'https://example.com/menu.pdf'])->assertOk();
        $this->putJson('/api/v1/settings/guest-messages/info', ['menu_url' => 'javascript:alert(1)'])->assertJsonValidationErrors('menu_url');
        $cards = $this->getJson('/api/v1/settings/room-cards')->assertOk()->json('data');
        $this->assertSame(['101', '102'], array_column($cards['rooms'], 'number'));
        $this->assertSame(['Sheger', 'tea2026', 'https://wa.me/251911000111', 'https://example.com/menu.pdf'], [$cards['wifi']['name'], $cards['wifi']['password'], $cards['whatsapp']['url'], $cards['menu_url']]);

        $this->get('/h/sheger')->assertOk()->assertSee('See our restaurant menu')->assertSee('href="https://example.com/menu.pdf"', false);

        $receptionist = User::factory()->withRole($hotel, 'receptionist')->create();
        $this->actingAs($receptionist)->getJson('/api/v1/settings/room-cards')->assertForbidden();
        $otherAdmin = User::factory()->withRole(Hotel::factory()->create(), 'hotel_admin')->create();
        $this->actingAs($otherAdmin)->getJson('/api/v1/settings/room-cards')->assertNotFound(); // no branch of theirs
    }
}
