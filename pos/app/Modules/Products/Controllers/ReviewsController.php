<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use CodeIgniter\HTTP\RedirectResponse;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\ImageCompressor;

/** Product reviews: list, add (with pictures), approve / reject, verified purchase, delete. Scoped to this outlet's products. */
final class ReviewsController extends BackOfficeController
{
    private function product(int $id): array
    {
        return db_connect()->table('products')->select('id, name')->where('id', $id)->where('business_id', service('tenant')->businessId())->where('deleted_at', null)->get()->getRowArray()
            ?? throw new NotFoundException('Product not found.');
    }

    private function review(int $id): array
    {
        return db_connect()->table('product_reviews')->where('id', $id)->where('business_id', service('tenant')->businessId())->get()->getRowArray()
            ?? throw new NotFoundException('Review not found.');
    }

    public function index(int $productId): string
    {
        $p = $this->product($productId);
        $rows = db_connect()->table('product_reviews')->where('product_id', $productId)->where('business_id', service('tenant')->businessId())->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        $avg = $rows === [] ? 0 : array_sum(array_map(static fn (array $r): int => $r['status'] === 'approved' ? (int) $r['rating'] : 0, $rows)) / max(1, count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'approved')));

        return $this->page('App\Modules\Products\Views\reviews', ['title' => 'Reviews · ' . $p['name'], 'p' => $p, 'rows' => $rows, 'avg' => $avg, 'manage' => $this->s()->can('products.manage')]);
    }

    public function store(int $productId): RedirectResponse
    {
        $p = $this->product($productId);
        $in = (array) $this->request->getPost();
        $rating = (int) ($in['rating'] ?? 0);
        $author = mb_substr(trim((string) ($in['author'] ?? '')), 0, 80);
        $errors = [];
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Choose 1 to 5 stars.';
        }
        if ($author === '') {
            $errors['author'] = 'Enter the customer name.';
        }
        $images = [];
        foreach ($this->request->getFileMultiple('images') ?: [] as $f) {
            if ($f->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (! $f->isValid() || count($images) >= 5) {
                $errors['images'] = 'Up to 5 valid pictures.';
                break;
            }
            $images[] = $f;
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the review.', $errors);
        }
        $t = service('tenant');
        $paths = [];
        foreach ($images as $f) {
            $paths[] = ImageCompressor::store((string) file_get_contents($f->getTempName()), $t->tenantId(), $t->businessId(), 'review', 1000);
        }
        $status = in_array($in['status'] ?? 'approved', ['approved', 'pending', 'rejected'], true) ? (string) $in['status'] : 'approved';
        db_connect()->table('product_reviews')->insert([
            'tenant_id' => $t->tenantId(), 'business_id' => $t->businessId(), 'product_id' => $productId, 'author' => $author, 'rating' => $rating,
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 160) ?: null, 'body' => mb_substr(trim((string) ($in['body'] ?? '')), 0, 3000) ?: null,
            'images' => $paths !== [] ? json_encode($paths, JSON_UNESCAPED_SLASHES) : null, 'verified_purchase' => ! empty($in['verified_purchase']) ? 1 : 0, 'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        service('audit')->activity('Review added', $p['name']);

        return $this->done('Review added.', site_url('products/' . $productId . '/reviews'));
    }

    public function status(int $id): RedirectResponse
    {
        $r = $this->review($id);
        $to = (string) $this->request->getPost('status');
        if (! in_array($to, ['approved', 'pending', 'rejected'], true)) {
            throw new ValidationException('Unknown status.', []);
        }
        db_connect()->table('product_reviews')->where('id', $id)->update(['status' => $to]);

        return $this->done('Review ' . $to . '.', site_url('products/' . $r['product_id'] . '/reviews'));
    }

    public function verified(int $id): RedirectResponse
    {
        $r = $this->review($id);
        db_connect()->table('product_reviews')->where('id', $id)->update(['verified_purchase' => (int) $r['verified_purchase'] === 1 ? 0 : 1]);

        return $this->done('Saved.', site_url('products/' . $r['product_id'] . '/reviews'));
    }

    public function delete(int $id): RedirectResponse
    {
        $r = $this->review($id);
        foreach ((array) (json_decode((string) $r['images'], true) ?: []) as $img) {
            ImageCompressor::forget((string) $img, service('tenant')->businessId());
        }
        db_connect()->table('product_reviews')->where('id', $id)->delete();

        return $this->done('Review deleted.', site_url('products/' . $r['product_id'] . '/reviews'));
    }
}
