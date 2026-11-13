<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * The clinic's own configuration (Clinic › Setup) — all of it entered by the clinic, nothing pre-filled:
 *
 *   pick lists       dose, frequency, duration, route, instruction, advice, complaint values offered while
 *                    writing a prescription (the clinician can always type something else)
 *   investigations   the clinic's lab / physical / imaging / other tests to tick on a prescription
 *   prescription pad per doctor, or clinic-wide (doctor 0): paper, header (text block or image), footer,
 *                    margins, font size, accent colour, signature
 */
final class ClinicConfig
{
    public const LISTS = [
        'complaint' => 'Chief complaints', 'dose' => 'Doses', 'frequency' => 'Frequencies', 'duration' => 'Durations',
        'route' => 'Routes / administration', 'instruction' => 'Instructions', 'advice' => 'Advice',
    ];

    /** Prescription pad designs (all print in black & white; the accent colour is optional). */
    public const STYLES = ['classic' => 'Classic', 'modern' => 'Modern', 'minimal' => 'Minimal'];

    public const TEST_CATS = ['lab' => 'Laboratory', 'physical' => 'Physical examination', 'imaging' => 'Imaging', 'other' => 'Other'];

    /** Pad used until the clinic saves its own. */
    public const PAD_DEFAULTS = [
        'id' => 0, 'doctor_id' => 0, 'paper' => '', 'style' => 'classic', 'header_mode' => 'text', 'doctor_block' => 'left', 'header_text' => '', 'footer_text' => '', 'header_file_id' => null,
        'footer_file_id' => null, 'margin_top' => 12, 'margin_right' => 12, 'margin_bottom' => 12, 'margin_left' => 12, 'font_size' => 11.0, 'accent' => null, 'show_signature' => 1,
    ];

    private function bid(): int
    {
        return service('posSession')->businessId();
    }

    /** @return array<string, list<array{id: int, value: string}>> list => values */
    public function lists(): array
    {
        $out = array_fill_keys(array_keys(self::LISTS), []);
        foreach (db_connect()->table('clinic_lists')->select('id, list, value')->where('business_id', $this->bid())->orderBy('list')->orderBy('sort_order')->orderBy('value')->get()->getResultArray() as $r) {
            $out[$r['list']][] = ['id' => (int) $r['id'], 'value' => (string) $r['value']];
        }

        return $out;
    }

    /** Add one or more values (one per line). Returns how many were new. */
    public function addListValues(string $list, string $values): int
    {
        if (! isset(self::LISTS[$list])) {
            throw new ValidationException('Unknown list.', ['list' => 'Unknown list.']);
        }
        $s = service('posSession');
        $n = 0;
        foreach (preg_split('/\r\n|\r|\n/', $values) ?: [] as $v) {
            $v = mb_substr(trim($v), 0, 255);
            if ($v === '') {
                continue;
            }
            $n += db_connect()->query('INSERT IGNORE INTO clinic_lists (tenant_id, business_id, list, value, sort_order, created_at) VALUES (?,?,?,?,?,?)',
                [$s->tenantId(), $this->bid(), $list, $v, 0, date('Y-m-d H:i:s')]) ? db_connect()->affectedRows() : 0;
        }
        if ($n === 0) {
            throw new ValidationException('Nothing new to add.', ['value' => 'Type a value (one per line) that is not in the list yet.']);
        }
        service('audit')->activity('Clinic list changed', self::LISTS[$list] . ' · ' . $n . ' added');

        return $n;
    }

    public function deleteListValue(int $id): void
    {
        db_connect()->table('clinic_lists')->where('id', $id)->where('business_id', $this->bid())->delete();
    }

    /** @return list<array<string, mixed>> */
    public function tests(bool $activeOnly = false): array
    {
        $b = db_connect()->table('clinic_tests')->select('id, category, name, is_active')->where('business_id', $this->bid());
        if ($activeOnly) {
            $b->where('is_active', 1);
        }

        return $b->orderBy('category')->orderBy('sort_order')->orderBy('name')->get()->getResultArray();
    }

    /** Add tests to the clinic's list (one per line). */
    public function addTests(string $category, string $names): int
    {
        if (! isset(self::TEST_CATS[$category])) {
            throw new ValidationException('Choose a category.', ['category' => 'Choose a category.']);
        }
        $s = service('posSession');
        $n = 0;
        foreach (preg_split('/\r\n|\r|\n/', $names) ?: [] as $v) {
            $v = mb_substr(trim($v), 0, 160);
            if ($v === '') {
                continue;
            }
            db_connect()->query('INSERT IGNORE INTO clinic_tests (tenant_id, business_id, category, name, is_active, sort_order, created_at) VALUES (?,?,?,?,1,0,?)',
                [$s->tenantId(), $this->bid(), $category, $v, date('Y-m-d H:i:s')]);
            $n += db_connect()->affectedRows();
        }
        if ($n === 0) {
            throw new ValidationException('Nothing new to add.', ['names' => 'Type test names (one per line) that are not in the list yet.']);
        }
        service('audit')->activity('Clinic tests changed', self::TEST_CATS[$category] . ' · ' . $n . ' added');

        return $n;
    }

