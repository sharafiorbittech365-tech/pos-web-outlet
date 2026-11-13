<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\ImageCompressor;

/**
 * A product's pictures (table product_images): up to ten, with alt text and an order; the first is the main picture.
 * Every upload is checked, scaled down and re-encoded (ImageCompressor), and a small thumbnail is made for lists.
 * products.image_path (main) and products.gallery (the rest) are kept in step, so the till, the API and older
 * code keep working. Pictures are stored in site_media and addressed /media/<id>-<hash>.<ext>.
 */
final class ProductPhotos
{
    public const MAX = 10;

    /** @return list<array{id: int, path: string, thumb_path: ?string, alt: ?string, sort_order: int}> */
    public static function rows(int $productId): array
    {
        return db_connect()->table('product_images')->select('id, path, thumb_path, alt, sort_order')->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
    }

    /**
     * Uploaded pictures of the form, checked now (a bad file stops the whole save before anything is written).
     *
     * @param list<UploadedFile> $files
     * @return list<UploadedFile>
     */
    public function check(array $files): array
    {
        $ok = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (! $file->isValid() || $file->hasMoved()) {
                throw new ValidationException('A picture could not be uploaded.', ['photos' => 'A picture could not be uploaded — try a smaller file.']);
            }
            $bytes = $file->getSize() > ImageCompressor::MAX_UPLOAD ? false : @file_get_contents($file->getTempName());
            if ($bytes === false || @getimagesizefromstring($bytes) === false) {
                throw new ValidationException('A picture is not usable.', ['photos' => 'Use PNG, JPG, WebP or GIF pictures up to 12 MB each.']);
            }
            $ok[] = $file;
        }

        return $ok;
    }

    /**
     * Applies the form: alt texts, removed pictures, the chosen main one, the order (photo_id[] in screen order) and the new uploads.
     *
     * @param list<UploadedFile>   $uploads already checked
     * @param array<string, mixed> $in      POST: photo_id[], photo_alt[id], photo_remove[], photo_main, photo_new_alt[]
     */
    public function apply(int $productId, array $uploads, array $in): void
    {
        $db = db_connect();
        $tenant = service('tenant');
        $bid = $tenant->businessId();
        if ($db->table('products')->where('id', $productId)->where('business_id', $bid)->countAllResults() === 0) {
            return;
        }
        $have = [];
        foreach (self::rows($productId) as $r) {
            $have[(int) $r['id']] = $r;
        }
        $remove = array_map('intval', (array) ($in['photo_remove'] ?? []));
        // screen order first, then anything the form did not mention
        $order = array_values(array_unique(array_filter(array_map('intval', (array) ($in['photo_id'] ?? [])), static fn (int $i): bool => isset($have[$i]))));
        $order = array_merge($order, array_values(array_diff(array_keys($have), $order)));
        $keep = array_values(array_diff($order, $remove));
        $main = (int) ($in['photo_main'] ?? 0);
        if ($main > 0 && in_array($main, $keep, true)) {
            $keep = array_merge([$main], array_values(array_diff($keep, [$main])));
        }
        if (count($keep) + count($uploads) > self::MAX) {
            throw new ValidationException('Too many pictures.', ['photos' => 'A product can have up to ' . self::MAX . ' pictures.']);
        }
        $now = date('Y-m-d H:i:s');
        $alts = (array) ($in['photo_alt'] ?? []);
        foreach ($keep as $sort => $id) {
            $alt = mb_substr(trim((string) ($alts[$id] ?? $have[$id]['alt'] ?? '')), 0, 160);
            $db->table('product_images')->where('id', $id)->update(['sort_order' => $sort, 'alt' => $alt !== '' ? $alt : null]);
        }
        $newAlt = array_values((array) ($in['photo_new_alt'] ?? preg_split('/\R/', (string) ($in['photo_new_alt_text'] ?? '')) ?: []));
        foreach ($uploads as $i => $file) {
            $bytes = (string) file_get_contents($file->getTempName());
            $path = ImageCompressor::store($bytes, $tenant->tenantId(), $bid, 'product', ImageCompressor::MAIN_SIDE);
            $thumb = ImageCompressor::store($bytes, $tenant->tenantId(), $bid, 'product-thumb', ImageCompressor::THUMB_SIDE);
            $alt = mb_substr(trim((string) ($newAlt[$i] ?? '')), 0, 160);
            $db->table('product_images')->insert([
                'tenant_id' => $tenant->tenantId(), 'business_id' => $bid, 'product_id' => $productId, 'path' => $path, 'thumb_path' => $thumb,
                'alt' => $alt !== '' ? $alt : null, 'sort_order' => count($keep) + $i, 'created_at' => $now,
            ]);
        }
        foreach (array_intersect($remove, array_keys($have)) as $id) {
            ImageCompressor::forget((string) $have[$id]['path'], $bid);
            ImageCompressor::forget((string) ($have[$id]['thumb_path'] ?? ''), $bid);
            $db->table('product_images')->where('id', $id)->delete();
        }
        $this->syncProduct($productId);
    }

    /** products.image_path = main picture, products.gallery = the others (what the till, the API list and older code read). */
    public function syncProduct(int $productId): void
    {
        $paths = array_map(static fn (array $r): string => (string) $r['path'], self::rows($productId));
        db_connect()->table('products')->where('id', $productId)->where('business_id', service('tenant')->businessId())->update([
            'image_path' => $paths[0] ?? null,
            'gallery' => count($paths) > 1 ? json_encode(array_slice($paths, 1), JSON_UNESCAPED_SLASHES) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
