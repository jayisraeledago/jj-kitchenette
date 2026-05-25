<?php
require_once __DIR__ . '/../../includes/session.php';
startAppSession();

function adminRedirectToLogin()
{
    header("Location: /store/login.php");
    exit;
}

function adminForbidden()
{
    http_response_code(403);
    echo "<!DOCTYPE html><html><head><title>Access denied</title></head><body style=\"font-family:Arial,sans-serif;padding:40px;\">";
    echo "<h1>Access denied</h1>";
    echo "<p>You do not have permission to access this admin page.</p>";
    echo "<p><a href=\"/store/dashboard.php\">Back to dashboard</a></p>";
    echo "</body></html>";
    exit;
}

function currentAdminRole()
{
    return $_SESSION['role_name'] ?? '';
}

function ensureAdminUser()
{
    $role = currentAdminRole();

    if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'staff'], true)) {
        adminRedirectToLogin();
    }
}

function staffPermissionRow($conn)
{
    static $permissionRow = null;

    if ($permissionRow !== null) {
        return $permissionRow;
    }

    $permissionRow = [];

    if (!isset($_SESSION['user_id'])) {
        return $permissionRow;
    }

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

    $stmt = $conn->prepare("
        SELECT
            can_manage_inventory,
            can_manage_products,
            can_manage_categories,
            can_manage_orders
        FROM staff_permissions
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $permissionRow = $stmt->get_result()->fetch_assoc() ?: [];

    return $permissionRow;
}

function staffCan($conn, $permission)
{
    if (currentAdminRole() === 'admin') {
        return true;
    }

    if (currentAdminRole() !== 'staff') {
        return false;
    }

    $permissionMap = [
        'inventory' => 'can_manage_inventory',
        'products' => 'can_manage_products',
        'categories' => 'can_manage_categories',
        'orders' => 'can_manage_orders'
    ];

    $column = $permissionMap[$permission] ?? '';
    if ($column === '') {
        return false;
    }

    $row = staffPermissionRow($conn);
    return (int) ($row[$column] ?? 0) === 1;
}

function requireAdminPermission($conn, $permissions = [])
{
    ensureAdminUser();

    if (currentAdminRole() === 'admin') {
        return;
    }

    if (empty($permissions)) {
        return;
    }

    foreach ((array) $permissions as $permission) {
        if (staffCan($conn, $permission)) {
            return;
        }
    }

    adminForbidden();
}

function requireAdminOnly()
{
    ensureAdminUser();

    if (currentAdminRole() !== 'admin') {
        adminForbidden();
    }
}
