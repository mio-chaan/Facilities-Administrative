<?php
/**
 * templates/sidebar.php
 *
 * REDESIGN: dark maroon gradient sidebar with the real RAM-YUM logo,
 * icon + label nav items, rounded active state, and an optional
 * desktop collapse toggle (public/js/app.js, additive only).
 *
 * Labels/routes still come from app/config/routes.php - the single
 * source of truth (see docs/API.md's Medium fix note). $t8NavIcons
 * below is a purely cosmetic, LOCAL lookup for which Font Awesome
 * icon each route gets; it does not change routes.php's contract or
 * T8_PAGES, and a route missing from this map just falls back to a
 * generic icon instead of breaking.
 *
 * FIX (Facility Management, 2026-07-17): routes.php can now carry an
 * optional 'roles' key. A route with no 'roles' key stays visible to
 * every authenticated user, same as before - this loop only ADDS a
 * skip for routes that declare roles the current user doesn't have
 * (e.g. 'facilities' => admin-only). Uses the same t8_has_role()
 * helper permissions.php already provides, no new role-checking logic.
 */
declare(strict_types=1);

$t8Routes = require __DIR__ . '/../app/config/routes.php';
$active   = current_page();

$t8NavIcons = [
    'dashboard'   => 'fa-gauge-high',
    'reservation' => 'fa-calendar-check',
    'facilities'  => 'fa-building',
    'visitor'     => 'fa-id-card-clip',
    'documents'   => 'fa-file-lines',
    'retention'   => 'fa-box-archive',
    'legal'       => 'fa-scale-balanced',
    'contracts'   => 'fa-file-contract',
];
?>
<aside class="t8-sidebar" id="t8Sidebar">
    <div class="t8-sidebar-brand">
        <img class="t8-sidebar-logo" src="<?= e(asset('img/ramyumlogo.jpg')) ?>" alt="<?= e(APP_NAME) ?> logo">
        <div class="t8-sidebar-brand-text">
            <div class="t8-sidebar-brand-name">RAM YUM</div>
            <div class="t8-sidebar-brand-tag">Facilities &amp; Administration</div>
        </div>
    </div>

    <nav class="t8-sidebar-nav">
        <?php foreach ($t8Routes as $key => $route): ?>
            <?php if (!empty($route['roles']) && !t8_has_role($route['roles'])): ?>
        <?php continue; ?>
            <?php endif; ?>
        <?php if (!empty($route['hidden'])): ?>
            <?php continue; ?>
        <?php endif; ?>
            <a href="<?= e(page_url($key)) ?>"
               class="t8-sidebar-link<?= $active === $key ? ' t8-sidebar-link-active' : '' ?>">
                <i class="fa-solid <?= e($t8NavIcons[$key] ?? 'fa-circle-dot') ?>"></i>
                <span class="t8-sidebar-label"><?= e($route['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <button class="t8-sidebar-collapse-btn" id="t8SidebarCollapseToggle" type="button" aria-label="Expand sidebar">
        <i class="fa-solid fa-angles-right"></i>
        <span>Expand</span>
    </button>
</aside>
