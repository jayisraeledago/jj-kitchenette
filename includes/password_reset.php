<?php

require_once __DIR__ . '/mailer.php';

function ensurePasswordResetTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS password_reset_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (email),
            INDEX (expires_at)
        )
    ");
}

function sendPasswordResetCode(mysqli $conn, array $user): bool
{
    ensurePasswordResetTable($conn);

    $userId = (int) $user['id'];
    $email = trim((string) ($user['email'] ?? ''));
    $name = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    $displayName = $name !== '' ? $name : $email;
    $code = (string) random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    $expireStmt = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $expireStmt->bind_param("i", $userId);
    $expireStmt->execute();

    $insertStmt = $conn->prepare("
        INSERT INTO password_reset_codes (user_id, email, code_hash, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
    ");
    $insertStmt->bind_param("iss", $userId, $email, $codeHash);
    $insertStmt->execute();

    $safeName = htmlspecialchars(strtoupper($displayName), ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $logoHtml = appMailLogoHtml(170);

    $html = <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f5f8f3;font-family:Arial,Helvetica,sans-serif;color:#172018;">
    <div class="email-wrap" style="padding:24px 14px;">
        <div class="email-container" style="max-width:620px;margin:0 auto;background:#fbfdf9;border:1px solid #e1eadf;border-radius:12px;box-shadow:0 12px 28px rgba(18,88,39,0.08);overflow:hidden;">
            <div class="email-logo" style="padding:22px 20px 8px;text-align:center;">
                {$logoHtml}
            </div>

            <div class="email-card" style="margin:0 22px 22px;background:#ffffff;border-radius:12px;box-shadow:0 10px 24px rgba(17,24,39,0.06);padding:24px 28px;">
                <table class="email-stack" role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                    <tr>
                        <td style="width:78px;vertical-align:top;">
                            <div style="width:58px;height:58px;border-radius:50%;background:#e8f3ea;text-align:center;line-height:58px;color:#125827;font-size:28px;font-weight:700;">&#128274;</div>
                        </td>
                        <td style="vertical-align:top;">
                            <h1 class="email-title" style="margin:4px 0 10px;font-size:26px;line-height:1.15;color:#1b1f1d;font-weight:800;">Password reset code</h1>
                            <div style="width:52px;height:4px;background:#125827;border-radius:999px;margin:0 0 20px;"></div>
                        </td>
                    </tr>
                </table>

                <p class="email-copy" style="margin:0 0 12px;font-size:15px;line-height:1.5;">Hello <strong style="color:#125827;">{$safeName}</strong>,</p>
                <p class="email-copy" style="margin:0 0 6px;font-size:15px;line-height:1.5;">We received a request to reset your password.</p>
                <p class="email-copy" style="margin:0 0 18px;font-size:15px;line-height:1.5;">Use this code to create your new password.</p>

                <div style="background:#f0f8ef;border:2px dashed #b9d8b9;border-radius:10px;padding:18px 12px;text-align:center;margin:0 0 18px;">
                    <div class="email-code" style="font-size:40px;line-height:1;letter-spacing:12px;color:#125827;font-weight:800;">{$safeCode}</div>
                </div>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f8fbf7;border:1px solid #dfeadf;border-radius:10px;margin:0 0 30px;">
                    <tr>
                        <td style="width:70px;padding:18px 0 18px 20px;vertical-align:middle;">
                            <div style="width:42px;height:42px;border-radius:50%;border:4px solid #125827;color:#125827;text-align:center;line-height:36px;font-size:24px;">&#9201;</div>
                        </td>
                        <td style="padding:18px 20px 18px 0;font-size:15px;line-height:1.5;color:#1f2937;">
                            This code will expire in <strong style="color:#125827;">15 minutes</strong>.<br>
                            If you did not request this, you can safely ignore this email.
                        </td>
                    </tr>
                </table>

                <div style="border-top:1px solid #dfe5da;padding-top:22px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                        <tr>
                            <td style="width:58px;vertical-align:middle;">
                                <div style="width:44px;height:44px;border-radius:50%;background:#e8f3ea;color:#125827;text-align:center;line-height:44px;font-size:24px;">&#127793;</div>
                            </td>
                            <td style="vertical-align:middle;">
                                <p style="margin:0;color:#125827;font-size:14px;font-weight:800;">Fresh ingredients. Made with love. Delivered to you.</p>
                                <p style="margin:4px 0 0;color:#1f2937;font-size:14px;">Thank you for choosing J&amp;J&apos;s Kitchenette.</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="email-footer" style="background:#eef6ee;padding:14px 20px;text-align:center;color:#5f6f64;font-size:12px;">
                &copy; 2026 J&amp;J&apos;s Kitchenette. All rights reserved.
                <span style="display:inline-block;margin:0 14px;color:#a8b7aa;">|</span>
                This is an automated email. Please do not reply.
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    $plain = "Your J&J's Kitchenette password reset code is {$code}. It expires in 15 minutes.";

    return sendAppMail($email, $displayName, "J&J's Kitchenette password reset code", $html, $plain);
}

function sendStaffInvitationCode(mysqli $conn, array $user, string $roleName): bool
{
    ensurePasswordResetTable($conn);

    $userId = (int) $user['id'];
    $email = trim((string) ($user['email'] ?? ''));
    $name = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    $displayName = $name !== '' ? $name : $email;
    $code = (string) random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    $expireStmt = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $expireStmt->bind_param("i", $userId);
    $expireStmt->execute();

    $insertStmt = $conn->prepare("
        INSERT INTO password_reset_codes (user_id, email, code_hash, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
    ");
    $insertStmt->bind_param("iss", $userId, $email, $codeHash);
    $insertStmt->execute();

    $safeName = htmlspecialchars(strtoupper($displayName), ENT_QUOTES, 'UTF-8');
    $safeRole = htmlspecialchars(ucfirst($roleName), ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $baseUrl = appPublicUrl();
    $setupUrl = ($baseUrl !== '' ? $baseUrl : 'https://jj-kitchenette.onrender.com') . '/store/reset-password.php?email=' . urlencode($email);
    $safeSetupUrl = htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8');
    $logoHtml = appMailLogoHtml(170);

    $html = <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f5f8f3;font-family:Arial,Helvetica,sans-serif;color:#172018;">
    <div class="email-wrap" style="padding:24px 14px;">
        <div class="email-container" style="max-width:620px;margin:0 auto;background:#fbfdf9;border:1px solid #e1eadf;border-radius:12px;box-shadow:0 12px 28px rgba(18,88,39,0.08);overflow:hidden;">
            <div class="email-logo" style="padding:22px 20px 8px;text-align:center;">{$logoHtml}</div>
            <div class="email-card" style="margin:0 22px 22px;background:#ffffff;border-radius:12px;box-shadow:0 10px 24px rgba(17,24,39,0.06);padding:24px 28px;">
                <h1 class="email-title" style="margin:0 0 10px;font-size:26px;line-height:1.15;color:#1b1f1d;font-weight:800;">You&apos;re invited</h1>
                <div style="width:52px;height:4px;background:#125827;border-radius:999px;margin:0 0 20px;"></div>
                <p class="email-copy" style="margin:0 0 12px;font-size:15px;line-height:1.5;">Hello <strong style="color:#125827;">{$safeName}</strong>,</p>
                <p class="email-copy" style="margin:0 0 6px;font-size:15px;line-height:1.5;">You were invited as <strong>{$safeRole}</strong> for J&amp;J&apos;s Kitchenette.</p>
                <p class="email-copy" style="margin:0 0 18px;font-size:15px;line-height:1.5;">Use this code to create your password.</p>
                <div style="background:#f0f8ef;border:2px dashed #b9d8b9;border-radius:10px;padding:18px 12px;text-align:center;margin:0 0 18px;">
                    <div class="email-code" style="font-size:40px;line-height:1;letter-spacing:12px;color:#125827;font-weight:800;">{$safeCode}</div>
                </div>
                <p style="margin:0 0 18px;font-size:15px;line-height:1.55;">Setup page: <a href="{$safeSetupUrl}" style="color:#125827;font-weight:700;">{$safeSetupUrl}</a></p>
                <div style="background:#f8fbf7;border:1px solid #dfeadf;border-radius:10px;padding:18px 20px;font-size:15px;line-height:1.5;color:#1f2937;">
                    This code will expire in <strong style="color:#125827;">15 minutes</strong>. If you were not expecting this invitation, you can ignore this email.
                </div>
            </div>
            <div class="email-footer" style="background:#eef6ee;padding:14px 20px;text-align:center;color:#5f6f64;font-size:12px;">
                &copy; 2026 J&amp;J&apos;s Kitchenette. This is an automated email. Please do not reply.
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    $plain = "You were invited as {$roleName} for J&J's Kitchenette. Use code {$code} to create your password at {$setupUrl}. It expires in 15 minutes.";

    return sendAppMail($email, $displayName, "J&J's Kitchenette staff invitation", $html, $plain);
}

function findUserByEmail(mysqli $conn, string $email, array $allowedRoles = []): ?array
{
    $roleSql = '';
    $types = 's';
    $params = [$email];

    if (!empty($allowedRoles)) {
        $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
        $roleSql = "AND r.role_name IN ({$placeholders})";
        $types .= str_repeat('s', count($allowedRoles));
        $params = array_merge($params, $allowedRoles);
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, r.role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.email = ?
        {$roleSql}
        LIMIT 1
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    return $user ?: null;
}

function resetPasswordWithCode(mysqli $conn, string $email, string $code, string $newPassword, array $allowedRoles = []): bool
{
    ensurePasswordResetTable($conn);

    $roleSql = '';
    $types = 's';
    $params = [$email];

    if (!empty($allowedRoles)) {
        $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
        $roleSql = "AND r.role_name IN ({$placeholders})";
        $types .= str_repeat('s', count($allowedRoles));
        $params = array_merge($params, $allowedRoles);
    }

    $stmt = $conn->prepare("
        SELECT prc.id, prc.user_id, prc.code_hash
        FROM password_reset_codes prc
        LEFT JOIN users u ON u.id = prc.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE prc.email = ?
        {$roleSql}
        AND prc.used_at IS NULL
        AND prc.expires_at >= NOW()
        ORDER BY prc.created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();

    if (!$reset || !password_verify($code, $reset['code_hash'])) {
        return false;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $userId = (int) $reset['user_id'];
    $resetId = (int) $reset['id'];

    $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $updateStmt->bind_param("si", $hashedPassword, $userId);
    $updateStmt->execute();

    $usedStmt = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?");
    $usedStmt->bind_param("i", $resetId);
    $usedStmt->execute();

    return true;
}
