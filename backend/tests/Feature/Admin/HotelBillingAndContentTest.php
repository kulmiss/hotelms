<?php

namespace Tests\Feature\Admin;

use App\Mail\HotelInvoiceMail;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\HotelInvoice;
use App\Models\Plan;
use App\Models\Tax;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\HotelSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HotelBillingAndContentTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 09:00'));
        $plan = Plan::factory()->create(['price_per_room' => '100.00', 'minimum_monthly' => '2000.00']);
        $this->hotel = Hotel::factory()->create(['status' => 'active', 'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'subscription_ends_at' => '2026-11-14', 'email' => 'hotel@sheba.test']);
        Branch::factory()->for($this->hotel)->create();
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create(['email' => 'admin@sheba.test']);
        $this->owner = User::factory()->superAdmin()->create();
    }

    public function test_invoices_are_made_a_week_ahead_emailed_once_and_paid_by_the_payment(): void
    {
        Mail::fake();
        $this->artisan('subscriptions:invoices')->assertSuccessful();
        $this->artisan('subscriptions:invoices')->assertSuccessful();

        $invoice = HotelInvoice::sole();
        $this->assertSame('HMS-2026-00001', $invoice->number);
        $this->assertSame('2026-11-15', $invoice->period_start->toDateString());
        $this->assertSame('2026-12-14', $invoice->period_end->toDateString());
        $this->assertSame('2000.00', (string) $invoice->amount); // the plan minimum (no rooms yet)
        Mail::assertSent(HotelInvoiceMail::class, fn ($m) => $m->hasTo('admin@sheba.test') && $m->hasTo('hotel@sheba.test'));
        Mail::assertSentCount(1);

        $this->actingAs($this->owner)->postJson("/api/v1/admin/hotels/{$this->hotel->id}/payments", [
            'plan_id' => $this->hotel->plan_id, 'amount' => '2000.00', 'currency' => 'ETB', 'method' => 'bank_transfer', 'billing_cycle' => 'monthly', 'periods' => 1,
        ])->assertCreated();
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_hotels_see_their_own_invoices_only(): void
    {
        Mail::fake();
        $id = $this->actingAs($this->owner)->postJson("/api/v1/admin/hotels/{$this->hotel->id}/invoices")->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->getJson('/api/v1/settings/subscription')->assertOk()->assertJsonPath('data.invoices.0.id', $id);
        $this->getJson("/api/v1/subscription-invoices/{$id}")->assertOk()->assertJsonPath('data.number', 'HMS-2026-00001');

        $other = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        Branch::factory()->for($other)->create();
        app(HotelDefaults::class)->apply($other);
        $stranger = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($stranger)->getJson("/api/v1/subscription-invoices/{$id}")->assertNotFound();

        // Only billing roles make invoices.
        $support = User::factory()->platformRole('support')->create();
        $this->actingAs($support)->postJson("/api/v1/admin/hotels/{$this->hotel->id}/invoices")->assertForbidden();
    }

    public function test_whats_new_after_a_release_is_published(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/admin/releases', ['version' => '1.1.0', 'title' => 'Front desk mode', 'body' => 'Big tiles for reception.'])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->getJson('/api/v1/whats-new')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->owner)->postJson("/api/v1/admin/releases/{$id}/publish")->assertOk();
        $this->actingAs($this->admin)->getJson('/api/v1/whats-new')->assertOk()->assertJsonPath('data.0.title', 'Front desk mode');
        $this->actingAs($this->owner)->deleteJson("/api/v1/admin/releases/{$id}")->assertNotFound(); // published stays
    }

    public function test_defaults_for_new_hotels(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/admin/content', [
            'billing_name' => 'HotelMS PLC', 'welcome_message' => 'Call us any time.',
            'new_hotel_defaults' => ['deposit_value' => '50', 'vat_rate' => '15.00', 'invoice_footer' => 'Thank you!', 'receipt_paper' => 'receipt'],
        ])->assertOk()->assertJsonPath('data.new_hotel_defaults.deposit_value', '50');

        $new = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        app(HotelDefaults::class)->apply($new);
        app(TenantContext::class)->runAs($new, function () {
            $s = app(HotelSettings::class);
            $this->assertSame('50', (string) $s->get('deposit_value'));
            $this->assertSame('receipt', $s->get('receipt_paper'));
            $this->assertSame('Thank you!', $s->get('invoice_footer'));
            $this->assertSame('15.00', (string) Tax::where('name', 'VAT')->value('rate'));
        });
    }
}
