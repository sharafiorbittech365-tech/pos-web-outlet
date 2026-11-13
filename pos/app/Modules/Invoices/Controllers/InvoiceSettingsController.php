<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Controllers\BaseController;
use App\Modules\Invoices\Services\InvoiceSettings;
use App\Modules\Invoices\Services\SettingChanges;
use App\Modules\Orders\Services\PrintSettings;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;

/** Invoices › Invoice page: design, images, texts and numbering (audited). */
final class InvoiceSettingsController extends BaseController
{
    public function index(): string
    {
        $set = InvoiceSettings::forCurrent();
        $b = service('posSession')->business();

        return $this->page('App\Modules\Invoices\Views\settings', [
            'title' => 'Invoice page', 'cfg' => $set->get(), 'next' => $set->nextNumber(), 'images' => array_map(static fn ($k) => $set->imageUrl($k), array_combine(array_keys(InvoiceSettings::IMAGES), array_keys(InvoiceSettings::IMAGES))),
            'print' => PrintSettings::for($b, 'invoice'), 'history' => SettingChanges::latest('invoice_page', 15),
        ]);
    }

    public function save(): RedirectResponse
    {
        InvoiceSettings::forCurrent()->save((array) $this->request->getPost());

        return $this->done('Invoice page saved.', site_url('invoices/settings'));
    }

    public function upload(string $kind): RedirectResponse
    {
        InvoiceSettings::forCurrent()->upload($kind, $this->request->getFile('image'));

        return $this->done((InvoiceSettings::IMAGES[$kind] ?? 'Image') . ' uploaded.', site_url('invoices/settings') . '#images');
    }

    public function removeImage(string $kind): RedirectResponse
    {
        InvoiceSettings::forCurrent()->removeImage($kind);

        return $this->done((InvoiceSettings::IMAGES[$kind] ?? 'Image') . ' removed.', site_url('invoices/settings') . '#images');
    }

    /** The outlet's own header / footer image (staff of this outlet only). */
    public function image(string $kind): ResponseInterface
    {
        $file = InvoiceSettings::forCurrent()->imageFile($kind) ?? throw new NotFoundException('No image.');

        return $this->response->setHeader('Content-Type', $file['mime'])->setHeader('Cache-Control', 'private, max-age=86400')
            ->setHeader('X-Content-Type-Options', 'nosniff')->setBody((string) file_get_contents($file['path']));
    }
}
