<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /account/profile");
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // CUSTOMER ROLE ID
    $roleQuery = $conn->prepare("
        SELECT id FROM roles
        WHERE role_name = 'customer'
    ");

    $roleQuery->execute();

    $roleResult = $roleQuery->get_result();
    $role = $roleResult->fetch_assoc();

    $customerRoleId = $role['id'];

    // CHECK IF EMAIL EXISTS
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($firstName === '' || $lastName === '') {

        $message = "First name and last name are required.";

    } elseif ($result->num_rows > 0) {

        $message = "Email already registered.";

    } else {

        // HASH PASSWORD
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // INSERT USER
        $stmt = $conn->prepare("
            INSERT INTO users (
                first_name,
                last_name,
                email,
                password,
                role_id
            ) VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssi",
            $firstName,
            $lastName,
            $email,
            $hashedPassword,
            $customerRoleId
        );

        if ($stmt->execute()) {

            $userId = $stmt->insert_id;

            // OPTIONAL AUTO LOGIN
            $_SESSION['user_id'] = $userId;
            $_SESSION['role_id'] = $customerRoleId;
            $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);

            header("Location: /account/profile");
            exit;

        } else {

            $message = "Something went wrong.";
        }
    }
}
$pageTitle = "Create Account | J&J's Kitchenette";
$pageCSS = "account.css";
include('store/includes/header.php');
?>

    <div class="account-container">

        <div class="account-card">

            <div class="account-card-header">
                <h2>Create Account <i class="fas fa-leaf"></i></h2>
                <p>Join J&J's Kitchenette and start ordering favorites faster.</p>
            </div>

            <?php if ($message): ?>
                <div class="account-message">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="account-form">

                <label>
                    First Name
                    <span class="account-input">
                        <i class="fas fa-user"></i>
                        <input type="text" name="first_name" placeholder="Enter your first name" required>
                    </span>
                </label>

                <label>
                    Last Name
                    <span class="account-input">
                        <i class="fas fa-user"></i>
                        <input type="text" name="last_name" placeholder="Enter your last name" required>
                    </span>
                </label>

                <label>
                    Email Address
                    <span class="account-input">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" placeholder="Enter your email" required>
                    </span>
                </label>

                <label>
                    Password
                    <span class="account-input">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="Create a password" required>
                    </span>
                </label>

                <button type="submit">
                    Create Account
                </button>

            </form>

            <div class="account-footer">
                Already have an account?
                <a href="/login">Login</a>
            </div>

        </div>

    </div>

<?php include('store/includes/footer.php'); ?>
