<?php
/**
 * "Your booking link" panel: copyable public link, open button and a downloadable QR code.
 * Expects $bookingSlug (string) to be set by the including page.
 */
$bookingSlug = (string)($bookingSlug ?? '');
if ($bookingSlug === '') {
    return;
}
$bookingPath = bookingUrl($bookingSlug);
$bookingFullUrl = absoluteUrl($bookingPath);
$bookingLocalOnly = isLocalOnlyUrl($bookingFullUrl);
?>
<section class="content-card booking-link-card" data-booking-link>
    <div class="booking-link-main">
        <div class="section-head">
            <div>
                <p class="eyebrow">SHARE</p>
                <h2>Your booking link</h2>
            </div>
        </div>
        <p class="muted">Clients open this page to request a consultation. No account is needed. Add it to your website, email signature, social profiles or print the QR code.</p>

        <?php if ($bookingLocalOnly): ?>
            <div class="booking-link-warning" role="alert">
                <strong>Only works on this computer.</strong>
                This link uses <code><?= h((string)parse_url($bookingFullUrl, PHP_URL_HOST)) ?></code>, so clients cannot open it yet.
                Once the app is online, set <code>APP_PUBLIC_URL</code> in <code>config/app.php</code>
                (for example <code>https://book.yourbusiness.com</code>) and this link and QR code will update.
            </div>
        <?php endif; ?>

        <label for="booking-link-input">
            Public booking link
            <input id="booking-link-input" type="text" readonly value="<?= h($bookingFullUrl) ?>" onclick="this.select()">
        </label>
        <div class="action-group">
            <button class="button" type="button" data-copy-target="booking-link-input">Copy link</button>
            <a class="button secondary" href="<?= h($bookingPath) ?>" target="_blank" rel="noopener">Open page</a>
        </div>
    </div>

    <div class="booking-link-qr">
        <div class="qr-frame" data-qr-source="booking-link-input" data-qr-name="<?= h($bookingSlug) ?>-booking-qr">
            <span class="muted">QR code needs JavaScript.</span>
        </div>
        <div class="action-group">
            <button class="button secondary small" type="button" data-qr-download="png">Download PNG</button>
            <button class="button secondary small" type="button" data-qr-download="svg">Download SVG</button>
        </div>
        <p class="field-help">Scan to test it on your phone. Use the PNG for print and the SVG for large signage.</p>
    </div>
</section>
<script src="<?= h(appUrl('assets/js/qrcode.js')) ?>" defer></script>
<script src="<?= h(appUrl('assets/js/booking-link.js')) ?>" defer></script>
