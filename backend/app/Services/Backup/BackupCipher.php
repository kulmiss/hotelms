<?php

namespace App\Services\Backup;

use Generator;
use RuntimeException;

/**
 * Encrypted backup files (SECURITY_REPORT H1): gzip inside, then libsodium secretstream
 * (XChaCha20-Poly1305) in chunks of 64 KB, so a big backup never sits in memory or unencrypted on disk.
 * The key is BACKUP_KEY (32 bytes, "base64:…"), on purpose not APP_KEY: a backup copied elsewhere
 * cannot be read without a key that lives in the owner's password manager.
 *
 * File layout: "HMSB1\n" + secretstream header (24 bytes) + chunks of [4-byte length][ciphertext].
 * The last chunk carries the FINAL tag, so a cut-off file is noticed.
 */
class BackupCipher
{
    public const MAGIC = "HMSB1\n";

    public const EXT = '.enc';

    private const CHUNK = 65536;

    /** @var resource */
    private $out;

    private string $state;

    private \DeflateContext $deflate;

    private string $pending = '';

    private function __construct() {}

    /** The configured key (32 raw bytes), or null when none is set. */
    public static function key(): ?string
    {
        $raw = (string) config('hotelms.backups.key');
        if ($raw === '') {
            return null;
        }
        $bytes = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : false;
        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_KEY is not valid. Make one with: php artisan hotelms:backup-key');
        }

        return $bytes;
    }

    public static function newKey(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public static function isEncrypted(string $file): bool
    {
        $h = @fopen($file, 'rb');
        if (! $h) {
            return false;
        }
        $start = (string) fread($h, strlen(self::MAGIC));
        fclose($h);

        return $start === self::MAGIC;
    }

    /** Opens a new encrypted file; write() plain text, close() at the end. */
    public static function create(string $path, string $key): self
    {
        $w = new self;
        $out = fopen($path, 'wb');
        if (! $out) {
            throw new RuntimeException("Cannot write {$path}");
        }
        $w->out = $out;
        [$w->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($out, self::MAGIC.$header);
        $w->deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        return $w;
    }

    public function write(string $text): void
    {
        $this->pending .= deflate_add($this->deflate, $text, ZLIB_NO_FLUSH);
        while (strlen($this->pending) >= self::CHUNK) {
            $this->push(substr($this->pending, 0, self::CHUNK), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->pending = substr($this->pending, self::CHUNK);
        }
    }

    public function close(): void
    {
        $this->pending .= deflate_add($this->deflate, '', ZLIB_FINISH);
        $this->push($this->pending, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->pending = '';
        fclose($this->out);
        sodium_memzero($this->state);
    }

    private function push(string $plain, int $tag): void
    {
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);
        fwrite($this->out, pack('N', strlen($cipher)).$cipher);
    }

    /** Encrypts a whole file (uploaded files mirrored with the backup). */
    public static function encryptFile(string $from, string $to, string $key): void
    {
        $in = fopen($from, 'rb');
        if (! $in) {
            throw new RuntimeException("Cannot read {$from}");
        }
        $w = self::create($to, $key);
        while (! feof($in)) {
            $w->write((string) fread($in, self::CHUNK));
        }
        fclose($in);
        $w->close();
    }

    /**
     * The plain text of an encrypted file, piece by piece (never all in memory).
     * Throws when the key is wrong, the file was changed, or it is cut off.
     *
     * @return Generator<int, string>
     */
    public static function read(string $path, string $key): Generator
    {
        $in = fopen($path, 'rb');
        if (! $in) {
            throw new RuntimeException("Cannot open {$path}");
        }
        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('This is not an encrypted HotelMS backup.');
            }
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            $final = false;
            while (! $final) {
                $len = fread($in, 4);
                if ($len === false || strlen($len) < 4) {
                    throw new RuntimeException('The backup file is cut off.');
                }
                $cipher = (string) fread($in, unpack('N', $len)[1]);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new RuntimeException('The backup cannot be opened: wrong BACKUP_KEY, or the file was changed.');
                }
                [$plain, $tag] = $result;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                $text = inflate_add($inflate, $plain, $final ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($text === false) {
                    throw new RuntimeException('The backup file is damaged.');
                }
                if ($text !== '') {
                    yield $text;
                }
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * The plain text line by line (for check and restore).
     *
     * @return Generator<int, string>
     */
    public static function lines(string $path, string $key): Generator
    {
        $rest = '';
        foreach (self::read($path, $key) as $piece) {
            $rest .= $piece;
            while (($at = strpos($rest, "\n")) !== false) {
                yield substr($rest, 0, $at + 1);
                $rest = substr($rest, $at + 1);
            }
        }
        if ($rest !== '') {
            yield $rest;
        }
    }
}
