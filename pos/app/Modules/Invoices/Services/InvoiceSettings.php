<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * The invoice page of an outlet (businesses.settings.invoicePage) and invoice numbering:
 *
 *   title, accent        heading ("INVOICE", "BILL", …) and accent colour (default: theme colour)
 *   header / footer      full-width images printed at the top / bottom of A4 & A5 invoices
 *   terms, notes         defaults for new invoices (each invoice can change its own)
 *   footer_text, bank    thank-you line and payment details
 *   show_brand           print the business name / address block (off when the header image has it)
 *   prefix, pad, reset   numbering: INV-00001, INV-2026-00001 (reset each calendar year) or
 *                        INV-2026-27-00001 (reset each Bangladesh fiscal year, July–June)
 *
 * Images are validated (JPEG / PNG / WebP, real image data, at most 2 MB and 4000 px a side) and
 * stored outside the web root in writable/uploads/invoice/<outlet id>/; they are served only to
 * signed-in staff of that outlet (InvoiceSettingsController::image).
 */
final class InvoiceSettings
{
    public const IMAGES = ['header' => 'Header image', 'footer' => 'Footer image', 'signature' => 'Authorized signature image'];
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_SIDE = 4000;
    /** printed invoice designs (all black-and-white printer friendly; the accent colour only tints) */
    public const TEMPLATES = ['classic' => 'Classic — ruled, formal', 'modern' => 'Modern — accent band, airy', 'compact' => 'Compact — dense, more lines a page',
        'ocean' => 'Ocean — blue gradient band', 'sunset' => 'Sunset — orange to pink', 'emerald' => 'Emerald — fresh green', 'royal' => 'Royal — indigo to purple', 'midnight' => 'Midnight — dark navy, gold touch'];
    /** colourful designs: [colour 1, colour 2 (gradient end), touch] — they carry their own colours (the accent colour is not used) */
    public const THEMES = ['ocean' => ['#0369A1', '#0EA5E9', '#BAE6FD'], 'sunset' => ['#EA580C', '#DB2777', '#FED7AA'], 'emerald' => ['#047857', '#10B981', '#A7F3D0'], 'royal' => ['#4338CA', '#9333EA', '#DDD6FE'], 'midnight' => ['#0F172A', '#334155', '#FBBF24']];

    public const RESETS = ['never' => 'Never (one running series)', 'year' => 'Every calendar year', 'fy' => 'Every fiscal year (July–June)'];

    public const DEFAULTS = [
        'title' => 'INVOICE', 'accent' => '', 'header' => null, 'footer' => null, 'signature' => null, 'sign_show' => true, 'sign_customer' => true, 'sign_name' => '', 'sign_title' => '', 'show_logo' => true,
        'terms' => '', 'notes' => '', 'footer_text' => 'Thank you for your business!', 'bank' => '', 'show_brand' => true, 'header_mode' => 'image',
        'prefix' => 'INV-', 'pad' => 5, 'reset' => 'never', 'template' => 'modern',
    ];

    /** inline CSS variables of a colourful design ('' for the plain ones) */
    public static function themeVars(string $template): string
    {
        $t = self::THEMES[$template] ?? null;

        return $t === null ? '' : ';--t1:' . $t[0] . ';--t2:' . $t[1] . ';--t3:' . $t[2];
    }

    public function __construct(private array $business)
    {
    }

    public static function forCurrent(): self
    {
        return new self(service('posSession')->business());
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $cur = (array) ($this->business['settings']['invoicePage'] ?? []);
        $out = array_merge(self::DEFAULTS, array_intersect_key($cur, self::DEFAULTS));
        if (! isset($this->business['settings']['invoicePage'])) {
            // first use: start from the outlet's existing invoice texts
            $out['terms'] = (string) ($this->business['settings']['terms'] ?? '');
            $out['bank'] = (string) ($this->business['settings']['invoice']['bank'] ?? '');
            $out['footer_text'] = (string) ($this->business['settings']['invoice']['footer'] ?? $out['footer_text']);
        }

        return $out;
    }

    /** Period key of the numbering series at a time ('' | 2026 | 2026-27). */
    public static function period(array $cfg, ?string $at = null): string
    {
        return match ($cfg['reset'] ?? 'never') {
            'year' => date('Y', strtotime($at ?? 'now')),
            'fy' => FiscalYear::label($at),
            default => '',
        };
    }

    public static function format(array $cfg, int $n, string $period): string
    {
        $pad = max(0, min(8, (int) ($cfg['pad'] ?? 5)));

        return (string) ($cfg['prefix'] ?? '') . ($period !== '' ? $period . '-' : '') . ($pad > 0 ? str_pad((string) $n, $pad, '0', STR_PAD_LEFT) : (string) $n);
    }

    /** The number the next issued invoice gets (preview). */
    public function nextNumber(): string
    {
        $cfg = $this->get();
        $p = self::period($cfg);

        return self::format($cfg, DocSequence::peek((int) $this->business['id'], 'invoice', $p), $p);
    }

