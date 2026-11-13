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
    /** @var list<string> url, form, ui (design helpers) and pos (payment labels, amount in words …) */
    protected $helpers = ['url', 'form', 'ui', 'pos'];

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        // CSV export of every list follows the account's plan feature "csv" (Super Admin can override it)
        \OmniPOS\Ui\Libraries\DataTable::exportPolicy(static function (): bool {
            $s = service('posSession');

            return $s->tenantId() <= 0 || $s->access()->feature('csv');
        });
    }

    /** JSON body of a fetch() POST (the till and other AJAX calls), or the form fields. */
    protected function input(): array
    {
        $json = $this->request->is('json') ? $this->request->getJSON(true) : null;

        return is_array($json) ? $json : (array) ($this->request->getPost() ?? []);
    }
}
