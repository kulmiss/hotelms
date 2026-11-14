<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\ActivityLogger;
use App\Services\Landing\LandingContent;
use App\Support\SafeImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Super Admin → Content → Website: headline, subline, sections on/off, questions, real reviews, pictures.
 * Contact links are the WhatsApp number and support email in Settings → General.
 */
class LandingContentController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->current(), 'defaults' => LandingContent::defaults(), 'sections' => LandingContent::SECTIONS]);
    }

    public function update(Request $request, ActivityLogger $log): JsonResponse
    {
        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:90'],
            'subline' => ['nullable', 'string', 'max:240'],
            'sections' => ['array'],
            'sections.*' => ['boolean'],
            'faq' => ['array', 'max:15'],
            'faq.*.q' => ['required', 'string', 'max:160'],
            'faq.*.a' => ['required', 'string', 'max:800'],
            'testimonials' => ['array', 'max:9'],
            'testimonials.*.quote' => ['required', 'string', 'max:400'],
            'testimonials.*.name' => ['required', 'string', 'max:80'],
            'testimonials.*.hotel' => ['nullable', 'string', 'max:120'],
        ]);
        $saved = $this->saved();
        $saved = array_merge($saved, [
            'headline' => $data['headline'] ?? null,
            'subline' => $data['subline'] ?? null,
            'sections' => array_intersect_key(array_map('boolval', $data['sections'] ?? []), array_flip(LandingContent::SECTIONS)),
            'faq' => array_values($data['faq'] ?? []),
            'testimonials' => array_values($data['testimonials'] ?? []),
        ]);
        PlatformSetting::write('landing', $saved);
        LandingContent::forget();
        $log->log('platform.landing_changed', 'Changed the website content');

        return response()->json(['data' => $this->current(), 'message' => 'Saved. The website shows it now.']);
    }

    /** Replace one picture (hero or gallery 0–3) with a real screen, or put the original back (no file). */
    public function picture(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slot' => ['required', Rule::in(['hero', 'gallery.0', 'gallery.1', 'gallery.2', 'gallery.3'])],
            'file' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'alt' => ['nullable', 'string', 'max:160'],
        ]);
        $saved = $this->saved();
        $key = $data['slot'] === 'hero' ? 'hero' : (int) substr($data['slot'], 8);
        $old = $key === 'hero' ? ($saved['pictures']['hero']['path'] ?? null) : ($saved['pictures']['gallery'][$key]['path'] ?? null);
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        $pic = null;
        if ($file = $request->file('file')) {
            [$bytes] = SafeImage::clean($file); // drawn again: no hidden data
            $size = @getimagesizefromstring($bytes) ?: [1280, 800];
            $path = 'landing/'.$file->hashName();
            Storage::disk('public')->put($path, $bytes);
            $pic = ['src' => '/storage/'.$path, 'path' => $path, 'w' => $size[0], 'h' => $size[1]] + (filled($data['alt'] ?? null) ? ['alt' => $data['alt']] : []);
        }
        if ($key === 'hero') {
            $saved['pictures']['hero'] = $pic;
        } else {
            $saved['pictures']['gallery'][$key] = $pic;
        }
        PlatformSetting::write('landing', $saved);
        LandingContent::forget();

        return response()->json(['data' => $this->current()]);
    }

    /** @return array<string, mixed> */
    private function saved(): array
    {
        $v = PlatformSetting::read('landing');

        return is_array($v) ? $v : [];
    }

    /** @return array<string, mixed> */
    private function current(): array
    {
        $c = LandingContent::merge(LandingContent::defaults(), $this->saved());

        return ['headline' => $c['headline'], 'subline' => $c['subline'], 'sections' => $c['sections'], 'faq' => $c['faq'], 'testimonials' => $c['testimonials'], 'pictures' => $c['pictures']];
    }
}
