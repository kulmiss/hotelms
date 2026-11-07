<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The developer documentation (Markdown files in the repository's docs/ folder), readable in the app
 * by the Owner and the tech team. Shipped with the app by deploy/package.sh. Only these files: no path
 * from the request reaches the disk (the name must match a file in the list).
 */
class DocsController extends Controller
{
    /** Folder with the .md files: docs/ next to the app on a server, ../docs in development. */
    public static function folder(): ?string
    {
        foreach ([config('hotelms.docs_path'), base_path('docs'), base_path('../docs')] as $dir) {
            if ($dir && is_dir($dir) && is_file($dir.'/README.md')) {
                return realpath($dir) ?: $dir;
            }
        }

        return null;
    }

    /** @return array<string, string> name => file path, README first */
    private function files(): array
    {
        $dir = self::folder();
        if (! $dir) {
            return [];
        }
        $files = [];
        foreach (glob($dir.'/*.md') ?: [] as $path) {
            $files[Str::lower(pathinfo($path, PATHINFO_FILENAME))] = $path;
        }
        uksort($files, fn ($a, $b) => [$a !== 'readme', $a] <=> [$b !== 'readme', $b]);

        return $files;
    }

    private function title(string $markdown, string $name): string
    {
        return preg_match('/^#\s+(.+)$/m', $markdown, $m) ? trim($m[1]) : Str::headline($name);
    }

    /** The list of documents; with ?q= only those that contain the words, with a short piece of text. */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $rows = [];
        foreach ($this->files() as $name => $path) {
            $text = (string) file_get_contents($path);
            $row = ['name' => $name, 'title' => $this->title($text, $name)];
            if ($q !== '') {
                $at = mb_stripos($text, $q);
                if ($at === false) {
                    continue;
                }
                $row['hits'] = mb_substr_count(mb_strtolower($text), mb_strtolower($q));
                $row['snippet'] = trim(preg_replace('/\s+/', ' ', mb_substr($text, max(0, $at - 80), 200)));
            }
            $rows[] = $row;
        }
        if ($q !== '') {
            usort($rows, fn ($a, $b) => $b['hits'] <=> $a['hits']);
        }

        return response()->json(['data' => $rows, 'available' => self::folder() !== null]);
    }

    /** One document as safe HTML (raw HTML in the file is removed); links to other documents open in the app. */
    public function show(string $name): JsonResponse
    {
        $path = $this->files()[$name] ?? abort(404, 'This document does not exist.');
        $markdown = (string) file_get_contents($path);
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        // "architecture.md#money" → the same page in the app.
        $html = preg_replace_callback('/href="([a-z0-9-]+)\.md(#[^"]*)?"/i', fn ($m) => 'href="/admin/docs/'.Str::lower($m[1]).($m[2] ?? '').'"', $html);

        return response()->json(['data' => [
            'name' => $name,
            'title' => $this->title($markdown, $name),
            'html' => $html,
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
        ]]);
    }
}