    public function toggleTest(int $id): void
    {
        $t = db_connect()->table('clinic_tests')->select('is_active')->where('id', $id)->where('business_id', $this->bid())->get()->getRowArray() ?? throw new NotFoundException('Test not found.');
        db_connect()->table('clinic_tests')->where('id', $id)->where('business_id', $this->bid())->update(['is_active' => (int) $t['is_active'] ? 0 : 1]);
    }

    /** The saved pad of a scope (doctor id, 0 = clinic-wide), else the defaults. */
    public function pad(int $doctorId): array
    {
        $r = db_connect()->table('clinic_templates')->where('business_id', $this->bid())->where('doctor_id', $doctorId)->get()->getRowArray();

        return $r !== null ? $r + ['saved' => true] : ['doctor_id' => $doctorId, 'saved' => false] + self::PAD_DEFAULTS;
    }

    /** The pad a doctor prints on: their own, else the clinic's. */
    public function padFor(int $doctorId): array
    {
        $own = $this->pad($doctorId);

        return $own['saved'] ? $own : $this->pad(0);
    }

    /**
     * @param array<string, mixed>         $in
     * @param array<string, ?UploadedFile> $files header, footer
     */
    public function savePad(int $doctorId, array $in, array $files = []): void
    {
        if ($doctorId > 0 && db_connect()->table('clinic_doctors')->where('id', $doctorId)->where('business_id', $this->bid())->where('deleted_at', null)->countAllResults() === 0) {
            throw new NotFoundException('Doctor not found.');
        }
        $errors = [];
        $m = [];
        foreach (['margin_top', 'margin_right', 'margin_bottom', 'margin_left'] as $k) {
            $v = (int) ($in[$k] ?? 12);
            if ($v < 0 || $v > 60) {
                $errors[$k] = 'Margins: 0 to 60 mm.';
            }
            $m[$k] = max(0, min(60, $v));
        }
        $font = (float) ($in['font_size'] ?? 11);
        if ($font < 7 || $font > 18) {
            $errors['font_size'] = 'Font size: 7 to 18 pt.';
        }
        $accent = trim((string) ($in['accent'] ?? ''));
        if ($accent !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $errors['accent'] = 'Use a colour like #0F766E.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the prescription pad.', $errors);
        }
        $old = $this->pad($doctorId);
        $row = $m + [
            'paper' => in_array($in['paper'] ?? '', ['a4', 'a5'], true) ? $in['paper'] : 'a4',
            'style' => isset(self::STYLES[$in['style'] ?? '']) ? $in['style'] : 'classic',
            'header_mode' => in_array($in['header_mode'] ?? '', ['text', 'image', 'none'], true) ? $in['header_mode'] : 'text',
            'doctor_block' => ($in['doctor_block'] ?? '') === 'right' ? 'right' : 'left',
            'header_text' => mb_substr(trim((string) ($in['header_text'] ?? '')), 0, 2000) ?: null,
            'footer_text' => mb_substr(trim((string) ($in['footer_text'] ?? '')), 0, 2000) ?: null,
            'font_size' => round($font, 1), 'accent' => $accent !== '' ? strtoupper($accent) : null, 'show_signature' => ! empty($in['show_signature']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        foreach (['header', 'footer'] as $k) {
            $new = ClinicFiles::store($files[$k] ?? null, $k, $k . '_image');
            if ($new !== null) {
                $row[$k . '_file_id'] = $new;
            } elseif (! empty($in['remove_' . $k])) {
                $row[$k . '_file_id'] = null;
            }
        }
        $s = service('posSession');
        $db = db_connect();
        if ($old['saved']) {
            $db->table('clinic_templates')->where('business_id', $this->bid())->where('doctor_id', $doctorId)->update($row);
            foreach (['header_file_id', 'footer_file_id'] as $col) {
                if (array_key_exists($col, $row) && (int) ($old[$col] ?? 0) !== (int) ($row[$col] ?? 0)) {
                    ClinicFiles::delete($old[$col] !== null ? (int) $old[$col] : null);
                }
            }
        } else {
            $db->table('clinic_templates')->insert($row + ['tenant_id' => $s->tenantId(), 'business_id' => $this->bid(), 'doctor_id' => $doctorId]);
        }
        service('audit')->activity('Prescription pad saved', $doctorId > 0 ? 'Doctor #' . $doctorId : 'Clinic-wide');
    }

    /** Remove a doctor's own pad (the doctor then prints on the clinic's). */
    public function resetPad(int $doctorId): void
    {
        $old = $this->pad($doctorId);
        if ($doctorId > 0 && $old['saved']) {
            db_connect()->table('clinic_templates')->where('business_id', $this->bid())->where('doctor_id', $doctorId)->delete();
            ClinicFiles::delete($old['header_file_id'] !== null ? (int) $old['header_file_id'] : null);
            ClinicFiles::delete($old['footer_file_id'] !== null ? (int) $old['footer_file_id'] : null);
        }
    }
}
