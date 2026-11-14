<?php

namespace Tests\Feature\Web;

use App\Models\Hotel;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Landing\LandingContent;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The public website at "/" (owner, 14 Nov 2026): plans from the database, no cookies, strict CSP, Register only when open. */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LandingContent::forget();
    }

    public function test_it_renders_with_no_cookies_no_scripts_and_a_strict_policy(): void
    {
        $r = $this->get('/')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertEmpty($r->headers->getCookies(), 'visitors get no cookies');
        $csp = $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringNotContainsString('script-src', $csp);
        $html = $r->getContent();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<h1>Run your hotel from one simple screen</h1>', $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringContainsString('og:image', $html);
        $this->assertStringContainsString('src="/landing/dashboard.webp"', $html);
    }

    public function test_the_website_in_amharic_by_link_with_no_cookie(): void
    {
        $r = $this->get('/?lang=am')->assertOk();
        $this->assertEmpty($r->headers->getCookies());
        $html = $r->getContent();
        $this->assertStringContainsString('<html lang="am">', $html);
        $this->assertStringContainsString('<h1>ሆቴልዎን ከአንድ ቀላል ማያ ያስተዳድሩ</h1>', $html);
        $this->assertStringContainsString('href="/login?lang=am"', $html); // the app opens in Amharic too
        $this->assertStringContainsString('href="/privacy?lang=am"', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/?lang=am">', $html);
        $this->assertStringContainsString('እስከ 15 ክፍሎች', $html); // plan limits with numbers
        $this->assertStringContainsString("font-src 'self'", $r->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('<script', $html);

        // English stays the default, with no Amharic font download.
        $en = $this->get('/')->getContent();
        $this->assertStringContainsString('<html lang="en">', $en);
        $this->assertStringNotContainsString('noto-sans-ethiopic', $en);
        $this->assertStringContainsString('Up to 15 rooms', $en);

        // Legal drafts: English text with an Amharic note.
        $legal = $this->get('/privacy?lang=am')->assertOk()->getContent();
        $this->assertStringContainsString('ይህ ጽሑፍ ለአሁን በእንግሊዝኛ ብቻ ነው', $legal);
        $this->assertStringContainsString('<article class="prose" lang="en">', $legal);
    }

    public function test_the_legal_drafts_cover_guest_messages_feedback_and_calendar_links(): void
    {
        $privacy = $this->get('/privacy')->assertOk()->getContent();
        foreach (['Messages to guests', 'Guest feedback', 'Calendar links', 'no messages', 'own SMS account', 'never names, phone numbers or prices', 'stop working after 60 days'] as $text) {
            $this->assertStringContainsString($text, $privacy, $text);
        }
        $terms = $this->get('/terms')->assertOk()->getContent();
        foreach (['Messages to guests', 'never change a guest', 'nothing is cancelled automatically', 'mainly for apartments and guesthouses'] as $text) {
            $this->assertStringContainsString($text, $terms, $text);
        }
        $this->assertStringContainsString('Draft – to be checked by a lawyer', $terms);
    }

    public function test_every_website_string_has_an_amharic_translation(): void
    {
        $am = json_decode((string) file_get_contents(lang_path('am.json')), true);
        $files = array_merge(glob(resource_path('views/landing/*.blade.php')), [app_path('Http/Controllers/Web/BookingPageController.php'), app_path('Services/Landing/LandingContent.php'), app_path('Services/BookingPage.php')]);
        $missing = [];
        foreach ($files as $f) {
            preg_match_all("/(?:__|trans_choice)\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($f), $m);
            foreach ($m[1] as $key) {
                $key = str_replace("\\'", "'", $key);
                if (! isset($am[$key])) {
                    $missing[] = $key;
                }
            }
        }
        $this->assertSame([], $missing);
        foreach ($am as $en => $text) {
            preg_match_all('/:[a-z]+/', $en, $a);
            preg_match_all('/:[a-z]+/', $text, $b);
            sort($a[0]);
            sort($b[0]);
            $this->assertSame($a[0], $b[0], "placeholders of: {$en}");
        }
    }

    public function test_plan_cards_come_from_the_database_with_pro_highlighted(): void
    {
        $html = $this->get('/')->getContent();
        foreach (['Free', 'Starter', 'Pro', 'Business'] as $name) {
            $this->assertStringContainsString(">{$name}</h3>", $html);
        }
        $this->assertStringContainsString('ETB 1,500', $html);
        $this->assertStringContainsString('ETB 15,000', $html); // yearly = 10 months
        $this->assertStringContainsString('ETB 7,500', $html);
        $this->assertSame(1, substr_count($html, 'class="tag">Recommended'));
        $this->assertStringNotContainsString('(classic)', $html);

        // A Super Admin change shows at once.
        $owner = User::factory()->superAdmin()->create();
        $plan = Plan::where('code', 'starter')->first();
        $this->actingAs($owner)->putJson("/api/v1/admin/plans/{$plan->id}", [
            'name' => 'Starter', 'code' => 'starter', 'price_per_room' => '0', 'minimum_monthly' => '1800', 'yearly_free_months' => 2, 'currency' => 'ETB',
            'is_custom' => false, 'max_rooms' => 40, 'max_branches' => 1, 'max_staff' => 15, 'max_storage_mb' => 5120, 'modules' => ['housekeeping', 'cashier_close'],
            'is_active' => true, 'is_public' => true, 'is_recommended' => false,
        ])->assertOk();
        $this->assertStringContainsString('ETB 1,800', $this->get('/')->getContent());
    }

    public function test_register_buttons_only_when_sign_up_is_not_off(): void
    {
        PlatformSetting::write('whatsapp', '+251 911 000 000');
        $off = $this->get('/')->getContent();
        $this->assertStringNotContainsString('href="/register"', $off);
        $this->assertStringContainsString('href="https://wa.me/251911000000"', $off);
        $this->assertStringContainsString('Contact us on WhatsApp', $off);

        PlatformSetting::write('signup_mode', 'approval');
        LandingContent::forget();
        $open = $this->get('/')->getContent();
        $this->assertStringContainsString('href="/register"', $open);
        $this->assertStringContainsString('Register your hotel free', $open);
    }

    public function test_no_invented_reviews_and_sections_can_be_hidden(): void
    {
        $html = $this->get('/')->getContent();
        $this->assertStringNotContainsString('id="stories"', $html); // testimonials hidden until real ones are added

        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner)->putJson('/api/v1/admin/landing', [
            'headline' => 'Hotel software that just works', 'subline' => 'Simple.',
            'sections' => ['faq' => false, 'testimonials' => true],
            'faq' => [['q' => 'Q?', 'a' => 'A.']],
            'testimonials' => [['quote' => 'We use it every day.', 'name' => 'Almaz', 'hotel' => 'Lake Lodge']],
        ])->assertOk();
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('Hotel software that just works', $html);
        $this->assertStringNotContainsString('id="faq"', $html);
        $this->assertStringContainsString('We use it every day.', $html);

        // Hotel staff cannot change the website.
        $hotel = Hotel::factory()->create();
        $this->actingAs(User::factory()->withRole($hotel, 'hotel_admin')->create())->putJson('/api/v1/admin/landing', ['headline' => 'x'])->assertForbidden();
    }

    public function test_super_admin_replaces_a_picture_and_can_put_it_back(): void
    {
        Storage::fake('public');
        $owner = User::factory()->superAdmin()->create();
        $img = imagecreatetruecolor(64, 40);
        ob_start();
        imagepng($img);
        $path = tempnam(sys_get_temp_dir(), 'p').'.png';
        file_put_contents($path, (string) ob_get_clean());
        $src = $this->actingAs($owner)->post('/api/v1/admin/landing/picture', ['slot' => 'hero', 'file' => new UploadedFile($path, 'screen.png', 'image/png', null, true)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.pictures.hero.src');
        $this->assertStringStartsWith('/storage/landing/', $src);
        $this->assertStringContainsString('src="'.$src.'"', $this->get('/')->getContent());
        $this->actingAs($owner)->postJson('/api/v1/admin/landing/picture', ['slot' => 'hero'])->assertOk()->assertJsonPath('data.pictures.hero.src', '/landing/dashboard.webp');
    }

    public function test_a_signed_in_visitor_goes_to_the_app(): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        // The sign-in cookie exactly as a browser sends it back.
        $session = $this->app['session.store'];
        $session->save();
        $raw = Crypt::encrypt(CookieValuePrefix::create(config('session.cookie'), app('encrypter')->getKey()).$session->getId(), false);
        $this->withUnencryptedCookie(config('session.cookie'), $raw)->get('/')->assertRedirect('/dashboard');
        // A cookie without a signed-in session still sees the website.
        $this->withUnencryptedCookie(config('session.cookie'), 'nonsense')->get('/')->assertOk();
    }

    public function test_seo_files(): void
    {
        $base = rtrim((string) config('app.url'), '/');
        $this->get('/robots.txt')->assertOk()->assertSee("Sitemap: {$base}/sitemap.xml", false)->assertSee('Disallow: /api/');
        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString("<loc>{$base}/</loc>", $xml);
        $this->assertStringContainsString('/register</loc>', $xml);
    }

    public function test_terms_and_privacy_drafts(): void
    {
        $terms = $this->get('/terms')->assertOk();
        $this->assertEmpty($terms->headers->getCookies());
        $terms->assertSee('Draft – to be checked by a lawyer.')->assertSee('The Free plan costs nothing')->assertSee('kept for one year')->assertSee('security emails');
        $this->get('/privacy')->assertOk()->assertSee('Draft – to be checked by a lawyer.')
            ->assertSee('data controller')->assertSee('data processor')->assertSee('one year')->assertSee('sets <strong>no cookies</strong>', false);
        $home = $this->get('/')->getContent();
        $this->assertStringContainsString('href="/terms"', $home);
        $this->assertStringContainsString('href="/privacy"', $home);
    }
}
