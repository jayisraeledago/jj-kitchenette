<?php
session_start();
include __DIR__ . '/../db.php';
include __DIR__ . '/../includes/password_reset.php';

$message = '';
$messageType = '';
$messageLink = null;
$email = trim($_POST['email'] ?? $_GET['email'] ?? '');
$showResetForm = ($_GET['mode'] ?? '') === 'reset';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formAction = $_POST['form_action'] ?? 'send_code';

    if ($formAction === 'reset_password') {
        $showResetForm = true;
        $code = trim($_POST['code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $message = 'Please enter the 6-digit verification code.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'New password must be at least 8 characters.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New password and confirmation do not match.';
            $messageType = 'error';
        } elseif (!resetPasswordWithCode($conn, $email, $code, $newPassword, ['admin', 'staff'])) {
            $message = 'Invalid or expired verification code.';
            $messageType = 'error';
        } else {
            $message = 'Password updated. You can now sign in to admin.';
            $messageType = 'success';
        }
    } else {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } else {
            $user = findUserByEmail($conn, $email);
            $roleName = $user['role_name'] ?? '';

            if (!$user) {
                $message = 'No account was found with that email address.';
                $messageType = 'error';
            } elseif (!in_array($roleName, ['admin', 'staff'], true)) {
                $message = 'This email is registered as a customer account. Please reset it from the customer password reset page.';
                $messageType = 'error';
                $messageLink = [
                    'href' => '/jj_kitchenette/forgot-password.php?email=' . urlencode($email),
                    'label' => 'Go to customer reset'
                ];
            } elseif (sendPasswordResetCode($conn, $user)) {
                $message = 'A verification code has been sent to your admin/staff email.';
                $messageType = 'success';
                $showResetForm = true;
            } else {
                $message = 'Unable to send the verification code. Please check your mail settings.';
                $messageType = 'error';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Forgot Admin Password | J&J's Kitchenette</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body class="admin-login-page">
    <main class="admin-login-shell">
        <section class="admin-login-card admin-login-card--compact <?= $showResetForm ? 'admin-login-card--reset' : '' ?>">
            <img src="/jj_kitchenette/assets/images/kitchenette-logo.svg" alt="J&J's Kitchenette">
            <h1><?= $showResetForm ? 'Create New Password' : 'Reset Admin Password' ?></h1>
            <p>
                <?= $showResetForm
                    ? 'Enter the verification code sent to your staff or admin email.'
                    : 'Enter your staff or admin email to receive a verification code.' ?>
            </p>

            <?php if ($message !== ''): ?>
                <div class="admin-login-message <?= $messageType === 'success' ? 'admin-login-message--success' : '' ?>">
                    <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <span>
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($messageLink): ?>
                            <a href="<?= htmlspecialchars($messageLink['href'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($messageLink['label'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($showResetForm): ?>
                <form method="POST" class="admin-login-form" id="resetPasswordForm" novalidate>
                    <input type="hidden" name="form_action" value="reset_password">

                    <label>
                        Email Address
                        <span>
                            <i class="fa-regular fa-envelope"></i>
                            <input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="admin@example.com" required>
                        </span>
                        <small class="field-error" data-error-for="email"></small>
                    </label>

                    <label>
                        Verification Code
                        <span>
                            <i class="fa-solid fa-hashtag"></i>
                            <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6-digit code" data-number-only required>
                        </span>
                        <small class="field-error" data-error-for="code"></small>
                    </label>

                    <label>
                        New Password
                        <span>
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="new_password" minlength="8" placeholder="Create new password" required>
                            <button type="button" class="password-hold-toggle" data-password-toggle="new_password" aria-label="Hold to show new password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </span>
                        <small class="field-error" data-error-for="new_password"></small>
                    </label>

                    <label>
                        Confirm New Password
                        <span>
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="confirm_password" minlength="8" placeholder="Confirm new password" required>
                            <button type="button" class="password-hold-toggle" data-password-toggle="confirm_password" aria-label="Hold to show confirm password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </span>
                        <small class="field-error" data-error-for="confirm_password"></small>
                    </label>

                    <button type="submit">
                        <i class="fa-solid fa-key"></i>
                        Update Password
                    </button>
                </form>

                <div class="admin-login-links">
                    <a href="/jj_kitchenette/store/forgot-password.php<?= $email !== '' ? '?email=' . urlencode($email) : '' ?>">Send another code</a>
                    <a href="/jj_kitchenette/store/login.php">Back to login</a>
                </div>
            <?php else: ?>
                <form method="POST" class="admin-login-form">
                    <input type="hidden" name="form_action" value="send_code">

                    <label>
                        Email Address
                        <span>
                            <i class="fa-regular fa-envelope"></i>
                            <input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="admin@example.com" required>
                        </span>
                    </label>

                    <button type="submit">
                        <i class="fa-regular fa-paper-plane"></i>
                        Send Verification Code
                    </button>
                </form>

                <div class="admin-login-links">
                    <a href="/jj_kitchenette/store/forgot-password.php?mode=reset<?= $email !== '' ? '&email=' . urlencode($email) : '' ?>">I have a code</a>
                    <a href="/jj_kitchenette/store/login.php">Back to login</a>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach(button => {
            const input = document.querySelector(`[name="${button.dataset.passwordToggle}"]`);
            const icon = button.querySelector('i');
            if (!input || !icon) return;

            const show = () => {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            };

            const hide = () => {
                input.type = 'password';
                icon.classList.add('fa-eye');
                icon.classList.remove('fa-eye-slash');
            };

            button.addEventListener('mousedown', show);
            button.addEventListener('touchstart', show, { passive: true });
            button.addEventListener('mouseup', hide);
            button.addEventListener('mouseleave', hide);
            button.addEventListener('touchend', hide);
            button.addEventListener('touchcancel', hide);
            button.addEventListener('blur', hide);
        });

        document.querySelectorAll('[data-number-only]').forEach(input => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 6);
            });
        });

        const resetPasswordForm = document.getElementById('resetPasswordForm');

        function setFieldError(name, message) {
            const input = resetPasswordForm?.querySelector(`[name="${name}"]`);
            const error = resetPasswordForm?.querySelector(`[data-error-for="${name}"]`);

            input?.closest('span')?.classList.toggle('has-error', message !== '');

            if (error) {
                error.textContent = message;
            }
        }

        resetPasswordForm?.addEventListener('submit', event => {
            const email = resetPasswordForm.email.value.trim();
            const code = resetPasswordForm.code.value.trim();
            const newPassword = resetPasswordForm.new_password.value;
            const confirmPassword = resetPasswordForm.confirm_password.value;
            let hasError = false;

            ['email', 'code', 'new_password', 'confirm_password'].forEach(name => setFieldError(name, ''));

            if (!email || !resetPasswordForm.email.checkValidity()) {
                setFieldError('email', 'Enter a valid email address.');
                hasError = true;
            }

            if (!/^\d{6}$/.test(code)) {
                setFieldError('code', 'Enter the 6-digit verification code.');
                hasError = true;
            }

            if (newPassword.length < 8) {
                setFieldError('new_password', 'Password must be at least 8 characters.');
                hasError = true;
            }

            if (confirmPassword.length < 8) {
                setFieldError('confirm_password', 'Confirm password must be at least 8 characters.');
                hasError = true;
            } else if (newPassword !== confirmPassword) {
                setFieldError('confirm_password', 'Passwords do not match.');
                hasError = true;
            }

            if (hasError) {
                event.preventDefault();
            }
        });
    </script>
</body>

</html>
