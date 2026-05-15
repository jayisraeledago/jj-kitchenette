<?php
$footerSettings = [
    'store_name' => "J&J's Kitchenette",
    'store_email' => 'jjkitchenette@email.com',
    'store_phone' => '09910095940',
    'store_area' => 'Tangub City, Mis.Occ.'
];
$footerSettingsFile = __DIR__ . '/../settings.json';
if (file_exists($footerSettingsFile)) {
    $savedFooterSettings = json_decode((string) file_get_contents($footerSettingsFile), true);
    if (is_array($savedFooterSettings)) {
        $footerSettings = array_merge($footerSettings, $savedFooterSettings);
    }
}
?>
<footer class="footer">
    <div class="footer-container">

        <!-- Brand -->
        <div class="footer-section brand">
            <img src="/jj_kitchenette/assets/images/kitchenette-logo.svg" alt="J&J's Kitchenette">
            <p>Delicious & Affordable Meals. Order now and enjoy quality food at your convenience.</p>

            <div class="footer-badges" aria-label="Store highlights">
                <span><i class="fas fa-leaf"></i> Fresh</span>
                <span><i class="fas fa-bowl-food"></i> Quality</span>
                <span><i class="fas fa-heart"></i> Made with love</span>
            </div>
        </div>

        <!-- Links -->
        <div class="footer-section">
            <h4>Explore</h4>
            <ul>
                <li><a href="/jj_kitchenette/"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="/jj_kitchenette/menu"><i class="fas fa-utensils"></i> Menu</a></li>
                <li><a href="/jj_kitchenette/location"><i class="fas fa-location-dot"></i> Location</a></li>
                <li><a href="/jj_kitchenette/contact"><i class="fas fa-envelope"></i> Contact Us</a></li>
                <li><a href="/jj_kitchenette/cart"><i class="fas fa-shopping-cart"></i> Cart</a></li>
            </ul>
        </div>

        <!-- Contact -->
        <div class="footer-section">
            <h4>Contact</h4>
            <div class="footer-contact">
                <p><i class="fas fa-location-dot"></i> <?= htmlspecialchars($footerSettings['store_area'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><i class="fas fa-phone"></i> <?= htmlspecialchars($footerSettings['store_phone'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($footerSettings['store_email'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><i class="fas fa-clock"></i> Mon - Sun: 8:00 AM - 9:00 PM</p>
            </div>
        </div>

        <!-- Social -->
        <div class="footer-section">
            <h4>Follow Us</h4>
            <p>Stay connected and follow us on our social media platforms.</p>

            <div class="socials">
                <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                <a href="#" aria-label="TikTok"><i class="fab fa-tiktok"></i></a>
            </div>

            <div class="footer-delivery">
                <i class="fas fa-bag-shopping"></i>
                <div>
                    <strong>Fast & Reliable Delivery</strong>
                    <span>Favorites straight to your door.</span>
                </div>
            </div>
        </div>

    </div>

    <div class="footer-bottom">
        <p>&copy; <?php echo date("Y"); ?> <strong><?= htmlspecialchars($footerSettings['store_name'], ENT_QUOTES, 'UTF-8') ?></strong>. All rights reserved.</p>
    </div>
</footer>
