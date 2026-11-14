<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\FeatureSwitch;
use App\Models\Hotel;
use App\Models\PaymentProof;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\HotelModules;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Free / Starter / Pro / Business (owner, 14 Nov 2026): limits, plan features, storage and per-hotel overrides. */
class BalancedPlansTest extends TestCase
{
    use RefreshDatabase;

    private function hotelOn(string $code): Hotel
    {
        $hotel = Hotel::factory()->create(['status' => 'active', 'plan_id' => Plan::where('code', $code)->value('id')]);
        app(HotelDefaults::class)->apply($hotel);
        Branch::factory()->for($hotel)->create();

        return $hotel;
    }

    private function jpeg(): UploadedFile
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($img);
        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        file_put_contents($path, (string) ob_get_clean());

        return new UploadedFile($path, 'slip.jpg', 'image/jpeg', null, true);
    }

    public function test_the_four_plans_exist_with_the_approved_limits_and_prices(): void
    {
        $plans = Plan::whereIn('code', ['free', 'starter', 'professional', 'business'])->orderBy('sort')->get()->keyBy('code');
        $this->assertCount(4, $plans);
        $this->assertSame(['0.00', '1500.00', '3500.00', '7500.00'], $plans->pluck('minimum_monthly')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->values()->all());
        $this->assertSame([15, 40, 100, null], $plans->pluck('max_rooms')->values()->all());
        $this->assertSame([1, 1, 2, null], $plans->pluck('max_branches')->values()->all());
        $this->assertSame([5, 15, 40, null], $plans->pluck('max_staff')->values()->all());
        $this->assertSame([1024, 5120, 20480, 102400], $plans->pluck('max_storage_mb')->values()->all());
        $this->assertTrue($plans['professional']->is_recommended);
        $this->assertTrue($plans->every(fn ($p) => $p->is_public && $p->yearly_free_months === 2));
        // Old plans are kept for the hotels that use them, but no longer offered.
        $this->assertTrue(Plan::where('name', 'like', '%(classic)')->get()->every(fn ($p) => ! $p->is_public));
    }

    public function test_free_has_the_core_work_and_bigger_plans_add_more(): void
    {
        $free = $this->hotelOn('free');
        $starter = $this->hotelOn('starter');
        $pro = $this->hotelOn('professional');
        $s = app(Switches::class);
        $this->assertTrue($s->on($free, 'feature.hourly_stays')); // core work is never locked
        $this->assertFalse($s->on($free, 'feature.telegram'));
        $this->assertFalse($s->on($free, 'feature.full_reports'));
        $this->assertTrue($s->on($starter, 'feature.telegram'));
        $this->assertTrue($s->on($starter, 'feature.full_reports'));
        $this->assertFalse($s->on($starter, 'feature.accountant_role'));
        $this->assertFalse($s->on($starter, 'feature.branding'));
        $this->assertTrue($s->on($pro, 'feature.accountant_role'));
        $this->assertTrue($s->on($pro, 'feature.branding'));
        $m = app(HotelModules::class);
        $this->assertSame(['housekeeping'], $m->available($free));
        $this->assertContains('cashier_close', $m->available($starter));
        $this->assertContains('shifts', $m->available($pro));
    }

    public function test_full_reports_and_list_downloads_are_refused_on_free_with_an_upgrade_message(): void
    {
        PlatformSetting::write('whatsapp', '+251 911 000 000');
        $hotel = $this->hotelOn('free');
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $this->actingAs($admin)->getJson('/api/v1/reports/forecast')->assertForbidden()
            ->assertJsonPath('code', 'feature_off')
            ->assertJsonFragment(['message' => 'This feature is not part of your plan. To upgrade your plan, contact us: WhatsApp +251 911 000 000 or info@kulmiss.com.']);
        $this->getJson('/api/v1/reports/payments?format=csv')->assertForbidden();
        $this->getJson('/api/v1/reports/payments')->assertOk(); // the report itself stays
    }

    public function test_the_accountant_role_needs_pro_but_people_who_have_it_keep_it(): void
    {
        $hotel = $this->hotelOn('starter');
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $role = app(TenantContext::class)->runAs($hotel, fn () => Role::where('key', 'accountant')->first());
        $this->assertNotNull($role);
        $body = ['name' => 'Yonas', 'email' => 'yonas@x.test', 'role_id' => $role->id, 'branch_ids' => [Branch::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->value('id')]];
        $this->actingAs($admin)->postJson('/api/v1/staff', $body)->assertJsonValidationErrors('role_id');

        // A Super Admin override for this one hotel lifts it.
        FeatureSwitch::create(['key' => 'feature.accountant_role', 'level' => 'hotel', 'target_id' => $hotel->id, 'value' => true]);
        app(Switches::class)->flush();
        $this->postJson('/api/v1/staff', $body)->assertCreated();
    }

    public function test_storage_full_refuses_new_files_and_removes_nothing(): void
    {
        Storage::fake('local');
        $hotel = $this->hotelOn('free');
        $staff = User::factory()->withRole($hotel, 'receptionist')->create();
        app(TenantContext::class)->runAs($hotel, fn () => PaymentProof::create(['path' => 'x/old.jpg', 'mime' => 'image/jpeg', 'size' => 1024 * 1024 * 1024, 'uploaded_by' => $staff->id]));

        $this->actingAs($staff)->postJson('/api/v1/payment-proofs', ['file' => $this->jpeg()])->assertJsonValidationErrors('file');
        $this->assertSame(1, PaymentProof::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count());

        // Per-hotel override of the limit.
        FeatureSwitch::create(['key' => 'limit.storage_mb', 'level' => 'hotel', 'target_id' => $hotel->id, 'value' => 2048]);
        app(Switches::class)->flush();
        $this->postJson('/api/v1/payment-proofs', ['file' => $this->jpeg()])->assertCreated();
    }

    public function test_room_limit_follows_the_plan_and_the_hotel_override(): void
    {
        $hotel = $this->hotelOn('free');
        $this->assertSame(15, $hotel->limit('rooms'));
        FeatureSwitch::create(['key' => 'limit.rooms', 'level' => 'hotel', 'target_id' => $hotel->id, 'value' => 20]);
        app(Switches::class)->flush();
        $this->assertSame(20, $hotel->fresh()->limit('rooms'));
        $this->assertSame(15, $this->hotelOn('free')->limit('rooms')); // other Free hotels unchanged
    }

    public function test_the_subscription_page_shows_usage_limits_and_how_to_upgrade(): void
    {
        $hotel = $this->hotelOn('free');
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $this->actingAs($admin)->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.limits.rooms', 15)
            ->assertJsonPath('data.limits.storage_mb', 1024)
            ->assertJsonPath('data.usage.storage_mb', 0)
            ->assertJsonPath('data.contact.email', 'info@kulmiss.com');
    }

    public function test_super_admin_edits_the_new_plan_fields(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $plan = Plan::where('code', 'starter')->first();
        $this->actingAs($owner)->putJson("/api/v1/admin/plans/{$plan->id}", [
            'name' => 'Starter', 'code' => 'starter', 'price_per_room' => '0', 'minimum_monthly' => '1800', 'yearly_free_months' => 2, 'currency' => 'ETB',
            'is_custom' => false, 'max_rooms' => 45, 'max_branches' => 1, 'max_staff' => 15, 'max_storage_mb' => 6000, 'modules' => ['housekeeping'],
            'is_active' => true, 'is_public' => true, 'is_recommended' => false,
        ])->assertOk()->assertJsonPath('data.max_storage_mb', 6000);
        $this->assertSame(45, $plan->fresh()->max_rooms);
        $this->actingAs($owner)->putJson('/api/v1/admin/settings', ['whatsapp' => '+251 911 22 33 44'])->assertOk();
        $this->assertSame('+251 911 22 33 44', PlatformSetting::read('whatsapp'));
        $this->putJson('/api/v1/admin/settings', ['whatsapp' => 'call me'])->assertJsonValidationErrors('whatsapp');
    }
}
