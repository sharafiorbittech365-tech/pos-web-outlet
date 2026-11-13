<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use App\Modules\Orders\Services\PrintSettings;
use OmniPOS\Core\Services\ClinicSchedule;

/**
 * Everything the prescription pad shows around the clinician's text — the same for the editor (left
 * side, editable) and the print page: design (classic | modern | minimal), paper, margins, font,
 * accent, header (text block, image, or blank space for a pre-printed letterhead), doctor block,
 * chamber timings, footer, signature.
 */
final class PadView
{
    /**
     * @param array<string, mixed> $rx       PrescriptionService::find()
     * @param array<string, mixed> $business current outlet
     * @param ?string              $paper    a4 | a5 chosen for this view (else the prescription's, the pad's, the outlet's)
     * @return array<string, mixed>
     */
    public static function build(array $rx, array $business, ?string $paper = null): array
    {
        $pad = (new ClinicConfig())->padFor((int) $rx['doctor_id']);
        $paper = in_array($paper, ['a4', 'a5'], true) ? $paper
            : (in_array($rx['paper'] ?? null, ['a4', 'a5'], true) ? (string) $rx['paper']
            : ($pad['saved'] && in_array($pad['paper'], ['a4', 'a5'], true) ? (string) $pad['paper'] : PrintSettings::for($business, 'prescription')['size']));
        $style = isset(ClinicConfig::STYLES[$rx['style'] ?? '']) ? (string) $rx['style'] : (isset(ClinicConfig::STYLES[$pad['style'] ?? '']) ? (string) $pad['style'] : 'classic');
        $doctor = $rx['doctor'] ?: [];
        $ds = ($rx['doctor_snapshot'] ?: []) + $doctor;
        $sessions = service('clinicSchedule')->sessionsFor([(int) $rx['doctor_id']])[(int) $rx['doctor_id']] ?? [];

        return [
            'pad' => $pad, 'paper' => $paper, 'style' => $style,
            'accent' => (string) ($pad['accent'] ?: '#0F766E'),
            'font' => max(7.0, min(18.0, (float) $pad['font_size'])) * ($paper === 'a5' ? .92 : 1),
            'margins' => array_map('intval', [$pad['margin_top'], $pad['margin_right'], $pad['margin_bottom'], $pad['margin_left']]),
            'header' => $pad['header_mode'] === 'image' && $pad['header_file_id'] ? 'image' : ($pad['header_mode'] === 'none' ? 'letterhead' : 'text'),
            'headerImg' => ClinicFiles::url($pad['header_file_id'] !== null ? (int) $pad['header_file_id'] : null),
            'footerImg' => ClinicFiles::url($pad['footer_file_id'] !== null ? (int) $pad['footer_file_id'] : null),
            'signature' => $pad['show_signature'] && ! empty($doctor['signature_file_id']) ? ClinicFiles::url((int) $doctor['signature_file_id']) : null,
            'doctor' => [
                'name' => (string) ($ds['name'] ?? ''), 'degrees' => (string) ($ds['degrees'] ?? ''), 'specialty' => (string) ($ds['specialty'] ?? ''),
                'designation' => (string) ($ds['designation'] ?? ''), 'reg_no' => (string) ($ds['reg_no'] ?? ''),
            ],
            'clinic' => [
                'name' => (string) ($business['name'] ?? ''), 'chamber' => (string) (($ds['chamber'] ?? '') ?: ($business['address'] ?? '')),
                'phone' => (string) (($ds['phone'] ?? '') ?: ($business['phone'] ?? '')), 'timings' => self::timings($sessions), 'extra' => (string) ($pad['header_text'] ?? ''),
            ],
            'footerText' => (string) ($pad['footer_text'] ?? ''),
        ];
    }

    /**
     * Chamber timings from the weekly sessions, grouped by time: "Sat, Mon, Wed · 5:00 PM – 9:00 PM".
     *
     * @param list<array<string, mixed>> $sessions
     * @return list<string>
     */
    public static function timings(array $sessions): array
    {
        $by = [];
        foreach ($sessions as $s) {
            $k = date('g:i A', (int) strtotime((string) $s['starts'])) . ' – ' . date('g:i A', (int) strtotime((string) $s['ends']));
            $by[$k][] = substr(ClinicSchedule::WEEKDAYS[(int) $s['weekday']], 0, 3);
        }
        $out = [];
        foreach ($by as $time => $days) {
            $out[] = implode(', ', array_unique($days)) . ' · ' . $time;
        }

        return $out;
    }

    /** "Wt 62 kg · BP 120/80" — only what the clinician filled in. */
    public static function vitalsText(array $vitals): string
    {
        $out = [];
        foreach (PrescriptionService::VITALS as $k => $l) {
            if (trim((string) ($vitals[$k] ?? '')) !== '') {
                $out[] = $l . ' ' . $vitals[$k];
            }
        }

        return implode(' · ', $out);
    }
}
