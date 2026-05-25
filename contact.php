<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';
require_once __DIR__ . '/includes/mailer.php';

$contactSettings = [
    'store_name' => "J&J's Kitchenette",
    'store_email' => 'jjkitchenette@email.com',
    'store_phone' => '09910095940',
    'store_area' => 'Tangub City, Misamis Occidental'
];
$contactSettingsFile = __DIR__ . '/store/settings.json';
if (file_exists($contactSettingsFile)) {
    $savedContactSettings = json_decode((string) file_get_contents($contactSettingsFile), true);
    if (is_array($savedContactSettings)) {
        $contactSettings = array_merge($contactSettings, $savedContactSettings);
    }
}
$contactPhoneHref = preg_replace('/[^0-9+]/', '', $contactSettings['store_phone']);
$formValues = [
    'name' => '',
    'phone' => '',
    'email' => '',
    'message' => ''
];
$contactNotice = '';
$contactError = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formValues['name'] = trim((string) ($_POST['name'] ?? ''));
    $formValues['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $formValues['email'] = trim((string) ($_POST['email'] ?? ''));
    $formValues['message'] = trim((string) ($_POST['message'] ?? ''));
    $mailConfig = appMailConfig();
    $recipientEmail = filter_var($contactSettings['store_email'], FILTER_VALIDATE_EMAIL)
        ? $contactSettings['store_email']
        : ($mailConfig['from_email'] ?? '');
    $ccRecipients = [];
    $smtpInboxEmail = $mailConfig['from_email'] ?? '';

    if (
        filter_var($smtpInboxEmail, FILTER_VALIDATE_EMAIL)
        && strtolower($smtpInboxEmail) !== strtolower((string) $recipientEmail)
    ) {
        $ccRecipients[] = [
            'email' => $smtpInboxEmail,
            'name' => $mailConfig['from_name'] ?? $contactSettings['store_name']
        ];
    }

    if ($formValues['name'] === '' || $formValues['phone'] === '' || $formValues['email'] === '' || $formValues['message'] === '') {
        $contactError = 'Please complete all fields before sending your message.';
    } elseif (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
        $contactError = 'Please enter a valid email address.';
    } elseif (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $contactError = 'The store email is not configured yet. Please call or text us instead.';
    } else {
        $replyTo = filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)
            ? ['email' => $formValues['email'], 'name' => $formValues['name']]
            : [];
        $submittedAt = date('M d, Y h:i A');
        $subject = "New contact message from {$formValues['name']}";
        $safeName = htmlspecialchars($formValues['name'], ENT_QUOTES, 'UTF-8');
        $safePhone = htmlspecialchars($formValues['phone'], ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($formValues['email'], ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($formValues['message'], ENT_QUOTES, 'UTF-8'));
        $safeSubmittedAt = htmlspecialchars($submittedAt, ENT_QUOTES, 'UTF-8');
        $logoHtml = appMailLogoHtml(170);
        $htmlBody = "
            <div class=\"email-wrap\" style=\"margin:0;padding:24px 14px;background:#f7fbf5;font-family:Arial,Helvetica,sans-serif;color:#111827;\">
                <div class=\"email-container\" style=\"max-width:640px;margin:0 auto;background:#fbfdf9;border:1px solid #e0eadb;border-radius:12px;padding:18px;box-shadow:0 12px 28px rgba(18,88,39,0.08);\">
                    <div class=\"email-logo\" style=\"text-align:center;margin:0 0 16px;\">{$logoHtml}</div>

                    <div class=\"email-card\" style=\"background:#ffffff;border:1px solid #dfe7dc;border-radius:12px;padding:22px;\">
                        <table class=\"email-stack\" role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" style=\"border-collapse:collapse;\">
                            <tr>
                                <td width=\"82\" valign=\"top\" style=\"padding:0 20px 20px 0;\">
                                    <div style=\"width:58px;height:58px;border-radius:14px;background:#e9f7ed;color:#125827;text-align:center;line-height:58px;font-size:27px;font-weight:800;\">&#9993;</div>
                                </td>
                                <td valign=\"top\" style=\"padding:0 0 20px;\">
                                    <h1 class=\"email-title\" style=\"margin:0 0 6px;color:#00521f;font-size:24px;line-height:1.15;font-weight:900;\">New Contact Message</h1>
                                    <p class=\"email-copy\" style=\"margin:0;color:#374151;font-size:14px;line-height:1.5;\">You received a new website contact message.</p>
                                </td>
                            </tr>
                        </table>

                        <div style=\"height:1px;background:#dfe7dc;margin:0 0 20px;\"></div>

                        <table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" style=\"border-collapse:collapse;\">
                            <tr class=\"email-detail-row\">
                                <td class=\"email-detail-icon\" width=\"54\" valign=\"top\" style=\"padding:0 14px 14px 0;\">
                                    <div style=\"width:42px;height:42px;border-radius:10px;background:#e9f7ed;color:#125827;text-align:center;line-height:42px;font-size:20px;\">&#9679;</div>
                                </td>
                                <td style=\"padding:0 0 14px;border-bottom:1px dashed #e2e8df;\">
                                    <strong style=\"display:block;color:#111827;font-size:13px;margin-bottom:6px;\">Name</strong>
                                    <span style=\"display:block;color:#00521f;font-size:15px;font-weight:900;word-break:break-word;\">{$safeName}</span>
                                </td>
                            </tr>
                            <tr class=\"email-detail-row\">
                                <td class=\"email-detail-icon\" width=\"54\" valign=\"top\" style=\"padding:14px 14px 14px 0;\">
                                    <div style=\"width:42px;height:42px;border-radius:10px;background:#e9f7ed;color:#125827;text-align:center;line-height:42px;font-size:20px;\">&#9993;</div>
                                </td>
                                <td style=\"padding:14px 0;border-bottom:1px dashed #e2e8df;\">
                                    <strong style=\"display:block;color:#111827;font-size:13px;margin-bottom:6px;\">Email</strong>
                                    <a href=\"mailto:{$safeEmail}\" style=\"color:#2563eb;font-size:15px;font-weight:700;text-decoration:underline;word-break:break-word;\">{$safeEmail}</a>
                                </td>
                            </tr>
                            <tr class=\"email-detail-row\">
                                <td class=\"email-detail-icon\" width=\"54\" valign=\"top\" style=\"padding:14px 14px 14px 0;\">
                                    <div style=\"width:42px;height:42px;border-radius:10px;background:#e9f7ed;color:#125827;text-align:center;line-height:42px;font-size:20px;\">&#9742;</div>
                                </td>
                                <td style=\"padding:14px 0;border-bottom:1px dashed #e2e8df;\">
                                    <strong style=\"display:block;color:#111827;font-size:13px;margin-bottom:6px;\">Phone</strong>
                                    <span style=\"display:block;color:#111827;font-size:15px;font-weight:700;word-break:break-word;\">{$safePhone}</span>
                                </td>
                            </tr>
                            <tr class=\"email-detail-row\">
                                <td class=\"email-detail-icon\" width=\"54\" valign=\"top\" style=\"padding:14px 14px 18px 0;\">
                                    <div style=\"width:42px;height:42px;border-radius:10px;background:#e9f7ed;color:#125827;text-align:center;line-height:42px;font-size:20px;\">&#9635;</div>
                                </td>
                                <td style=\"padding:14px 0 18px;\">
                                    <strong style=\"display:block;color:#111827;font-size:13px;margin-bottom:6px;\">Submitted</strong>
                                    <span style=\"display:block;color:#111827;font-size:15px;word-break:break-word;\">{$safeSubmittedAt}</span>
                                </td>
                            </tr>
                        </table>

                        <div style=\"height:1px;background:#dfe7dc;margin:4px 0 18px;\"></div>

                        <div style=\"display:inline-block;background:#e9f7ed;color:#125827;border-radius:8px;padding:8px 12px;font-size:13px;font-weight:900;margin:0 0 12px;\">&#128172; Message</div>
                        <div class=\"email-copy\" style=\"border:1px solid #dfe7dc;border-radius:8px;padding:18px;background:#fff;color:#111827;font-size:15px;line-height:1.6;\">
                            <div style=\"color:#125827;font-size:28px;font-weight:900;line-height:1;margin-bottom:12px;\">&ldquo;</div>
                            <div style=\"word-break:break-word;\">{$safeMessage}</div>
                            <div style=\"color:#125827;font-size:28px;font-weight:900;line-height:1;text-align:right;margin-top:12px;\">&rdquo;</div>
                        </div>

                        <div class=\"email-copy\" style=\"margin-top:20px;border:1px solid #dfe7dc;border-radius:8px;background:#fbfdf9;padding:14px 16px;color:#374151;font-size:13px;line-height:1.5;\">
                            <table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" style=\"border-collapse:collapse;\">
                                <tr>
                                    <td width=\"50\" valign=\"top\">
                                        <div style=\"width:38px;height:38px;border-radius:50%;background:#e1f3e5;color:#125827;text-align:center;line-height:38px;font-size:18px;\">&#9993;</div>
                                    </td>
                                    <td>
                                        Please respond to this message at your earliest convenience.<br>
                                        Thank you!
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div style=\"height:3px;background:#125827;margin:0 0 14px;\"></div>
                    <table class=\"email-footer-stack\" role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" style=\"border-collapse:collapse;\">
                        <tr>
                            <td style=\"color:#125827;font-size:13px;font-weight:800;padding:4px 8px;\">&#127793; Fresh ingredients. Made with love. Delivered to you.</td>
                            <td class=\"email-footer-social\" align=\"right\" style=\"padding:4px 8px;white-space:nowrap;\">
                                <span style=\"display:inline-block;width:30px;height:30px;border-radius:50%;background:#0f7a34;color:#fff;text-align:center;line-height:30px;font-weight:900;margin-left:8px;\">f</span>
                                <span style=\"display:inline-block;width:30px;height:30px;border-radius:50%;background:#0f7a34;color:#fff;text-align:center;line-height:30px;font-weight:900;margin-left:8px;\">ig</span>
                                <span style=\"display:inline-block;width:30px;height:30px;border-radius:50%;background:#0f7a34;color:#fff;text-align:center;line-height:30px;font-weight:900;margin-left:8px;\">@</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        ";
        $plainBody = "New Contact Message\n\n"
            . "Name: {$formValues['name']}\n"
            . "Phone: {$formValues['phone']}\n"
            . "Email: " . ($formValues['email'] !== '' ? $formValues['email'] : 'Not provided') . "\n"
            . "Submitted: {$submittedAt}\n\n"
            . $formValues['message'];

        if (sendAppMail($recipientEmail, $contactSettings['store_name'], $subject, $htmlBody, $plainBody, [], $replyTo, $ccRecipients)) {
            $contactNotice = 'Your message was sent. We will get back to you soon.';
            $formValues = ['name' => '', 'phone' => '', 'email' => '', 'message' => ''];
        } else {
            $contactError = 'Sorry, your message could not be sent right now. Please try again later.';
        }
    }
}

