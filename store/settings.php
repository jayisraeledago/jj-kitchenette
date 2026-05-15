<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminOnly();

$settingsFile = __DIR__ . '/settings.json';

$defaults = [
    'store_name' => "J&J's Kitchenette",
    'store_email' => 'jjkitchenette@email.com',
    'store_phone' => '09910095940',
    'store_area' => 'Tangub City, Misamis Occidental',
    'currency' => 'PHP',
    'delivery_fee' => '0',
    'pickup_enabled' => '1',
    'delivery_enabled' => '1',
    'low_stock_alert' => '10',
    'order_note' => 'Thank you for ordering from J&J Kitchenette.'
];

function settingValue($settings, $key)
{
    return htmlspecialchars((string) ($settings[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

$settings = $defaults;
if (file_exists($settingsFile)) {
    $saved = json_decode((string) file_get_contents($settingsFile), true);
    if (is_array($saved)) {
        $settings = array_merge($settings, $saved);
    }
}

$savedMessage = '';
$errorMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formAction = $_POST['form_action'] ?? 'save_settings';

    if ($formAction === 'save_settings') {
        $settings = [
        'store_name' => trim($_POST['store_name'] ?? $defaults['store_name']),
        'store_email' => trim($_POST['store_email'] ?? $defaults['store_email']),
        'store_phone' => trim($_POST['store_phone'] ?? $defaults['store_phone']),
        'store_area' => trim($_POST['store_area'] ?? $defaults['store_area']),
        'currency' => trim($_POST['currency'] ?? $defaults['currency']),
        'delivery_fee' => (string) max(0, (float) ($_POST['delivery_fee'] ?? 0)),
        'pickup_enabled' => isset($_POST['pickup_enabled']) ? '1' : '0',
        'delivery_enabled' => isset($_POST['delivery_enabled']) ? '1' : '0',
        'low_stock_alert' => (string) max(0, (int) ($_POST['low_stock_alert'] ?? 0)),
        'order_note' => trim($_POST['order_note'] ?? $defaults['order_note'])
        ];

        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
        $savedMessage = 'Settings saved successfully.';
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Settings | J&J's Kitchenette Admin</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main">
            <div class="page-container">
                <div class="settings-page-top">
                    <div>
                        <span class="settings-eyebrow">Admin preferences</span>
                        <h1>Settings</h1>
                        <p>Manage storefront details, order options, and admin defaults.</p>
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

                <form class="settings-layout" method="POST">
                    <input type="hidden" name="form_action" value="save_settings">
                    <section class="settings-card">
                        <div class="settings-card__header">
                            <i class="fa-solid fa-store"></i>
                            <div>
                                <h2>Store Profile</h2>
                                <p>Public contact information for customers.</p>
                            </div>
                        </div>

                        <div class="settings-grid">
                            <label>
                                <span>Store Name</span>
                                <input type="text" name="store_name" value="<?= settingValue($settings, 'store_name') ?>" required>
                            </label>

                            <label>
                                <span>Email</span>
                                <input type="email" name="store_email" value="<?= settingValue($settings, 'store_email') ?>" required>
                            </label>

                            <label>
                                <span>Phone</span>
                                <input type="text" name="store_phone" value="<?= settingValue($settings, 'store_phone') ?>" required>
                            </label>

                            <label>
                                <span>Store Area</span>
                                <input type="text" name="store_area" value="<?= settingValue($settings, 'store_area') ?>" required>
                            </label>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card__header">
                            <i class="fa-solid fa-bag-shopping"></i>
                            <div>
                                <h2>Orders</h2>
                                <p>Set default fees and fulfillment options.</p>
                            </div>
                        </div>

                        <div class="settings-grid">
                            <label>
                                <span>Currency</span>
                                <select name="currency">
                                    <option value="PHP" <?= ($settings['currency'] ?? '') === 'PHP' ? 'selected' : '' ?>>PHP - Philippine Peso</option>
                                </select>
                            </label>

                            <label>
                                <span>Default Delivery Fee</span>
                                <input type="number" name="delivery_fee" min="0" step="0.01" value="<?= settingValue($settings, 'delivery_fee') ?>">
                            </label>

                            <label>
                                <span>Low Stock Alert</span>
                                <input type="number" name="low_stock_alert" min="0" value="<?= settingValue($settings, 'low_stock_alert') ?>">
                            </label>
                        </div>

                        <div class="settings-toggles">
                            <label>
                                <input type="checkbox" name="pickup_enabled" <?= ($settings['pickup_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span>Store pickup enabled</span>
                            </label>

                            <label>
                                <input type="checkbox" name="delivery_enabled" <?= ($settings['delivery_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span>Delivery enabled</span>
                            </label>
                        </div>
                    </section>

                    <section class="settings-card settings-card--wide">
                        <div class="settings-card__header">
                            <i class="fa-solid fa-message"></i>
                            <div>
                                <h2>Customer Note</h2>
                                <p>Default note shown in order-related admin workflows.</p>
                            </div>
                        </div>

                        <label>
                            <span>Order Note</span>
                            <textarea name="order_note" rows="4"><?= settingValue($settings, 'order_note') ?></textarea>
                        </label>
                    </section>

                    <section class="settings-card settings-card--wide settings-link-card">
                        <div class="settings-card__header">
                            <i class="fa-solid fa-user-shield"></i>
                            <div>
                                <h2>Staff Users</h2>
                                <p>Add staff accounts and control access to products, inventory, categories, and orders.</p>
                            </div>
                        </div>

                        <a href="staff-users.php">
                            <i class="fa-solid fa-arrow-right"></i>
                            Manage Staff Users
                        </a>
                    </section>

                    <div class="settings-actions">
                        <button type="submit">
                            <i class="fa-regular fa-floppy-disk"></i>
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>
