<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(isset($pageTitle) ? $pageTitle : "J&J's Kitchenette", ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">

    <!-- CSS -->
    <link rel="stylesheet" href="/jj_kitchenette/style.css">

    <?php if (isset($pageCSS)) { ?>
        <link rel="stylesheet" href="/jj_kitchenette/assets/css/<?php echo $pageCSS; ?>">
    <?php } ?>

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

    <?php
    $currentPage = basename($_SERVER['PHP_SELF']);
    $hasHomePage = file_exists(dirname(__DIR__, 2) . '/index.php');
    $homeUrl = $hasHomePage ? '/jj_kitchenette/' : '/jj_kitchenette/menu';
    $accountUrl = isset($_SESSION['user_id'])
        ? '/jj_kitchenette/account/profile.php'
        : '/jj_kitchenette/account.php';
    $accountPages = ['account.php', 'login.php', 'profile.php', 'edit_profile.php'];
    $cartCount = 0;

    if (isset($_SESSION['user_id'], $conn)) {
        $cartCountStmt = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) AS total
            FROM cart
            WHERE user_id = ?
        ");

        if ($cartCountStmt) {
            $cartCountStmt->bind_param("i", $_SESSION['user_id']);
            $cartCountStmt->execute();
            $cartCount = (int) ($cartCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
        }
    }
    ?>

    <header class="header">
        <div class="header-topbar">
            <i class="fas fa-leaf"></i>
            <span>Fresh ingredients. Made with love. Delivered to you.</span>
        </div>

        <div class="nav-container">

            <!-- Logo -->
            <div class="logo">
                <a href="<?php echo $homeUrl; ?>" aria-label="J&J's Kitchenette home">
                    <img src="/jj_kitchenette/assets/images/kitchenette-logo.svg" alt="J&J's Kitchenette">
                </a>
            </div>

            <!-- Navigation -->
            <nav class="nav">
                <?php if ($hasHomePage) { ?>
                    <a
                        href="<?php echo $homeUrl; ?>"
                        class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i>
                        <span>Home</span>
                    </a>
                <?php } ?>

                <a
                    href="/jj_kitchenette/menu.php"
                    class="nav-link <?php echo $currentPage === 'menu.php' ? 'active' : ''; ?>">
                    <i class="fas fa-utensils"></i>
                    <span>Menu</span>
                </a>

                <a
                    href="/jj_kitchenette/location.php"
                    class="nav-link <?php echo $currentPage === 'location.php' ? 'active' : ''; ?>">
                    <i class="fas fa-location-dot"></i>
                    <span>Location</span>
                </a>

                <a
                    href="/jj_kitchenette/contact.php"
                    class="nav-link <?php echo $currentPage === 'contact.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i>
                    <span>Contact Us</span>
                </a>
            </nav>

            <div class="nav-actions">
                <a
                    href="/jj_kitchenette/cart.php"
                    class="cart-link <?php echo $currentPage === 'cart.php' ? 'active' : ''; ?>"
                    aria-label="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Cart</span>
                    <?php if ($cartCount > 0) { ?>
                        <strong class="cart-count"><?php echo $cartCount; ?></strong>
                    <?php } ?>
                </a>

                <a
                    href="<?php echo $accountUrl; ?>"
                    class="account-link <?php echo in_array($currentPage, $accountPages, true) ? 'active' : ''; ?>"
                    aria-label="Account">
                    <i class="fas fa-user"></i>
                    <span>Account</span>
                </a>
            </div>

        </div>
    </header>