$pageTitle = "Contact Us | J&J's Kitchenette";
$pageCSS = "contact.css";

include('store/includes/header.php');
?>

<main class="info-page">
    <section class="info-hero">
        <div class="info-container info-hero__inner">
            <p class="info-eyebrow">Contact <?= htmlspecialchars($contactSettings['store_name'], ENT_QUOTES, 'UTF-8') ?></p>
            <h1>We are here to help with orders, pickups, and delivery questions.</h1>
            <p>
                Reach out to the store using the details below, or visit our location page to find us in <?= htmlspecialchars($contactSettings['store_area'], ENT_QUOTES, 'UTF-8') ?>.
            </p>
        </div>
    </section>

    <section class="info-container info-grid" aria-label="Contact details">
        <article class="info-card">
            <i class="fas fa-phone"></i>
            <div>
                <h2>Call or Text</h2>
                <p><?= htmlspecialchars($contactSettings['store_phone'], ENT_QUOTES, 'UTF-8') ?></p>
                <a href="tel:<?= htmlspecialchars($contactPhoneHref, ENT_QUOTES, 'UTF-8') ?>">Call now</a>
            </div>
        </article>

        <article class="info-card">
            <i class="fas fa-envelope"></i>
            <div>
                <h2>Email</h2>
                <p><?= htmlspecialchars($contactSettings['store_email'], ENT_QUOTES, 'UTF-8') ?></p>
                <a href="mailto:<?= htmlspecialchars($contactSettings['store_email'], ENT_QUOTES, 'UTF-8') ?>">Send email</a>
            </div>
        </article>

        <article class="info-card">
            <i class="fas fa-location-dot"></i>
            <div>
                <h2>Store Area</h2>
                <p><?= htmlspecialchars($contactSettings['store_area'], ENT_QUOTES, 'UTF-8') ?></p>
                <a href="/location.php">View location</a>
            </div>
        </article>
    </section>

    <section class="info-container contact-panel">
        <div>
            <p class="info-eyebrow">Quick message</p>
            <h2>Tell us what you need.</h2>
            <p>
                For faster assistance, include your order number if you already placed an order.
            </p>
        </div>

        <form class="contact-form" action="/contact.php" method="POST">
            <?php if ($contactNotice !== '') { ?>
                <div class="contact-alert contact-alert--success">
                    <i class="fas fa-circle-check"></i>
                    <?= htmlspecialchars($contactNotice, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php } ?>

            <?php if ($contactError !== '') { ?>
                <div class="contact-alert contact-alert--error">
                    <i class="fas fa-circle-exclamation"></i>
                    <?= htmlspecialchars($contactError, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php } ?>

            <label>
                Name
                <input type="text" name="name" value="<?= htmlspecialchars($formValues['name'], ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label>
                Phone
                <input type="tel" name="phone" value="<?= htmlspecialchars($formValues['phone'], ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label>
                Email
                <input type="email" name="email" value="<?= htmlspecialchars($formValues['email'], ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label>
                Message
                <textarea name="message" rows="5" required><?= htmlspecialchars($formValues['message'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>

            <button type="submit">
                <i class="fas fa-paper-plane"></i>
                Send Message
            </button>
        </form>
    </section>
</main>

<?php include('store/includes/footer.php'); ?>
