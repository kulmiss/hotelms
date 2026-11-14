<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Landing\LandingContent;
use App\Support\PublicLocale;
use App\Support\SignedInCookie;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The public website at "/" (owner, 14 Nov 2026): made on the server, plain HTML and CSS, no script,
 * no cookies, no tracking, no outside files. A signed-in visitor goes straight to the app.
 */
class LandingController extends Controller
{
    /** No scripts at all; styles only from the page itself; pictures and the Amharic font from this site. */
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function index(Request $request, LandingContent $content): BaseResponse
    {
        if (SignedInCookie::check($request)) {
            return redirect('/dashboard');
        }
        PublicLocale::apply($request);

        return $this->page(view('landing.index', ['c' => $content->page(), 'url' => $this->base()]));
    }

    /** Terms of use and Privacy policy (drafts, owner 14 Nov 2026: "to be checked by a lawyer"). */
    public function legal(Request $request, LandingContent $content): Response
    {
        $page = $request->is('privacy') ? 'privacy' : 'terms';
        PublicLocale::apply($request);

        return $this->page(view("landing.{$page}", ['c' => $content->page(), 'url' => $this->base(), 'updated' => self::LEGAL_UPDATED]));
    }

    public const LEGAL_UPDATED = '14 November 2026';

    public function robots(): Response
    {
        $base = $this->base();

        return response("User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /admin\nSitemap: {$base}/sitemap.xml\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(): Response
    {
        $base = $this->base();
        $urls = collect(['/', '/?lang=am', '/register', '/terms', '/privacy'])
            ->map(fn ($p) => '<url><loc>'.e($base.$p).'</loc></url>')->implode('');

        return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** A public page: HTML with its own strict policy, checked again on every visit (no cookie, nothing personal in it). */
    public static function page(mixed $view): Response
    {
        return response($view, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::CSP,
            'Cache-Control' => 'no-cache',
        ]);
    }

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
