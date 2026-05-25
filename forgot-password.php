<?php
session_start();
include __DIR__ . '/db.php';
include __DIR__ . '/includes/password_reset.php';

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
        } elseif (!resetPasswordWithCode($conn, $email, $code, $newPassword, ['customer'])) {
            $message = 'Invalid or expired verification code.';
            $messageType = 'error';
        } else {
            $message = 'Password updated. You can now sign in.';
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
                $message = 'No account was found with that email address. Please create an account first.';
                $messageType = 'error';
                $messageLink = [
                    'href' => '/jj_kitchenette/account',
                    'label' => 'Create account'
                ];
            } elseif ($roleName !== 'customer') {
                $message = 'This email is registered as an admin/staff account. Please reset it from the admin password reset page.';
                $messageType = 'error';
                $messageLink = [
                    'href' => '/jj_kitchenette/store/forgot-password.php?email=' . urlencode($email),
                    'label' => 'Go to admin reset'
                ];
            } elseif (sendPasswordResetCode($conn, $user)) {
                $message = 'A verification code has been sent to your email.';
                $messageType = 'success';
                $showResetForm = true;
            } else {
                $message = 'Unable to send the verification code. Please check your mail settings.';
                $messageType = 'error';
            }
        }
    }
}

$pageTitle = ($showResetForm ? "Create New Password" : "Forgot Password") . " | J&J's Kitchenette";
$pageCSS = "account.css";
include('store/includes/header.php');
?>

<div class="account-container">
    <div class="account-card">
        <div class="account-card-header">
            <h2>
                <?= $showResetForm ? 'Create New Password' : 'Reset Password' ?>
                <i class="fas <?= $showResetForm ? 'fa-shield-halved' : 'fa-key' ?>"></i>
            </h2>
            <p>
                <?= $showResetForm
                    ? 'Enter the verification code from your email.'
                    : 'Enter your email and we will send a verification code.' ?>
            </p>
        </div>

        <?php if ($message): ?>
            <div class="account-message <?= $messageType === 'error' ? 'account-message--error' : '' ?>">
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
            <form method="POST" class="account-form">
                <input type="hidden" name="form_action" value="reset_password">

                <label>
                    Email Address
                    <span class="account-input">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter your email" required>
                    </span>
                </label>

                <label>
                    Verification Code
                    <span class="account-input">
                        <i class="fas fa-hashtag"></i>
                        <input type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="6-digit code" data-number-only required>
                    </span>
                </label>

                <label>
                    New Password
                    <span class="account-input account-input--password">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="new_password" minlength="8" placeholder="Create new password" required>
                        <button type="button" class="account-password-hold-toggle" data-password-toggle="new_password" aria-label="Hold to show new password">
                            <i class="far fa-eye"></i>
                        </button>
                    </span>
                </label>

                <label>
                    Confirm New Password
                    <span class="account-input account-input--password">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="confirm_password" minlength="8" placeholder="Confirm new password" required>
                        <button type="button" class="account-password-hold-toggle" data-password-toggle="confirm_password" aria-label="Hold to show confirm new password">
                            <i class="far fa-eye"></i>
                        </button>
                    </span>
                </label>

                <button type="submit">Update Password</button>
            </form>

            <div class="account-footer">
                Need a new code?
                <a href="/jj_kitchenette/forgot-password.php<?= $email !== '' ? '?email=' . urlencode($email) : '' ?>">Send another code</a>
            </div>
        <?php else: ?>
            <form method="POST" class="account-form">
                <input type="hidden" name="form_action" value="send_code">

                <label>
                    Email Address
                    <span class="account-input">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter your email" required>
                    </span>
                </label>

                <button type="submit">Send Verification Code</button>
            </form>

            <div class="account-footer">
                Already have a code?
                <a href="/jj_kitchenette/forgot-password.php?mode=reset<?= $email !== '' ? '&email=' . urlencode($email) : '' ?>">Create new password</a>
            </div>
        <?php endif; ?>
    </div>
</div>

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
</script>

<?php include('store/includes/footer.php'); ?>
