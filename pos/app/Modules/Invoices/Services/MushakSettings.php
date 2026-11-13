<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Mushak-6.3 settings of an outlet (businesses.settings.mushak), changed only with
 * "mushak.settings" and audited (setting_changes, before / after):
 *
 *   enabled              the outlet is VAT-registered and issues Mushak-6.3
 *   reg_name             নিবন্ধিত ব্যক্তির নাম — the registered person's name as on the BIN certificate
 *   bin                  নিবন্ধিত ব্যক্তির বিআইএন — entered by an authorized user, never generated
 *   address              চালানপত্র ইস্যুর ঠিকানা — address of the premises issuing the invoice
 *   responsible_name     প্রতিষ্ঠান কর্তৃপক্ষের দায়িত্বপ্রাপ্ত ব্যক্তির নাম
 *   responsible_title    পদবী (designation)
 *   auto_issue           give every new invoice its Mushak-6.3 when it is issued
 *
 * BIN format: NBR describes the BIN as a 9-digit number (8 serial digits + 1 check digit; NBR VAT
 * compliance guide and VAT FAQ). e-BIN certificates are commonly printed with a 4-digit branch /
 * unit suffix (13 digits, e.g. 000123456-0101); that form is accepted too but was not confirmed
 * from a primary source. The check digit algorithm is not published, so it is not verified.
 */
final class MushakSettings
{
    public const DEFAULTS = ['enabled' => false, 'reg_name' => '', 'bin' => '', 'address' => '', 'responsible_name' => '', 'responsible_title' => '', 'auto_issue' => false];

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
        return array_merge(self::DEFAULTS, array_intersect_key((array) ($this->business['settings']['mushak'] ?? []), self::DEFAULTS));
    }

    /** Mushak-6.3 is on and complete (name, BIN and address of the registered person). */
    public function enabled(): bool
    {
        $m = $this->get();

        return (bool) $m['enabled'] && self::normalizeBin((string) $m['bin']) !== null && trim((string) $m['reg_name']) !== '' && trim((string) $m['address']) !== '';
    }

    public function autoIssue(): bool
    {
        return $this->enabled() && (bool) $this->get()['auto_issue'];
    }

    /** 9 digits, or 9 + 4 (branch); spaces / hyphens allowed. Returns the stored form or null. */
    public static function normalizeBin(string $bin): ?string
    {
        $digits = preg_replace('/[\s\-]/', '', trim($bin));
        if (! is_string($digits) || ! preg_match('/^\d{9}(\d{4})?$/', $digits)) {
            return null;
        }

        return strlen($digits) === 13 ? substr($digits, 0, 9) . '-' . substr($digits, 9) : $digits;
    }

    /** Validate and save; audited. */
    public function save(array $in): array
    {
        $before = $this->get();
        $txt = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $after = [
            'enabled' => ! empty($in['enabled']), 'reg_name' => $txt('reg_name', 160), 'bin' => $txt('bin', 20), 'address' => $txt('address', 255),
            'responsible_name' => $txt('responsible_name', 100), 'responsible_title' => $txt('responsible_title', 100), 'auto_issue' => ! empty($in['auto_issue']),
        ];
        $errors = [];
        if ($after['bin'] !== '') {
            $bin = self::normalizeBin($after['bin']);
            if ($bin === null) {
                $errors['bin'] = 'The BIN has 9 digits (13 with the 4-digit branch code), as on your BIN certificate.';
            } else {
                $after['bin'] = $bin;
            }
        }
        if ($after['enabled']) {
            foreach (['reg_name' => 'Enter the registered name as on the BIN certificate.', 'bin' => 'Enter the BIN of this outlet.', 'address' => 'Enter the address of the premises that issues the invoice.'] as $k => $msg) {
                if ($after[$k] === '' && ! isset($errors[$k])) {
                    $errors[$k] = $msg;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the Mushak-6.3 settings.', $errors);
        }
        $this->business['settings'] = SettingsStore::put((int) $this->business['tenant_id'], (int) $this->business['id'], 'mushak', $after);
        SettingChanges::record('mushak', $before, $after);

        return $after;
    }
}
