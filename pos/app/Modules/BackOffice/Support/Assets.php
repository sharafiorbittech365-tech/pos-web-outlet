<?php

declare(strict_types=1);

namespace App\Modules\BackOffice\Support;

/** Back-office stylesheet + script (public/assets/backoffice), cache-busted by file time. */
final class Assets
{
    public static function tags(): string
    {
        $out = '';
        foreach (['backoffice.css' => 'css', 'backoffice.js' => 'js'] as $file => $kind) {
            $path = FCPATH . 'assets/backoffice/' . $file;
            if (! is_file($path)) {
                continue;
            }
            $url = base_url('assets/backoffice/' . $file) . '?v=' . filemtime($path);
            $out .= $kind === 'css'
                ? '<link rel="stylesheet" href="' . esc($url, 'attr') . '">'
                : '<script src="' . esc($url, 'attr') . '" defer></script>';
        }

        return $out;
    }
}
