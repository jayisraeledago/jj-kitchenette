<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(isset($pageTitle) ? $pageTitle : "J&J's Kitchenette", ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">

    <!-- CSS -->
    <link rel="stylesheet" href="/style.css">

    <?php if (isset($pageCSS)) { ?>
        <link rel="stylesheet" href="/assets/css/<?php echo $pageCSS; ?>">
    <?php } ?>

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

    <?php
    $currentPage = basename($_SERVER['PHP_SELF']);
    $hasHomePage = file_exists(dirname(__DIR__, 2) . '/index.php');
    $homeUrl = $hasHomePage ? '/' : '/menu';
    $accountUrl = isset($_SESSION['user_id'])
        ? '/account/profile.php'
        : '/account.php';
    $accountPages = ['account.php', 'login.php', 'profile.php', 'edit_profile.php'];
    $headerSearch = $currentPage === 'menu.php' ? trim($_GET['search'] ?? '') : '';
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

            <button
                type="button"
                class="mobile-menu-toggle"
                aria-label="Open menu"
                aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Logo -->
            <div class="logo">
                <a href="<?php echo $homeUrl; ?>" aria-label="J&J's Kitchenette home">
                    <img src="/assets/images/kitchenette-logo.svg" alt="J&J's Kitchenette">
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
                    href="/menu.php"
                    class="nav-link <?php echo $currentPage === 'menu.php' ? 'active' : ''; ?>">
                    <i class="fas fa-utensils"></i>
                    <span>Menu</span>
                </a>

                <a
                    href="/location.php"
                    class="nav-link <?php echo $currentPage === 'location.php' ? 'active' : ''; ?>">
                    <i class="fas fa-location-dot"></i>
                    <span>Location</span>
                </a>

                <a
                    href="/contact.php"
                    class="nav-link <?php echo $currentPage === 'contact.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i>
                    <span>Contact Us</span>
                </a>

                <a
                    href="<?php echo $accountUrl; ?>"
                    class="nav-link nav-account-mobile <?php echo in_array($currentPage, $accountPages, true) ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i>
                    <span>Account</span>
                </a>
            </nav>

            <div class="nav-actions">
                <button
                    type="button"
                    class="header-search-toggle"
                    aria-label="Open search"
                    aria-expanded="false">
                    <i class="fas fa-search"></i>
                </button>

                <a
                    href="/cart.php"
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

        <form class="header-search-panel" action="/menu.php" method="GET" role="search" aria-hidden="true">
            <div class="header-search-stack">
                <div class="header-search-field">
                    <input
                        type="search"
                        name="search"
                        class="header-search-input"
                        placeholder="Search"
                        value="<?php echo htmlspecialchars($headerSearch, ENT_QUOTES, 'UTF-8'); ?>"
                        autocomplete="off"
                        aria-label="Search menu"
                        aria-controls="headerSearchResults">
                    <button type="submit" class="header-search-submit" aria-label="Search menu">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="header-search-results" id="headerSearchResults" hidden></div>
            </div>
            <button type="button" class="header-search-close" aria-label="Close search">
                <i class="fas fa-times"></i>
            </button>
        </form>
    </header>

    <script>
        (function () {
            const header = document.querySelector('.header');
            const searchToggle = document.querySelector('.header-search-toggle');
            const searchPanel = document.querySelector('.header-search-panel');
            const searchInput = document.querySelector('.header-search-input');
            const searchClose = document.querySelector('.header-search-close');
            const searchResults = document.querySelector('.header-search-results');
            const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
            let searchTimer;
            let searchController;

            if (!header || !searchToggle || !searchPanel || !searchInput || !searchClose) {
                return;
            }

            function setMobileMenuOpen(isOpen) {
                if (!mobileMenuToggle) {
                    return;
                }

                header.classList.toggle('is-mobile-menu-open', isOpen);
                document.body.classList.toggle('is-mobile-menu-open', isOpen);
                mobileMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                mobileMenuToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
                mobileMenuToggle.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-bars"></i>';
            }

            function openSearch() {
                header.classList.add('is-searching');
                setMobileMenuOpen(false);
                searchToggle.setAttribute('aria-expanded', 'true');
                searchPanel.setAttribute('aria-hidden', 'false');
                window.setTimeout(function () {
                    searchInput.focus();
                    searchInput.select();
                }, 60);
            }

            function closeSearch() {
                header.classList.remove('is-searching');
                searchToggle.setAttribute('aria-expanded', 'false');
                searchPanel.setAttribute('aria-hidden', 'true');
                hideResults();
                searchToggle.focus();
            }

            function hideResults() {
                if (!searchResults) {
                    return;
                }

                searchResults.hidden = true;
                searchResults.innerHTML = '';
            }

            function escapeHtml(value) {
                return String(value).replace(/[&<>"']/g, function (char) {
                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    }[char];
                });
            }

            function renderResults(items, query) {
                if (!searchResults) {
                    return;
                }

                if (!items.length) {
                    searchResults.innerHTML = '<div class="header-search-empty">No products found.</div>';
                    searchResults.hidden = false;
                    return;
                }

                const safeQuery = escapeHtml(query);
                searchResults.innerHTML = `
                    <div class="header-search-results__label">Products</div>
                    <div class="header-search-products">
                        ${items.map(function (item) {
                            return `
                                <a class="header-search-product" href="${item.url}">
                                    <img src="${item.image}" alt="">
                                    <span>
                                        <strong>${escapeHtml(item.title)}</strong>
                                        <small>${escapeHtml(item.category)} &middot; ${escapeHtml(item.price)}</small>
                                    </span>
                                </a>
                            `;
                        }).join('')}
                    </div>
                    <a class="header-search-all" href="/menu.php?search=${encodeURIComponent(query)}">
                        Search for "${safeQuery}"
                        <i class="fas fa-arrow-right"></i>
                    </a>
                `;
                searchResults.hidden = false;
            }

            function loadResults() {
                const query = searchInput.value.trim();

                window.clearTimeout(searchTimer);

                if (searchController) {
                    searchController.abort();
                }

                if (query.length < 2) {
                    hideResults();
                    return;
                }

                searchTimer = window.setTimeout(function () {
                    searchController = new AbortController();

                    fetch('/search_suggestions.php?q=' + encodeURIComponent(query), {
                        signal: searchController.signal,
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Search failed');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            renderResults(data.items || [], query);
                        })
                        .catch(function (error) {
                            if (error.name !== 'AbortError') {
                                hideResults();
                            }
                        });
                }, 180);
            }

            searchToggle.addEventListener('click', openSearch);
            searchClose.addEventListener('click', closeSearch);
            searchInput.addEventListener('input', loadResults);

            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function () {
                    setMobileMenuOpen(!header.classList.contains('is-mobile-menu-open'));
                });
            }

            document.querySelectorAll('.nav a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (!mobileMenuToggle) {
                        return;
                    }

                    setMobileMenuOpen(false);
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && header.classList.contains('is-searching')) {
                    closeSearch();
                } else if (event.key === 'Escape' && header.classList.contains('is-mobile-menu-open') && mobileMenuToggle) {
                    setMobileMenuOpen(false);
                }
            });
        }());
    </script>
