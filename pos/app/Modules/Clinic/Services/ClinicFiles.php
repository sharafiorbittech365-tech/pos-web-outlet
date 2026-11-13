<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Clinic images — doctor photo, signature, prescription header and footer — uploaded from the POS,
 * checked to be a real PNG / JPEG / WebP image, scaled down with GD and kept in clinic_files so the
 * POS, the API (doctor photos on the website) and print pages all read the same copy.
 */
final class ClinicFiles
{
    /** kind => [max width, max height, output mime] */
    public const KINDS = [
        'photo' => [600, 600, 'image/jpeg'],
        'signature' => [900, 300, 'image/png'],
        'header' => [2400, 900, 'image/png'],
        'footer' => [2400, 600, 'image/png'],
    ];

    public const MAX_UPLOAD = 4 * 1024 * 1024;

    /** Store an uploaded image; returns the file id, or null when no file was chosen. */
    public static function store(?UploadedFile $file, string $kind, string $field): ?int
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $bad = static fn (string $m): ValidationException => new ValidationException($m, [$field => $m]);
        if (! isset(self::KINDS[$kind])) {
            throw $bad('Unknown image type.');
        }
        if (! $file->isValid() || $file->getSize() > self::MAX_UPLOAD) {
            throw $bad('Choose a PNG, JPEG or WebP image of at most 4 MB.');
        }
        $info = @getimagesize($file->getTempName());
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] < 16 || $info[1] < 16 || $info[0] > 8000 || $info[1] > 8000) {
            throw $bad('Choose a PNG, JPEG or WebP image.');
        }
        $src = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($file->getTempName()),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getTempName()),
            default => @imagecreatefromwebp($file->getTempName()),
        };
        if ($src === false) {
            throw $bad('This image could not be read.');
        }
        [$maxW, $maxH, $mime] = self::KINDS[$kind];
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        $mime === 'image/png' ? imagepng($dst, null, 7) : imagejpeg($dst, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $s = service('posSession');
        db_connect()->table('clinic_files')->insert([
            'tenant_id' => $s->tenantId(), 'business_id' => $s->businessId(), 'kind' => $kind, 'mime' => $mime, 'width' => $nw, 'height' => $nh,
            'size_bytes' => strlen($bytes), 'data' => $bytes, 'created_by' => $s->userId() ?: null, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /** @return array{mime: string, data: string, created_at: string} */
    public static function get(int $id): array
    {
        return db_connect()->table('clinic_files')->select('mime, data, created_at')->where('id', $id)->where('business_id', service('posSession')->businessId())
            ->get()->getRowArray() ?? throw new NotFoundException('Image not found.');
    }

    /** Remove an image no longer referenced (replaced photo, signature, header …). */
    public static function delete(?int $id): void
    {
        if ($id !== null && $id > 0) {
            db_connect()->table('clinic_files')->where('id', $id)->where('business_id', service('posSession')->businessId())->delete();
        }
    }

    public static function url(?int $id): ?string
    {
        return $id ? site_url('clinic/files/' . $id) : null;
    }
}