    /** Save the text settings (title, terms, numbering …); audited. */
    public function save(array $in): array
    {
        $before = $this->get();
        $errors = [];
        $prefix = trim((string) ($in['prefix'] ?? ''));
        if (! preg_match('/^[A-Za-z0-9\-\/#]{0,12}$/', $prefix)) {
            $errors['prefix'] = 'Letters, digits, - / # only (12 at most).';
        }
        $pad = (int) ($in['pad'] ?? 5);
        if ($pad < 0 || $pad > 8) {
            $errors['pad'] = '0 to 8 digits.';
        }
        $reset = (string) ($in['reset'] ?? 'never');
        if (! isset(self::RESETS[$reset])) {
            $errors['reset'] = 'Choose when numbering starts again.';
        }
        $accent = trim((string) ($in['accent'] ?? ''));
        if ($accent !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $errors['accent'] = 'A colour like #4F46E5, or leave it empty.';
        }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 40) {
            $errors['title'] = 'A heading of up to 40 characters.';
        }
        $template = (string) ($in['template'] ?? $before['template']);
        if (! isset(self::TEMPLATES[$template])) {
            $errors['template'] = 'Choose a design.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the invoice settings.', $errors);
        }
        $txt = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $after = array_merge($before, [
            'title' => $title, 'accent' => strtoupper($accent),
            'terms' => $txt('terms', 2000), 'notes' => $txt('notes', 2000), 'footer_text' => $txt('footer_text', 200), 'bank' => $txt('bank', 500),
            'header_mode' => (string) ($in['header_mode'] ?? '') === 'logo' ? 'logo' : 'image', 'show_brand' => true, 'show_logo' => ! empty($in['show_logo']), 'sign_show' => ! empty($in['sign_show']), 'sign_customer' => ! empty($in['sign_customer']),
            'sign_name' => $txt('sign_name', 80), 'sign_title' => $txt('sign_title', 80), 'prefix' => $prefix, 'pad' => $pad, 'reset' => $reset, 'template' => $template,
        ]);
        $this->write($after);
        SettingChanges::record('invoice_page', $before, $after);

        return $after;
    }

    /** Validate and store a header / footer image; the old file is removed. */
    public function upload(string $kind, ?UploadedFile $file): string
    {
        if (! isset(self::IMAGES[$kind])) {
            throw new NotFoundException('Unknown image.');
        }
        $name = self::IMAGES[$kind];
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            throw new ValidationException('Choose an image file for the ' . strtolower($name) . '.', ['image' => 'No file uploaded']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationException($name . ': the file is larger than 2 MB.', ['image' => 'Too large']);
        }
        $path = $file->getTempName();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        if (! isset(self::TYPES[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw new ValidationException($name . ': use a JPEG, PNG or WebP image.', ['image' => 'Not a supported image']);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new ValidationException($name . ': at most ' . self::MAX_SIDE . ' pixels on a side.', ['image' => 'Too big']);
        }
        $dir = self::dir((int) $this->business['id']);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create the upload folder.');
        }
        $fileName = $kind . '_' . bin2hex(random_bytes(8)) . '.' . self::TYPES[$mime];
        $file->move($dir, $fileName);

        $before = $this->get();
        $after = array_merge($before, [$kind => $fileName]);
        $this->write($after);
        $this->removeFile($before[$kind] ?? null);
        SettingChanges::record('invoice_page', [$kind => $before[$kind] ?? null], [$kind => $fileName]);

        return $fileName;
    }

    public function removeImage(string $kind): void
    {
        if (! isset(self::IMAGES[$kind])) {
            throw new NotFoundException('Unknown image.');
        }
        $before = $this->get();
        if (empty($before[$kind])) {
            return;
        }
        $this->write(array_merge($before, [$kind => null]));
        $this->removeFile($before[$kind]);
        SettingChanges::record('invoice_page', [$kind => $before[$kind]], [$kind => null]);
    }

    /** @return array{path: string, mime: string}|null the stored image of this outlet */
    public function imageFile(string $kind): ?array
    {
        $name = $this->get()[$kind] ?? null;
        if (! isset(self::IMAGES[$kind]) || ! is_string($name) || ! preg_match('/^[a-z]+_[a-f0-9]{16}\.(jpg|png|webp)$/', $name)) {
            return null;
        }
        $path = self::dir((int) $this->business['id']) . $name;
        if (! is_file($path)) {
            return null;
        }

        return ['path' => $path, 'mime' => (string) array_search(pathinfo($name, PATHINFO_EXTENSION), self::TYPES, true)];
    }

    /** URL of an image for documents (null when none); the file name busts caches after a change. */
    /** Header style: "image" = the uploaded header / footer images (the logo template when none is uploaded), "logo" = always the logo + business name template. */
    public function headerMode(): string
    {
        return ($this->get()['header_mode'] ?? 'image') === 'logo' ? 'logo' : 'image';
    }

    /** Header / footer image URL as the invoice uses it (null in the logo template). */
    public function layoutImage(string $kind): ?string
    {
        return $this->headerMode() === 'logo' ? null : $this->imageUrl($kind);
    }

    public function imageUrl(string $kind): ?string
    {
        $name = $this->get()[$kind] ?? null;

        return is_string($name) && $name !== '' ? site_url('invoices/image/' . $kind) . '?v=' . substr(md5($name), 0, 8) : null;
    }

    public static function dir(int $businessId): string
    {
        return WRITEPATH . 'uploads/invoice/' . $businessId . '/';
    }

    private function removeFile(?string $name): void
    {
        if (is_string($name) && preg_match('/^[a-z]+_[a-f0-9]{16}\.(jpg|png|webp)$/', $name) && is_file(self::dir((int) $this->business['id']) . $name)) {
            @unlink(self::dir((int) $this->business['id']) . $name);
        }
    }

    /** Merge settings.invoicePage into the stored settings (other keys untouched). */
    private function write(array $cfg): void
    {
        $this->business['settings'] = SettingsStore::put((int) $this->business['tenant_id'], (int) $this->business['id'], 'invoicePage', array_intersect_key($cfg, self::DEFAULTS));
    }
}
