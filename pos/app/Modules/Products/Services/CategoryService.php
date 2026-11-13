<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Modules\BackOffice\Support\Icons;
use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Models\Generated\CategoriesModel;

/** Product categories of the current outlet. Deleting one leaves its products uncategorised (FK SET NULL). */
final class CategoryService
{
    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    public function listQuery(): BaseBuilder
    {
        return db_connect()->table('categories c')
            ->select('c.id, c.parent_id, c.name, c.icon, c.sort_order, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.deleted_at IS NULL) AS n')
            ->where('c.business_id', $this->bid())->where('c.tenant_id', service('tenant')->tenantId());
    }

    /** @param array{name: string, icon?: string, sort_order?: int|string} $in */
    public function save(?int $id, array $in): int
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 100);
        if ($name === '') {
            throw new ValidationException('Enter the category name.', ['name' => 'Category name is required.']);
        }
        $dup = db_connect()->table('categories')->where('business_id', $this->bid())->where('name', $name);
        if ($id !== null) {
            $dup->where('id <>', $id);
        }
        if ($dup->countAllResults() > 0) {
            throw new ValidationException('A category with this name already exists.', ['name' => 'Already exists.']);
        }
        $parent = $this->parentId($in, $id);
        $model = new CategoriesModel();
        $row = ['name' => $name, 'icon' => Icons::valid($in['icon'] ?? null, 'tag'), 'sort_order' => max(0, min(9999, (int) ($in['sort_order'] ?? 0)))];
        if ($id !== null) {
            $model->find($id) ?? throw new NotFoundException('Category not found.');
            $model->update($id, $row);
            db_connect()->table('categories')->where('id', $id)->where('business_id', $this->bid())->update(['parent_id' => $parent]);
            service('audit')->activity('Category updated', $name);

            return $id;
        }
        if (! isset($in['sort_order']) || $in['sort_order'] === '') {
            $row['sort_order'] = (int) (db_connect()->table('categories')->selectMax('sort_order', 'm')->where('business_id', $this->bid())->get()->getRow('m') ?? 0) + 1;
        }
        $row['code'] = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: null;
        $newId = (int) $model->insert($row);
        if ($parent !== null) {
            db_connect()->table('categories')->where('id', $newId)->where('business_id', $this->bid())->update(['parent_id' => $parent]);
        }
        service('audit')->activity('Category added', $name);

        return $newId;
    }

    /** The chosen parent: a top-level category of this outlet, never the category itself, never for one that has children (two levels only). */
    private function parentId(array $in, ?int $id): ?int
    {
        $p = (int) ($in['parent_id'] ?? 0);
        if ($p <= 0) {
            return null;
        }
        $row = db_connect()->table('categories')->select('id, parent_id')->where('id', $p)->where('business_id', $this->bid())->get()->getRowArray();
        if ($row === null || $row['parent_id'] !== null || $p === $id) {
            throw new ValidationException('Choose a top-level category as the parent.', ['parent_id' => 'Pick a top-level category.']);
        }
        if ($id !== null && db_connect()->table('categories')->where('parent_id', $id)->countAllResults() > 0) {
            throw new ValidationException('This category already has subcategories.', ['parent_id' => 'A category with subcategories cannot become a subcategory.']);
        }

        return $p;
    }

    public function delete(int $id): void
    {
        $model = new CategoriesModel();
        $c = $model->find($id) ?? throw new NotFoundException('Category not found.');
        $model->delete($id);
        service('audit')->activity('Category deleted', (string) $c['name']);
    }
}
