<?php

declare(strict_types=1);

namespace App\Filters;

use App\Modules\Multisite\Services\MultisiteDirectory;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\ModuleDisabledException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Services\MultisiteKeys;

/**
 * Authentication of the multi-outlet website (routes under /v1/multisite). Two kinds of caller:
 *
 *   own deployment       Authorization: Bearer mk_live_…        (POS › Website › Multi-outlet website)
 *   platform-hosted      Authorization: Bearer <gateway token> + X-Multisite-Host: <slug>.<siteHost>
 *
 * Then: server-side only (any browser Origin is refused) → subscription active (402) → modules
 * website + multisite on (403) → website switched on (403). The tenant comes from the credential;
 * which outlets it may reach is read from multisite_outlets on every request (MultisiteDirectory).
 */
final class MultisiteAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return null;
        }
        if ($request->getHeaderLine('Origin') !== '') {
            throw new PermissionDeniedException('The multi-outlet website key is server-side only. Call the API from the website\'s server.');
        }
        $hdr = $request->getHeaderLine('Authorization');
        $bearer = preg_match('/^Bearer\s+(\S+)$/i', $hdr, $m) ? $m[1] : '';
        if ($bearer === '') {
            throw new AuthenticationException('Missing credentials. Send "Authorization: Bearer <key>".');
        }
        $host = trim($request->getHeaderLine('X-Multisite-Host'));
        $keys = new MultisiteKeys();
        if (str_starts_with($bearer, MultisiteKeys::PREFIX)) {
            $r = $keys->resolve($bearer);
            $via = 'key';
        } elseif ($host !== '' && service('storefrontGateway')->matches($bearer)) {
            $r = $keys->byHost($host);
            $via = 'gateway';
        } else {
            throw new AuthenticationException('Invalid website key.');
        }

        $ms = MultisiteDirectory::load($r['multisite_id']);
        $access = service('moduleAccess')->forTenant((int) $ms['tenant_id']);
        $access->assertActive();
        if (! $access->has('website') || ! $access->has('multisite')) {
            throw new ModuleDisabledException('The multi-outlet website is not part of this account\'s plan.');
        }
        if (! (bool) $ms['is_enabled']) {
            throw new PermissionDeniedException('This website is not published.');
        }

        service('apiCaller')->set([
            'id' => $r['key_id'], 'tenant_id' => (int) $ms['tenant_id'], 'business_id' => (int) $ms['home_business_id'], 'site_id' => (int) $ms['home_site_id'],
            'key_type' => 'secret', 'scopes' => MultisiteKeys::SCOPES, 'is_storefront' => true, 'site_mode' => 'storefront', 'slug' => (string) $ms['slug'],
            'origins' => [], 'via' => $via, 'multisite_id' => (int) $ms['id'], 'plan' => $access->plan['code'],
        ]);
        service('tenant')->set((int) $ms['tenant_id'], (int) $ms['home_business_id'], null, 'API');
        service('multisite')->set($ms);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $caller = service('apiCaller');
        if ($caller->has()) {
            service('apiKeys')->touch((int) $caller->keyId(), $response->getStatusCode() >= 400);
        }

        return $response;
    }
}
