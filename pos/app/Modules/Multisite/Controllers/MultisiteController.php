<?php

declare(strict_types=1);

namespace App\Modules\Multisite\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Multisite\Services\MultisiteAdmin;
use CodeIgniter\HTTP\RedirectResponse;

/** POS › Website › Multi-outlet website: settings, outlets shown, and the website's key. */
final class MultisiteController extends BackOfficeController
{
    private function to(): string
    {
        return site_url('website/multisite');
    }

    public function index(): string
    {
        $admin = MultisiteAdmin::forCurrent();
        $ms = $admin->website();
        $this->response->setHeader('Cache-Control', 'no-store');

        return $this->page('App\Modules\Multisite\Views\index', [
            'title' => 'Multi-outlet website', 'ms' => $ms, 'outlets' => $admin->outlets(), 'key' => $admin->key(), 'addr' => $admin->addresses($ms),
            'newKey' => session()->getFlashdata('new_ms_key'), 'errors' => (array) (session()->getFlashdata('errors') ?? []),
        ]);
    }

    public function save(): RedirectResponse
    {
        MultisiteAdmin::forCurrent()->save((array) $this->request->getPost());

        return $this->done('Website settings saved.', $this->to());
    }

    public function outlets(): RedirectResponse
    {
        $n = MultisiteAdmin::forCurrent()->saveOutlets(array_map('strval', $this->postArray('on')), $this->postArray('sort'));

        return $this->done($n === 0 ? 'No outlet is shown — the website is switched off.' : 'Saved — ' . $n . ' outlet' . ($n === 1 ? '' : 's') . ' on the website.', $this->to() . '#outlets');
    }

    public function issueKey(): RedirectResponse
    {
        $out = MultisiteAdmin::forCurrent()->issueKey();

        // shown on the next page only — only a fingerprint is stored
        return redirect()->to($this->to() . '#key')->with('new_ms_key', $out['key'])->with('success', 'New website key issued — copy it now. The previous key stopped working.');
    }

    public function revokeKey(): RedirectResponse
    {
        MultisiteAdmin::forCurrent()->revokeKey();

        return $this->done('Website key revoked — a website running on your own server stops working.', $this->to() . '#key');
    }
}
