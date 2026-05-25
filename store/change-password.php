<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/../includes/password_reset.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminPermission($conn);

$message = '';
$messageType = '';
$currentRole = $_SESSION['role_name'] ?? '';
$users = [];

if ($currentRole === 'admin') {
    $usersResult = $conn->query("
        SELECT u.id, u.first_name, u.last_name, u.email, r.role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY r.role_name ASC, u.first_name ASC, u.last_name ASC, u.email ASC
    ");

    while ($row = $usersResult->fetch_assoc()) {
        $users[] = $row;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formAction = $_POST['form_action'] ?? 'change_own_password';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($formAction === 'send_reset_code') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);

        $targetStmt = $conn->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email
            FROM users u
            WHERE u.id = ?
            LIMIT 1
        ");
        $targetStmt->bind_param("i", $targetUserId);
        $targetStmt->execute();
        $targetUser = $targetStmt->get_result()->fetch_assoc();

        if ($currentRole !== 'admin') {
            $message = 'Only admins can reset another user password.';
            $messageType = 'error';
        } elseif (!$targetUser) {
            $message = 'Please select a valid user.';
            $messageType = 'error';
        } else {
            $targetName = trim(($targetUser['first_name'] ?? '') . ' ' . ($targetUser['last_name'] ?? ''));
            $targetLabel = $targetName !== '' ? $targetName : ($targetUser['email'] ?? 'selected user');

            if (sendPasswordResetCode($conn, $targetUser)) {
                $message = "Verification code sent to {$targetLabel}.";
                $messageType = 'success';
            } else {
                $message = 'Unable to send the verification code. Please check your mail settings.';
                $messageType = 'error';
            }
        }
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'New password must be at least 8 characters.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New password and confirmation do not match.';
            $messageType = 'error';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->bind_param("si", $hashedPassword, $userId);
            $updateStmt->execute();

            $message = 'Password updated successfully.';
            $messageType = 'success';
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | J&J's Kitchenette</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main">
            <div class="page-container admin-account-page">
                <div class="settings-page-top">
                    <div>
                        <span class="settings-eyebrow">Account Security</span>
                        <h1>Change Password</h1>
                        <p>Update the password for your admin or staff account.</p>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="settings-alert <?= $messageType === 'error' ? 'settings-alert--error' : '' ?>">
                        <i class="fa-solid <?= $messageType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="settings-card admin-password-card">
                    <input type="hidden" name="form_action" value="change_own_password">
                    <div class="settings-card__header">
                        <i class="fa-solid fa-key"></i>
                        <div>
                            <h2>Password</h2>
                            <p>Use at least 8 characters for the new password.</p>
                        </div>
                    </div>

                    <div class="settings-grid">
                        <label>
                            <span>Current Password</span>
                            <input type="password" name="current_password" autocomplete="current-password" required>
                        </label>

                        <label>
                            <span>New Password</span>
                            <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                        </label>

                        <label>
                            <span>Confirm New Password</span>
                            <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                        </label>
                    </div>

                    <div class="settings-actions admin-password-actions">
                        <a href="dashboard.php">Cancel</a>
                        <button type="submit">
                            <i class="fa-solid fa-key"></i>
                            Update Password
                        </button>
                    </div>
                </form>

                <?php if ($currentRole === 'admin'): ?>
                    <form method="POST" class="settings-card admin-password-card admin-password-reset-card">
                        <input type="hidden" name="form_action" value="send_reset_code">
                        <div class="settings-card__header">
                            <i class="fa-solid fa-user-lock"></i>
                            <div>
                                <h2>Send Password Reset Code</h2>
                                <p>Email a verification code so the user can create their own new password.</p>
                            </div>
                        </div>

                        <div class="settings-grid">
                            <label>
                                <span>User</span>
                                <select name="target_user_id" required>
                                    <option value="">Select user</option>
                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                                        $label = $fullName !== '' ? $fullName : ($user['email'] ?? 'User');
                                        $role = ucfirst($user['role_name'] ?? 'user');
                                        ?>
                                        <option value="<?= (int) $user['id'] ?>">
                                            <?= htmlspecialchars($label . ' - ' . ($user['email'] ?? '') . ' (' . $role . ')', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="settings-actions admin-password-actions">
                            <button type="submit">
                                <i class="fa-regular fa-paper-plane"></i>
                                Send Reset Code
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>
