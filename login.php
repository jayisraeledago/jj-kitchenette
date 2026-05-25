<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';


// IF USER IS ALREADY LOGGED IN
if (isset($_SESSION['user_id'])) {
    if (in_array($_SESSION['role_name'] ?? '', ['admin', 'staff'], true)) {
        header("Location: /store/login.php");
        exit;
    }

    header("Location: /account/profile");
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // FIND USER
    $stmt = $conn->prepare("
        SELECT u.*, r.role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.email = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $user = $result->fetch_assoc();

        // VERIFY PASSWORD
        if (password_verify($password, $user['password'])) {

            // CHECK STATUS
            if ($user['status'] !== 'active') {

                $message = "Account is disabled.";

            } else {
                if (in_array($user['role_name'], ['admin', 'staff'], true)) {
                    $message = "Please use the staff/admin login page.";
                } else {

                    // LOGIN SESSION
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['email'];
                    $_SESSION['role_name'] = $user['role_name'];

                    header("Location: /account/profile");
                    exit;
                }
            }

        } else {

            $message = "Invalid email or password.";
        }

    } else {

        $message = "Invalid email or password.";
    }
}
$pageTitle = "Login | J&J's Kitchenette";
$pageCSS = "account.css";
include('store/includes/header.php');
?>

    <div class="account-container">

        <div class="account-card">

            <div class="account-card-header">
                <h2>Welcome Back <i class="fas fa-leaf"></i></h2>
                <p>Login to continue to your account</p>
            </div>

            <?php if ($message): ?>
                <div class="account-message">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="account-form">

                <label>
                    Email Address
                    <span class="account-input">
                        <i class="fas fa-envelope"></i>
                        <input
                            type="email"
                            name="email"
                            placeholder="Enter your email"
                            required
                        >
                    </span>
                </label>

                <label>
                    Password
                    <span class="account-input">
                        <i class="fas fa-lock"></i>
                        <input
                            type="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                        >
                    </span>
                </label>

                <button type="submit">
                    Login
                </button>

            </form>

            <div class="account-footer">
                <div class="account-footer__row account-footer__row--primary">
                    <a href="/forgot-password.php">
                        Forgot password?
                    </a>
                </div>
                <div class="account-footer__row">
                    <span>Don't have an account?</span>
                    <a href="/account">
                        Create Account
                    </a>
                </div>
            </div>

        </div>

    </div>

<?php include('store/includes/footer.php'); ?>
