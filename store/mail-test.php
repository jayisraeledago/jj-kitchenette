<?php
include __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/admin-auth.php';
requireAdminOnly();
require_once __DIR__ . '/../includes/mailer.php';

$pageTitle = 'Mail Test';
$config = appMailConfig();
$message = '';
$messageType = '';

$userStmt = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE id = ? LIMIT 1");
$userStmt->bind_param("i", $_SESSION['user_id']);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc() ?: [];
$defaultEmail = trim((string) ($currentUser['email'] ?? ''));
$targetEmail = trim((string) ($_POST['email'] ?? $defaultEmail));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
        $messageType = 'error';
    } else {
        $displayName = trim((string) (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?: 'Admin';
        $html = '
            <div style="font-family:Arial,Helvetica,sans-serif;padding:24px;background:#f7fbf5;color:#172018;">
                <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #dfeadf;border-radius:12px;padding:24px;">
                    <h1 style="margin:0 0 10px;color:#125827;">J&amp;J Kitchenette Mail Test</h1>
                    <p style="margin:0 0 12px;line-height:1.5;">If you received this email, the production mail configuration is working.</p>
                    <p style="margin:0;color:#64748b;font-size:13px;">Sent at ' . htmlspecialchars(date('M d, Y h:i A'), ENT_QUOTES, 'UTF-8') . '</p>
                </div>
            </div>
        ';

        if (sendAppMail($targetEmail, $displayName, "J&J's Kitchenette mail test", $html, 'J&J Kitchenette mail test.')) {
            $message = 'Test email sent to ' . htmlspecialchars($targetEmail, ENT_QUOTES, 'UTF-8') . '.';
            $messageType = 'success';
        } else {
            $message = 'Test email failed. Check Render logs for the Mail error line.';
            $messageType = 'error';
        }
    }
}

$checks = [
    'BREVO_API_KEY' => !empty($config['brevo_api_key']),
    'MAIL_FROM_EMAIL' => !empty($config['from_email']),
    'PHP curl extension' => function_exists('curl_init'),
    'SMTP username' => !empty($config['username']),
    'SMTP password' => !empty($config['password']),
];

include __DIR__ . '/includes/header.php';
?>

<main class="admin-page">
    <section class="settings-card admin-password-card">
        <div class="settings-card__header">
            <i class="fas fa-envelope"></i>
            <div>
                <h2>Mail Test</h2>
                <p>Check production email configuration and send a test message.</p>
            </div>
        </div>

        <?php if ($message !== '') { ?>
            <div class="admin-alert admin-alert--<?= $messageType === 'success' ? 'success' : 'error' ?>">
                <?= $message ?>
            </div>
        <?php } ?>

        <div class="settings-grid">
            <?php foreach ($checks as $label => $ok) { ?>
                <div>
                    <label><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                    <strong><?= $ok ? 'Configured' : 'Missing' ?></strong>
                </div>
            <?php } ?>
        </div>

        <form method="POST" class="settings-grid" style="margin-top:18px;">
            <div>
                <label>Send test to</label>
                <input type="email" name="email" value="<?= htmlspecialchars($targetEmail, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="admin-password-actions">
                <button type="submit">
                    <i class="fas fa-paper-plane"></i>
                    Send Test Email
                </button>
            </div>
        </form>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
