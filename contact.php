<?php
session_start();
include 'db.php';

$contactSettings = [
    'store_name' => "J&J's Kitchenette",
    'store_email' => 'jjkitchenette@email.com',
    'store_phone' => '09910095940',
    'store_area' => 'Tangub City, Misamis Occidental'
];
$contactSettingsFile = __DIR__ . '/store/settings.json';
if (file_exists($contactSettingsFile)) {
    $savedContactSettings = json_decode((string) file_get_contents($contactSettingsFile), true);
    if (is_array($savedContactSettings)) {
        $contactSettings = array_merge($contactSettings, $savedContactSettings);
    }
}
$contactPhoneHref = preg_replace('/[^0-9+]/', '', $contactSettings['store_phone']);

$pageTitle = "Contact Us | J&J's Kitchenette";
$pageCSS = "contact.css";

include('store/includes/header.php');
?>

<main class="info-page">
    <section class="info-hero">
        <div class="info-container info-hero__inner">
            <p class="info-eyebrow">Contact <?= htmlspecialchars($contactSettings['store_name'], ENT_QUOTES, 'UTF-8') ?></p>
            <h1>We are here to help with orders, pickups, and delivery questions.</h1>
            <p>
                Reach out to the store using the details below, or visit our location page to find us in <?= htmlspecialchars($contactSettings['store_area'], ENT_QUOTES, 'UTF-8') ?>.
            </p>
        </div>
    </section>

    <section class="info-container info-grid" aria-label="Contact details">
        <article class="info-card">
            <i class="fas fa-phone"></i>
            <div>
                <h2>Call or Text</h2>
                <p><?= htmlspecialchars($contactSettings['store_phone'], ENT_QUOTES, 'UTF-8') ?></p>
                <a href="tel:<?= htmlspecialchars($contactPhoneHref, ENT_QUOTES, 'UTF-8') ?>">Call now</a>
            </div>
        </article>

        <article class="info-card">
            <i class="fas fa-envelope"></i>
            <div>
                <h2>Email</h2>
                <p><?= htmlspecialchars($contactSettings['store_email'], ENT_QUOTES, 'UTF-8') ?></p>
                <a href="mailto:<?= htmlspecialchars($contactSettings['store_email'], ENT_QUOTES, 'UTF-8') ?>">Send email</a>
            </div>
        </article>

        <article class="info-card">
            <i class="fas fa-location-dot"></i>
            <div>
                <h2>Store Area</h2>
                <p><?= htmlspecialchars($contactSettings['store_area'], ENT_QUOTES, 'UTF-8') ?></p>
                <a href="/jj_kitchenette/location.php">View location</a>
            </div>
        </article>
    </section>

    <section class="info-container contact-panel">
        <div>
            <p class="info-eyebrow">Quick message</p>
            <h2>Tell us what you need.</h2>
            <p>
                For faster assistance, include your order number if you already placed an order.
            </p>
        </div>

        <form class="contact-form" action="mailto:<?= htmlspecialchars($contactSettings['store_email'], ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="text/plain">
            <label>
                Name
                <input type="text" name="name" required>
            </label>

            <label>
                Phone or Email
                <input type="text" name="contact" required>
            </label>

            <label>
                Message
                <textarea name="message" rows="5" required></textarea>
            </label>

            <button type="submit">
                <i class="fas fa-paper-plane"></i>
                Send Message
            </button>
        </form>
    </section>
</main>

<?php include('store/includes/footer.php'); ?>
