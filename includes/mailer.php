<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function appMailConfig(): array
{
    $localConfig = __DIR__ . '/../config/mail.php';
    $exampleConfig = __DIR__ . '/../config/mail.example.php';

    if (file_exists($localConfig)) {
        return require $localConfig;
    }

    return require $exampleConfig;
}

function appMailResponsiveCss(): string
{
    return <<<CSS
<style>
@media only screen and (max-width: 640px) {
    body { margin: 0 !important; padding: 0 !important; }
    table { width: 100% !important; }
    img { max-width: 100% !important; height: auto !important; }
    td, th { box-sizing: border-box !important; }
    .email-wrap { padding: 12px !important; }
    .email-container { width: 100% !important; max-width: 100% !important; border-radius: 10px !important; }
    .email-card { margin: 0 !important; padding: 18px !important; border-radius: 10px !important; }
    .email-logo { padding: 18px 16px 10px !important; }
    .email-stack, .email-stack tbody, .email-stack tr, .email-stack td { display: block !important; width: 100% !important; }
    .email-stack td { padding-left: 0 !important; padding-right: 0 !important; border-right: 0 !important; }
    .email-stat td { display: block !important; width: 100% !important; border-bottom: 1px solid #dfeadf !important; }
    .email-stat td:last-child { border-bottom: 0 !important; }
    .email-code { font-size: 34px !important; letter-spacing: 8px !important; }
    .email-title { font-size: 23px !important; line-height: 1.18 !important; }
    .email-copy { font-size: 14px !important; line-height: 1.5 !important; }
    .email-footer { padding: 14px 16px !important; font-size: 12px !important; }
    .email-hide-mobile { display: none !important; max-height: 0 !important; overflow: hidden !important; }
    .email-detail-row td { display: block !important; width: 100% !important; padding-right: 0 !important; }
    .email-detail-icon { padding-bottom: 8px !important; }
    .email-footer-stack, .email-footer-stack tbody, .email-footer-stack tr, .email-footer-stack td { display: block !important; width: 100% !important; text-align: center !important; }
    .email-footer-social { padding-top: 10px !important; }
}
</style>
CSS;
}

function appMailPrepareHtml(string $htmlBody): string
{
    $css = appMailResponsiveCss();

    if (stripos($htmlBody, '<head') !== false) {
        return preg_replace('/<\/head>/i', $css . '</head>', $htmlBody, 1) ?? $htmlBody;
    }

    if (stripos($htmlBody, '<html') !== false) {
        return preg_replace('/<html([^>]*)>/i', '<html$1><head><meta name="viewport" content="width=device-width, initial-scale=1.0">' . $css . '</head>', $htmlBody, 1) ?? $htmlBody;
    }

    return '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0">' . $css . '</head><body style="margin:0;padding:0;">' . $htmlBody . '</body></html>';
}

function sendBrevoMail(array $config, string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody = '', array $replyTo = [], array $ccRecipients = []): bool
{
    if (empty($config['brevo_api_key']) || empty($config['from_email'])) {
        error_log('Mail error: BREVO_API_KEY and MAIL_FROM_EMAIL must be configured.');
        return false;
    }

    $payload = [
        'sender' => [
            'name' => $config['from_name'] ?: "J&J's Kitchenette",
            'email' => $config['from_email'],
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $toName,
            ],
        ],
        'subject' => $subject,
        'htmlContent' => appMailPrepareHtml($htmlBody),
        'textContent' => $plainBody !== '' ? $plainBody : trim(strip_tags($htmlBody)),
    ];

    $cc = [];
    foreach ($ccRecipients as $ccRecipient) {
        $ccEmail = $ccRecipient['email'] ?? '';
        if (filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
            $cc[] = [
                'email' => $ccEmail,
                'name' => $ccRecipient['name'] ?? '',
            ];
        }
    }

    if ($cc) {
        $payload['cc'] = $cc;
    }

    if (!empty($replyTo['email']) && filter_var($replyTo['email'], FILTER_VALIDATE_EMAIL)) {
        $payload['replyTo'] = [
            'email' => $replyTo['email'],
            'name' => $replyTo['name'] ?? '',
        ];
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . $config['brevo_api_key'],
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Mail error: Brevo request failed. ' . $curlError);
        return false;
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        error_log('Mail error: Brevo returned HTTP ' . $statusCode . ' - ' . $response);
        return false;
    }

    return true;
}

function sendAppMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody = '', array $embeddedImages = [], array $replyTo = [], array $ccRecipients = []): bool
{
    $config = appMailConfig();

    if (!empty($config['brevo_api_key'])) {
        return sendBrevoMail($config, $toEmail, $toName, $subject, $htmlBody, $plainBody, $replyTo, $ccRecipients);
    }

    $mail = new PHPMailer(true);

    try {
        if (empty($config['username']) || empty($config['password']) || empty($config['from_email'])) {
            error_log('Mail error: MAIL_USERNAME, MAIL_PASSWORD, and MAIL_FROM_EMAIL must be configured.');
            return false;
        }

        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->Port = (int) $config['port'];
        $mail->Timeout = 10;
        $mail->SMTPKeepAlive = false;

        if (!empty($config['encryption'])) {
            $mail->SMTPSecure = $config['encryption'];
        }

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($toEmail, $toName);

        foreach ($ccRecipients as $ccRecipient) {
            $ccEmail = $ccRecipient['email'] ?? '';
            if (filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($ccEmail, $ccRecipient['name'] ?? '');
            }
        }

        if (!empty($replyTo['email']) && filter_var($replyTo['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo['email'], $replyTo['name'] ?? '');
        }

        foreach ($embeddedImages as $cid => $path) {
            if (is_string($cid) && is_string($path) && file_exists($path)) {
                $mail->addEmbeddedImage($path, $cid);
            }
        }

        $htmlBody = appMailPrepareHtml($htmlBody);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody !== '' ? $plainBody : trim(strip_tags($htmlBody));

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mail error: ' . ($mail->ErrorInfo ?: $e->getMessage()));
        return false;
    }
}
