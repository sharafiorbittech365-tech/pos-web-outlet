<?php

declare(strict_types=1);

namespace App\Modules\BackOffice\Support;

/** Lucide icon keys offered for products, categories and outlets (same set as the design build). */
final class Icons
{
    /** UI-only glyphs that make no sense as a product / category symbol. */
    private const SKIP = ['alert', 'arrow', 'back', 'chev', 'chevl', 'chevron', 'menu', 'moon', 'sun', 'x', 'xc', 'plus', 'plusc', 'minus', 'logout', 'search', 'search-x', 'swap', 'edit', 'editbox', 'trash', 'copy', 'download', 'expand', 'external', 'external-link', 'check', 'checks', 'refresh', 'undo', 'eye'];

    /** @return list<string> */
    public static function choices(): array
    {
        static $list = null;
        if ($list === null) {
            $file = dirname((string) (new \ReflectionClass(\OmniPOS\Ui\Libraries\DataTable::class))->getFileName(), 2) . '/Config/icons.php';
            $set = is_file($file) ? (array) require $file : [];
            $list = array_values(array_diff(array_keys($set), self::SKIP));
            sort($list);
        }

        return $list;
    }

    public static function valid(?string $key, string $default = 'package'): string
    {
        return $key !== null && in_array($key, self::choices(), true) ? $key : $default;
    }
}
