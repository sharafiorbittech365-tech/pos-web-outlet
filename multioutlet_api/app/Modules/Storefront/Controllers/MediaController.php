<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A shop's website images — logo, favicon, banner — uploaded in POS Setup › Website and kept in the
 * shared database (site_media). Public, like any image on a web page: GET /media/<id>-<hash>.<ext>.
 * The hash (first 16 hex of the file's SHA-256) is part of the address, so addresses cannot be
 * counted through, and a replaced image gets a new address (the old one may be cached for a year).
 */
final class MediaController extends BaseController
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon'];

    public function show(string $id, string $hash, string $ext): ResponseInterface
    {
        $row = db_connect()->table('site_media')->select('id, hash, ext, size, data')->where('id', (int) $id)->where('hash', $hash)->where('ext', $ext)->get()->getRowArray();
        if ($row === null || ! isset(self::TYPES[$ext])) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }
        $etag = '"' . $row['hash'] . '"';
        $this->response->removeHeader('Cache-Control');
        $this->response->removeHeader('Pragma');
        $res = $this->response
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setHeader('ETag', $etag)
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Cross-Origin-Resource-Policy', 'cross-origin')
            ->setHeader('X-Content-Type-Options', 'nosniff');
        if ($this->request->getHeaderLine('If-None-Match') === $etag) {
            return $res->setStatusCode(304);
        }

        // an image is served as an image only: never as a page (a "picture" with script in it stays inert)
        return $res->setContentType(self::TYPES[$ext])->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")->setBody((string) $row['data']);
    }
}
