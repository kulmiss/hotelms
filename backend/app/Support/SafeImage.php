<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Uploaded pictures (payment proofs, guest ID photos, logos) are made safe before they are kept (SECURITY_REPORT L4):
 * - more than 40 megapixels is refused (read from the file's header only – no "image bomb" is ever opened);
 * - JPEG / PNG / WebP are drawn again with GD when the server has it, so hidden extra data and the photo's
 *   metadata (camera, GPS place) are gone; the picture is turned the right way first (EXIF orientation);
 * - pictures above 16 megapixels, HEIC (iPhone) and PDF files are kept as they are (their type was already checked).
 * Without GD (optional on shared hosting) only the size check runs.
 */
class SafeImage
{
    public const MAX_PIXELS = 40_000_000;

    private const REDRAW_MAX_PIXELS = 16_000_000;

    /** @return array{0: string, 1: string} the bytes to keep and their type */
    public static function clean(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [$bytes, $mime];
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size) {
            throw ValidationException::withMessages(['file' => 'This picture cannot be read. Take or choose it again.']);
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['file' => 'This picture is too big (more than 40 megapixels). Take it again with a lower camera setting.']);
        }
        if (! function_exists('imagecreatefromstring') || $size[0] * $size[1] > self::REDRAW_MAX_PIXELS) {
            return [$bytes, $mime];
        }
        // JPEG: without the EXIF extension the right way up is unknown, and a redrawn photo could lie on its side.
        $orientation = 1;
        if ($mime === 'image/jpeg') {
            if (! function_exists('exif_read_data')) {
                return [$bytes, $mime];
            }
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
            $orientation = (int) ($exif['Orientation'] ?? 1);
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            throw ValidationException::withMessages(['file' => 'This picture cannot be read. Take or choose it again.']);
        }
        $turned = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if ($turned !== $img && $turned !== false) {
            imagedestroy($img);
            $img = $turned;
        }
        ob_start();
        $ok = match ($mime) {
            'image/png' => (imagesavealpha($img, true) && imagepng($img, null, 6)),
            'image/webp' => imagewebp($img, null, 85),
            default => imagejpeg($img, null, 88),
        };
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return [$ok && $out !== '' ? $out : $bytes, $mime];
    }

    /** Headers for showing a kept picture: it can never run as a page. */
    public static function headers(string $mime): array
    {
        return str_starts_with($mime, 'image/')
            ? ['Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox", 'X-Content-Type-Options' => 'nosniff']
            : ['X-Content-Type-Options' => 'nosniff'];
    }
}
