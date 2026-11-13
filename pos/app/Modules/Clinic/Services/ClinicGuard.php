<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/**
 * Who may do what in the clinic, and the medical-record access trail.
 *
 *   clinic.appointments   front desk: appointments, patient registration (name, phone, age …)
 *   clinic.patients.view  medical records: visit history, prescriptions (every open is logged)
 *   clinic.prescribe      write, finalize and revise prescriptions
 *   clinic.manage         doctors, schedules, test list, pick lists, prescription pads
 *
 * The routes carry module:clinic and can:<perm>; services check again (a service may be called
 * from another page). The clinic runs on outlets of the clinic business type, or where the outlet's
 * features.clinic is switched on.
 */
final class ClinicGuard
{
    public static function enabled(): bool
    {
        $s = service('posSession');
        $b = $s->business();

        return $s->moduleOn('clinic') && (($b['business_type'] ?? '') === 'clinic' || ! empty($b['settings']['features']['clinic']));
    }

    public static function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new BusinessRuleException('The clinic is not set up for this outlet.');
        }
    }

    public static function can(string $perm): bool
    {
        return service('posSession')->can($perm);
    }

    public static function require(string $perm): void
    {
        self::assertEnabled();
        if (! self::can($perm)) {
            throw new PermissionDeniedException(match ($perm) {
                'clinic.patients.view' => 'Only clinicians and authorised staff can open medical records.',
                'clinic.prescribe' => 'Only doctors and authorised staff can write prescriptions.',
                default => 'Your role cannot do this. Ask the owner or a manager.',
            });
        }
    }

    /** Record that the signed-in user opened / printed / changed a medical record. */
    public static function logAccess(int $patientId, ?int $prescriptionId, string $action): void
    {
        $s = service('posSession');
        $u = $s->user();
        $req = service('request');
        db_connect()->table('clinic_access_log')->insert([
            'tenant_id' => $s->tenantId(), 'business_id' => $s->businessId(), 'user_id' => $u['id'] ?? null, 'user_name' => mb_substr((string) ($u['name'] ?? 'System'), 0, 100),
            'patient_id' => $patientId, 'prescription_id' => $prescriptionId, 'action' => mb_substr($action, 0, 40),
            'ip_address' => method_exists($req, 'getIPAddress') ? $req->getIPAddress() : null, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string, mixed>> latest accesses to a patient's record */
    public static function accessLog(int $patientId, int $limit = 50): array
    {
        return db_connect()->table('clinic_access_log')->select('user_name, action, prescription_id, ip_address, created_at')
            ->where('business_id', service('posSession')->businessId())->where('patient_id', $patientId)
            ->orderBy('id', 'desc')->limit($limit)->get()->getResultArray();
    }
}
