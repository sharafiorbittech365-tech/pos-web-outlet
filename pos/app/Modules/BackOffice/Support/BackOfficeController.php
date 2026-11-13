<?php

declare(strict_types=1);

namespace App\Modules\BackOffice\Support;

use App\Controllers\BaseController;
use App\Libraries\PosSession;

/**
 * Base for the back-office modules (products, inventory, reports, setup …).
 * Adds the back-office assets (tabs, repeatable rows, VAT preview, print) to every page and a few
 * shortcuts to the signed-in workspace. Business rules stay in each module's Services.
 */
abstract class BackOfficeController extends BaseController
{
    protected function s(): PosSession
    {
        return service('posSession');
    }

    /** @return array{symbol: string, decimals: int, position: string} */
    protected function cur(): array
    {
        return $this->s()->currency();
    }

    protected function page(string $view, array $data = [], string $layout = 'App\Views\layout'): string
    {
        $data['scripts'] = ($data['scripts'] ?? '') . Assets::tags();

        return parent::page($view, $data, $layout);
    }

    /** Integer query parameter (0 when missing). */
    protected function getInt(string $key): int
    {
        return (int) ($this->request->getGet($key) ?? 0);
    }

    protected function getStr(string $key, int $max = 100): string
    {
        return mb_substr(trim((string) ($this->request->getGet($key) ?? '')), 0, $max);
    }

    /** Raw POST array (for repeatable rows); services sanitise every value. */
    protected function postArray(string $key): array
    {
        $v = $this->request->getPost($key);

        return is_array($v) ? $v : [];
    }
}
