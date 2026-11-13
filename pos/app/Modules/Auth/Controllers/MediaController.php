<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A shop's website images (logo, favicon, banner) kept in the shared database (site_media), served by the
 * POS app itself so the till, invoices and receipts show the logo without the API app running.
 * Same address and rules as the API's GET /media/<id>-<hash>.<ext>.
 */
final class MediaController extends BaseController
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon'];

    public function show(string $id, string $file): ResponseInterface
    {
        [$hash, $ext] = array_pad(explode('.', $file, 2), 2, '');
        $row = isset(self::TYPES[$ext])
            ? db_connect()->table('site_media')->select('hash, data')->where('id', (int) $id)->where('hash', $hash)->where('ext', $ext)->get()->getRowArray()
            : null;
        if ($row === null) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }
        $etag = '"' . $row['hash'] . '"';
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $res = $this->response->setHeader('Cache-Control', 'public, max-age=31536000, immutable')->setHeader('ETag', $etag)->setHeader('X-Content-Type-Options', 'nosniff');
        if ($this->request->getHeaderLine('If-None-Match') === $etag) {
            return $res->setStatusCode(304);
        }

        return $res->setContentType(self::TYPES[$ext])->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")->setBody((string) $row['data']);
    }
}
