<?php
session_start();
include 'db.php';

$locationSettings = [
    'store_phone' => '09910095940',
    'store_area' => 'Tangub City, Misamis Occidental'
];
$locationSettingsFile = __DIR__ . '/store/settings.json';
if (is_file($locationSettingsFile)) {
    $savedLocationSettings = json_decode(file_get_contents($locationSettingsFile), true);
    if (is_array($savedLocationSettings)) {
        $locationSettings = array_merge($locationSettings, $savedLocationSettings);
    }
}
$locationPhoneHref = preg_replace('/[^0-9+]/', '', $locationSettings['store_phone']);
$locationArea = $locationSettings['store_area'];

$pageTitle = "Store Location | J&J's Kitchenette";
$pageCSS = "contact.css";

include('store/includes/header.php');
?>

<main class="info-page">
    <section class="info-hero location-hero">
        <div class="info-container info-hero__inner">
            <p class="info-eyebrow">Find J&J Kitchenette</p>
            <h1>Visit us in Tangub City, Misamis Occidental.</h1>
            <p>
                Use the map below to find the store area, then contact us if you need help with pickup or delivery.
            </p>
        </div>
    </section>

    <section class="info-container location-layout">
        <div class="location-details">
            <p class="info-eyebrow">Store location</p>
            <h2><?= htmlspecialchars($locationArea, ENT_QUOTES, 'UTF-8') ?></h2>
            <p>
                J&J Kitchenette serves customers around <?= htmlspecialchars($locationArea, ENT_QUOTES, 'UTF-8') ?>. For the most accurate pickup instructions,
                contact the store before visiting.
            </p>

            <div class="location-actions">
                <a href="https://www.google.com/maps/search/?api=1&query=J%26J%20Kitchenette%20Tangub%20City%20Misamis%20Occidental" target="_blank" rel="noopener">
                    <i class="fas fa-route"></i>
                    Open in Google Maps
                </a>

                <a href="tel:<?= htmlspecialchars($locationPhoneHref, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-phone"></i>
                    Call Store
                </a>
            </div>
        </div>

        <div class="map-panel" aria-label="Map showing Tangub City, Misamis Occidental">
            <iframe
                title="J&J Kitchenette map"
                src="https://www.google.com/maps?q=J%26J%20Kitchenette%20Tangub%20City%20Misamis%20Occidental&output=embed"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade">
            </iframe>
        </div>
    </section>
</main>

<?php include('store/includes/footer.php'); ?>
