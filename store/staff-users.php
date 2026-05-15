<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminOnly();

$savedMessage = '';
$errorMessage = '';

$conn->query("
    CREATE TABLE IF NOT EXISTS staff_permissions (
        user_id INT PRIMARY KEY,
        can_manage_inventory TINYINT(1) NOT NULL DEFAULT 0,
        can_manage_products TINYINT(1) NOT NULL DEFAULT 0,
        can_manage_categories TINYINT(1) NOT NULL DEFAULT 0,
        can_manage_orders TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

$staffRoleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'staff' LIMIT 1");
$staffRoleStmt->execute();
$staffRole = $staffRoleStmt->get_result()->fetch_assoc();
if (!$staffRole) {
    $insertRoleStmt = $conn->prepare("INSERT INTO roles (role_name) VALUES ('staff')");
    $insertRoleStmt->execute();
    $staffRoleId = $conn->insert_id;
} else {
    $staffRoleId = (int) $staffRole['id'];
}

$adminRoleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = 'admin' LIMIT 1");
$adminRoleStmt->execute();
$adminRole = $adminRoleStmt->get_result()->fetch_assoc();
if (!$adminRole) {
    $insertAdminRoleStmt = $conn->prepare("INSERT INTO roles (role_name) VALUES ('admin')");
    $insertAdminRoleStmt->execute();
    $adminRoleId = $conn->insert_id;
} else {
    $adminRoleId = (int) $adminRole['id'];
}

$permissionNames = [
    'manage_inventory',
    'manage_products',
    'manage_categories',
    'manage_orders'
];
foreach ($permissionNames as $permissionName) {
    $permissionStmt = $conn->prepare("INSERT IGNORE INTO permissions (permission_name) VALUES (?)");
    $permissionStmt->bind_param("s", $permissionName);
    $permissionStmt->execute();
}

$permissionRows = $conn->query("SELECT id FROM permissions WHERE permission_name IN ('manage_inventory', 'manage_products', 'manage_categories', 'manage_orders')");
while ($permission = $permissionRows->fetch_assoc()) {
    $rolePermissionStmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
    $permissionId = (int) $permission['id'];
    $rolePermissionStmt->bind_param("ii", $adminRoleId, $permissionId);
    $rolePermissionStmt->execute();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $staffAction = $_POST['staff_action'] ?? 'create';

    if (in_array($staffAction, ['disable', 'activate', 'delete'], true)) {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $errorMessage = 'Invalid staff user.';
        } elseif ($targetUserId === (int) ($_SESSION['user_id'] ?? 0)) {
            $errorMessage = 'You cannot change your own staff account here.';
        } else {
            $targetStmt = $conn->prepare("
                SELECT u.id
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
                AND r.role_name IN ('staff', 'admin')
                LIMIT 1
            ");
            $targetStmt->bind_param("i", $targetUserId);
            $targetStmt->execute();
            $target = $targetStmt->get_result()->fetch_assoc();

            if (!$target) {
                $errorMessage = 'Staff user not found.';
            } elseif ($staffAction === 'delete') {
                $deletePermissionsStmt = $conn->prepare("DELETE FROM staff_permissions WHERE user_id = ?");
                $deletePermissionsStmt->bind_param("i", $targetUserId);
                $deletePermissionsStmt->execute();

                $deleteUserStmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $deleteUserStmt->bind_param("i", $targetUserId);
                $deleteUserStmt->execute();

                $savedMessage = 'Staff user deleted successfully.';
            } else {
                $newStatus = $staffAction === 'activate' ? 'active' : 'disabled';
                $statusStmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
                $statusStmt->bind_param("si", $newStatus, $targetUserId);
                $statusStmt->execute();

                $savedMessage = $staffAction === 'activate'
                    ? 'Staff user activated successfully.'
                    : 'Staff user deactivated successfully.';
            }
        }
    } else {
    $staffFirstName = trim($_POST['staff_first_name'] ?? '');
    $staffLastName = trim($_POST['staff_last_name'] ?? '');
    $staffEmail = trim($_POST['staff_email'] ?? '');
    $staffPassword = $_POST['staff_password'] ?? '';
    $roleType = ($_POST['role_type'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
    $allPermissionsSelected =
        isset($_POST['can_manage_inventory'])
        && isset($_POST['can_manage_products'])
        && isset($_POST['can_manage_categories'])
        && isset($_POST['can_manage_orders']);
    if ($allPermissionsSelected) {
        $roleType = 'admin';
    }
    $selectedRoleId = $roleType === 'admin' ? $adminRoleId : $staffRoleId;

    if ($staffFirstName === '' || $staffLastName === '' || $staffEmail === '' || $staffPassword === '') {
        $errorMessage = 'Please complete the staff first name, last name, email, and password.';
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkStmt->bind_param("s", $staffEmail);
        $checkStmt->execute();

        if ($checkStmt->get_result()->num_rows > 0) {
            $errorMessage = 'A user with that email already exists.';
        } else {
            $hashedPassword = password_hash($staffPassword, PASSWORD_DEFAULT);
            $status = 'active';

            $insertStaffStmt = $conn->prepare("
                INSERT INTO users (first_name, last_name, email, password, role_id, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertStaffStmt->bind_param("ssssis", $staffFirstName, $staffLastName, $staffEmail, $hashedPassword, $selectedRoleId, $status);
            $insertStaffStmt->execute();

            $staffUserId = $conn->insert_id;
            $canInventory = $roleType === 'admin' || isset($_POST['can_manage_inventory']) ? 1 : 0;
            $canProducts = $roleType === 'admin' || isset($_POST['can_manage_products']) ? 1 : 0;
            $canCategories = $roleType === 'admin' || isset($_POST['can_manage_categories']) ? 1 : 0;
            $canOrders = $roleType === 'admin' || isset($_POST['can_manage_orders']) ? 1 : 0;

            $permissionStmt = $conn->prepare("
                INSERT INTO staff_permissions (
                    user_id,
                    can_manage_inventory,
                    can_manage_products,
                    can_manage_categories,
                    can_manage_orders
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $permissionStmt->bind_param("iiiii", $staffUserId, $canInventory, $canProducts, $canCategories, $canOrders);
            $permissionStmt->execute();

            $savedMessage = 'Staff user created successfully.';
        }
    }
    }
}

$staffStmt = $conn->prepare("
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.status,
        u.created_at,
        r.role_name,
        sp.can_manage_inventory,
        sp.can_manage_products,
        sp.can_manage_categories,
        sp.can_manage_orders
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN staff_permissions sp ON sp.user_id = u.id
    WHERE r.role_name IN ('staff', 'admin')
    ORDER BY u.id DESC
");
$staffStmt->execute();
$staffUsers = $staffStmt->get_result();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Staff Users | J&J's Kitchenette Admin</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body class="staff-users-page">
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main">
            <div class="page-container">
                <div class="settings-page-top">
                    <div>
                        <a href="settings.php" class="product-editor-back">
                            <i class="fa-solid fa-arrow-left"></i>
                            Back to settings
                        </a>
                        <span class="settings-eyebrow">Admin access</span>
                        <h1>Staff Users</h1>
                        <p>Add staff accounts and choose which admin areas they can access.</p>
                    </div>
                </div>

                <?php if ($savedMessage !== ''): ?>
                    <div class="settings-alert">
                        <i class="fa-solid fa-circle-check"></i>
                        <?= htmlspecialchars($savedMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="settings-alert settings-alert--error">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <section class="staff-admin-card staff-admin-card--create">
                    <div class="staff-create-top">
                        <div class="settings-card__header">
                            <i class="fa-solid fa-user-shield"></i>
                            <div>
                                <h2>Add Staff User</h2>
                                <p>Create a new staff account and set the areas they can access.</p>
                            </div>
                        </div>

                        <div class="staff-role-presets" aria-label="Quick role presets">
                            <span>Quick role presets</span>
                            <div>
                                <button type="button" data-preset="inventory">
                                    <i class="fa-solid fa-box-open"></i>
                                    Inventory Staff
                                </button>
                                <button type="button" data-preset="products">
                                    <i class="fa-solid fa-bag-shopping"></i>
                                    Product Manager
                                </button>
                                <button type="button" data-preset="orders">
                                    <i class="fa-solid fa-user"></i>
                                    Order Manager
                                </button>
                                <button type="button" data-preset="all">
                                    <i class="fa-solid fa-user-tie"></i>
                                    Full Admin
                                </button>
                            </div>
                        </div>
                    </div>

                    <form class="staff-create-form staff-create-form--refined" method="POST">
                        <input type="hidden" name="role_type" id="staffRoleType" value="staff">

                        <div class="staff-form-grid">
                            <label>
                                <span>First Name</span>
                                <span class="staff-input">
                                    <i class="fa-regular fa-user"></i>
                                    <input type="text" name="staff_first_name" placeholder="e.g. Maria" required>
                                </span>
                            </label>

                            <label>
                                <span>Last Name</span>
                                <span class="staff-input">
                                    <i class="fa-regular fa-user"></i>
                                    <input type="text" name="staff_last_name" placeholder="e.g. Santos" required>
                                </span>
                            </label>

                            <label>
                                <span>Email</span>
                                <span class="staff-input">
                                    <i class="fa-regular fa-envelope"></i>
                                    <input type="email" name="staff_email" placeholder="staff@example.com" required>
                                </span>
                            </label>

                            <label>
                                <span>Password</span>
                                <span class="staff-input">
                                    <i class="fa-solid fa-lock"></i>
                                    <input type="password" name="staff_password" id="staffPassword" placeholder="Create a password" required>
                                    <button type="button" class="staff-password-toggle" aria-label="Show password">
                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                </span>
                            </label>
                        </div>

                        <div class="staff-permission-heading">
                            <div>
                                <h3>Access Permissions</h3>
                                <p>Select the areas this staff user can access and manage.</p>
                            </div>
                            <button type="button" id="selectAllPermissions">
                                <i class="fa-regular fa-circle-check"></i>
                                Select All
                            </button>
                        </div>

                        <div class="staff-permission-grid staff-permission-grid--refined" aria-label="Staff permissions">
                            <label>
                                <span class="staff-permission-icon staff-permission-icon--inventory">
                                    <i class="fa-solid fa-cube"></i>
                                </span>
                                <span>
                                    <strong>Manage Inventory</strong>
                                    <small>Update stock counts and low-stock inventory.</small>
                                </span>
                                <input type="checkbox" name="can_manage_inventory" data-permission="inventory">
                            </label>

                            <label>
                                <span class="staff-permission-icon staff-permission-icon--products">
                                    <i class="fa-solid fa-bag-shopping"></i>
                                </span>
                                <span>
                                    <strong>Manage Products</strong>
                                    <small>Add, edit, and review product details.</small>
                                </span>
                                <input type="checkbox" name="can_manage_products" data-permission="products">
                            </label>

                            <label>
                                <span class="staff-permission-icon staff-permission-icon--categories">
                                    <i class="fa-solid fa-tag"></i>
                                </span>
                                <span>
                                    <strong>Manage Categories</strong>
                                    <small>Create and edit product categories.</small>
                                </span>
                                <input type="checkbox" name="can_manage_categories" data-permission="categories">
                            </label>

                            <label>
                                <span class="staff-permission-icon staff-permission-icon--orders">
                                    <i class="fa-regular fa-clipboard"></i>
                                </span>
                                <span>
                                    <strong>Manage Orders</strong>
                                    <small>View orders and update order status.</small>
                                </span>
                                <input type="checkbox" name="can_manage_orders" data-permission="orders">
                            </label>
                        </div>

                        <div class="settings-actions">
                            <button type="submit">
                                <i class="fa-solid fa-user-plus"></i>
                                Add Staff User
                            </button>
                        </div>
                    </form>
                </section>

                <section class="staff-admin-card staff-admin-card--list">
                    <div class="staff-list-header">
                        <div class="settings-card__header">
                            <i class="fa-solid fa-user-shield"></i>
                            <div>
                                <h2>Existing Staff</h2>
                                <p>View and manage existing staff accounts.</p>
                            </div>
                        </div>

                        <label class="staff-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="search" id="staffSearch" placeholder="Search staff...">
                        </label>
                    </div>

                    <div class="staff-table-wrap">
                        <table class="staff-table">
                            <thead>
                                <tr>
                                    <th>Staff Name</th>
                                    <th>Email</th>
                                    <th>Role / Access</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($staffUsers->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="staff-empty-state">
                                                <span><i class="fa-solid fa-users"></i></span>
                                                <strong>No staff users yet.</strong>
                                                <p>Add your first staff user to get started.</p>
                                                <a href="#staffPassword">
                                                    <i class="fa-solid fa-plus"></i>
                                                    Add Staff User
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php while ($staff = $staffUsers->fetch_assoc()): ?>
                                        <?php
                                        $permissions = [];
                                        if ((int) ($staff['can_manage_inventory'] ?? 0) === 1) {
                                            $permissions[] = 'Inventory';
                                        }
                                        if ((int) ($staff['can_manage_products'] ?? 0) === 1) {
                                            $permissions[] = 'Products';
                                        }
                                        if ((int) ($staff['can_manage_categories'] ?? 0) === 1) {
                                            $permissions[] = 'Categories';
                                        }
                                        if ((int) ($staff['can_manage_orders'] ?? 0) === 1) {
                                            $permissions[] = 'Orders';
                                        }
                                        $staffName = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? '')) ?: 'Staff User';
                                        ?>
                                        <tr class="staff-row" data-search="<?= htmlspecialchars(strtolower($staffName . ' ' . ($staff['email'] ?? '') . ' ' . ($staff['role_name'] ?? '') . ' ' . implode(' ', $permissions)), ENT_QUOTES, 'UTF-8') ?>">
                                            <td><strong><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></strong></td>
                                            <td><?= htmlspecialchars($staff['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <div class="staff-badges">
                                                    <?php if (($staff['role_name'] ?? '') === 'admin'): ?>
                                                        <span>Full Admin</span>
                                                    <?php elseif (empty($permissions)): ?>
                                                        <em>No access set</em>
                                                    <?php else: ?>
                                                        <?php foreach ($permissions as $permission): ?>
                                                            <span><?= htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="staff-status staff-status--<?= htmlspecialchars($staff['status'] ?? 'active', ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(ucfirst($staff['status'] ?? 'active'), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td><?= !empty($staff['created_at']) ? date('M d, Y', strtotime($staff['created_at'])) : '-' ?></td>
                                            <td>
                                                <details class="staff-action-menu">
                                                    <summary class="staff-action-btn" aria-label="Staff actions">
                                                        <i class="fa-solid fa-ellipsis"></i>
                                                    </summary>
                                                    <div>
                                                        <form method="POST">
                                                            <input type="hidden" name="user_id" value="<?= (int) $staff['id'] ?>">
                                                            <?php if (($staff['status'] ?? '') === 'active'): ?>
                                                                <input type="hidden" name="staff_action" value="disable">
                                                                <button type="submit">
                                                                    <i class="fa-solid fa-user-slash"></i>
                                                                    Deactivate
                                                                </button>
                                                            <?php else: ?>
                                                                <input type="hidden" name="staff_action" value="activate">
                                                                <button type="submit">
                                                                    <i class="fa-solid fa-user-check"></i>
                                                                    Activate
                                                                </button>
                                                            <?php endif; ?>
                                                        </form>

                                                        <form method="POST" onsubmit="return confirm('Delete this staff user permanently?');">
                                                            <input type="hidden" name="user_id" value="<?= (int) $staff['id'] ?>">
                                                            <input type="hidden" name="staff_action" value="delete">
                                                            <button type="submit" class="staff-action-delete">
                                                                <i class="fa-regular fa-trash-can"></i>
                                                                Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <script>
        document.querySelectorAll('[data-preset]').forEach(button => {
            button.addEventListener('click', () => {
                const preset = button.dataset.preset;
                const permissions = document.querySelectorAll('[data-permission]');
                const roleType = document.getElementById('staffRoleType');

                if (roleType) {
                    roleType.value = preset === 'all' ? 'admin' : 'staff';
                }

                permissions.forEach(input => {
                    input.checked =
                        preset === 'all' ||
                        input.dataset.permission === preset ||
                        (preset === 'products' && input.dataset.permission === 'categories');
                });
            });
        });

        document.getElementById('selectAllPermissions')?.addEventListener('click', () => {
            const permissions = Array.from(document.querySelectorAll('[data-permission]'));
            const shouldCheck = permissions.some(input => !input.checked);
            permissions.forEach(input => input.checked = shouldCheck);

            const roleType = document.getElementById('staffRoleType');
            if (roleType) {
                roleType.value = shouldCheck ? 'admin' : 'staff';
            }
        });

        document.querySelector('.staff-password-toggle')?.addEventListener('click', function () {
            const input = document.getElementById('staffPassword');
            const icon = this.querySelector('i');
            input.type = input.type === 'password' ? 'text' : 'password';
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });

        document.getElementById('staffSearch')?.addEventListener('input', function () {
            const term = this.value.trim().toLowerCase();
            document.querySelectorAll('.staff-row').forEach(row => {
                row.style.display = row.dataset.search.includes(term) ? '' : 'none';
            });
        });
    </script>
</body>

</html>
