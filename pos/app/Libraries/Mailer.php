<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Modules\Settings\Services\SmtpSettings;

/** Sends one e-mail through the outlet's SMTP settings (Setup › Email). */
final class Mailer
{
    /**
     * @param array<string, mixed>|null                            $smtp   settings (password in clear) — default: the signed-in outlet's
     * @param array{name: string, data: string, mime: string}|null $attach one buffered attachment
     *
     * @return array{ok: bool, error: string}
     */
    public static function send(string $to, string $subject, string $html, ?array $attach = null, ?array $smtp = null): array
    {
        $c = $smtp ?? SmtpSettings::get(service('posSession')->business());
        if ($c['host'] === '' || ! filter_var($c['from_email'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email is not set up yet — add the SMTP details in Setup › Email.'];
        }
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        $email = \Config\Services::email(null, false);
        $email->initialize([
            'protocol' => 'smtp', 'SMTPHost' => $c['host'], 'SMTPPort' => (int) $c['port'], 'SMTPUser' => $c['user'], 'SMTPPass' => $c['pass'],
            'SMTPCrypto' => $c['security'] === 'ssl' ? 'ssl' : ($c['security'] === 'none' ? '' : 'tls'), 'SMTPTimeout' => 12, 'SMTPKeepAlive' => false,
            'mailType' => 'html', 'charset' => 'UTF-8', 'newline' => "\r\n", 'CRLF' => "\r\n", 'wordWrap' => false,
        ]);
        $email->setFrom($c['from_email'], $c['from_name'] !== '' ? $c['from_name'] : (string) service('posSession')->business()['name']);
        if ($c['reply_to'] !== '' && filter_var($c['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $email->setReplyTo($c['reply_to']);
        }
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($html);
        if ($attach !== null) {
            $email->attach($attach['data'], 'attachment', $attach['name'], $attach['mime']);
        }
        try {
            $ok = $email->send(false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the mail server: ' . $e->getMessage()];
        }
        if (! $ok) {
            $msg = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($email->printDebugger([])))));

            return ['ok' => false, 'error' => mb_substr($msg !== '' ? $msg : 'The mail server refused the message.', 0, 240)];
        }

        return ['ok' => true, 'error' => ''];
    }
}
