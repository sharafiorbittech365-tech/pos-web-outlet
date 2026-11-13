<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The invoice as a real A4 PDF file (download and e-mail attachment) — no print dialog.
 * Dompdf draws it from a table-based page (docs/invoice_pdf.php). The Bengali taka sign and Bengali
 * text are set in Noto Sans Bengali (app/Libraries/fonts, OFL); everything else in DejaVu Sans.
 */
final class InvoicePdf
{
    /** @param array<string, mixed> $d InvoiceDocs::build() */
    public static function render(array $d): string
    {
        $d['pdfImg'] = [
            'logo' => ! empty($d['showLogo']) ? (self::dataUri(! empty($d['logoUrl']) ? (string) $d['logoUrl'] : null) ?? self::symbol($d['logo'] ?? null)) : null,
            'header' => self::stored('header', true), 'footer' => self::stored('footer', true), 'signature' => self::stored('signature'),
        ];
        $html = view('App\Modules\Invoices\Views\docs\invoice_pdf', ['d' => $d]);
        // Bengali runs (and the taka sign) use the Bengali font; DejaVu has no glyphs for them
        $html = (string) preg_replace('/([\x{0980}-\x{09FF}][\x{0980}-\x{09FF}\s,.\-:\/()0-9]*)(?![^<]*>)/u', '<span class="bn">$1</span>', $html);

        $dir = WRITEPATH . 'dompdf/';
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $o = new Options();
        $o->set('fontDir', $dir);
        $o->set('fontCache', $dir);
        $o->set('tempDir', $dir);
        $o->set('chroot', [WRITEPATH, FCPATH, APPPATH . 'Libraries/fonts']);
        $o->set('isRemoteEnabled', false);
        $o->set('isHtml5ParserEnabled', true);
        $o->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($o);
        self::fonts($pdf);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function fileName(array $inv): string
    {
        $no = (string) ($inv['doc_no'] ?? '') !== '' ? (string) $inv['doc_no'] : 'Draft-' . (int) $inv['id'];

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $no) . '.pdf';
    }

    private static function fonts(Dompdf $pdf): void
    {
        $fm = $pdf->getFontMetrics();
        $have = (array) ($fm->getFontFamilies()['notobn'] ?? []);
        foreach (['normal' => 'Regular', 'bold' => 'Bold'] as $weight => $file) {
            if (empty($have[$weight])) {
                $fm->registerFont(['family' => 'notobn', 'style' => 'normal', 'weight' => $weight], APPPATH . 'Libraries/fonts/NotoSansBengali-' . $file . '.ttf');
            }
        }
    }

    /** The Setup symbol (no website logo) as an SVG picture, so the PDF shows the same mark as the screen. */
    private static function symbol(?string $key): ?string
    {
        $set = require dirname((string) (new \ReflectionClass(\OmniPOS\Ui\Libraries\Column::class))->getFileName(), 2) . '/Config/icons.php';
        if ($key === null || ! isset($set[$key])) {
            return null;
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="54" height="54" fill="none" stroke="#1f2937" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><g transform="scale(2.25)">' . $set[$key] . '</g></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function stored(string $kind, bool $layout = false): ?string
    {
        $set = InvoiceSettings::forCurrent();
        $f = $layout && $set->headerMode() === 'logo' ? null : $set->imageFile($kind);

        return $f !== null ? 'data:' . $f['mime'] . ';base64,' . base64_encode((string) file_get_contents($f['path'])) : null;
    }

    /** A logo URL (web or this site) as a data URI, or null when it cannot be fetched quickly. */
    private static function dataUri(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $abs = str_starts_with($url, '/') ? rtrim(base_url(), '/') . $url : $url;
        $ctx = stream_context_create(['http' => ['timeout' => 4], 'ssl' => ['verify_peer' => false]]);
        $bin = @file_get_contents($abs, false, $ctx);
        if ($bin === false || $bin === '' || strlen($bin) > 3 * 1024 * 1024) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }
}
