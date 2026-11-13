<?php

declare(strict_types=1);

namespace App\Controllers;

use OmniPOS\Core\Http\BaseController as CoreController;

/**
 * Base for this application's controllers (modules extend it).
 * Validation, layouts and JSON helpers come from OmniPOS\Core\Http\BaseController.
 */
abstract class BaseController extends CoreController
{
    /** @return array{name: string, role: string, id: int|null} the signed-in staff member, for audit rows */
    protected function actor(): array
    {
        return service('adminSession')->actor();
    }

    /** Write one platform audit row as the signed-in staff member. */
    protected function audit(string $action, string $detail = '', ?int $tenantId = null): void
    {
        service('audit')->platform($action, $detail, $tenantId, $this->actor());
    }

    /** A GET filter value limited to an allow-list ('' when not allowed). */
    protected function pick(string $name, array $allowed): string
    {
        $v = (string) $this->request->getGet($name);

        return in_array($v, $allowed, true) ? $v : '';
    }
}
