<?php
/**
 * templates/sidebar.php
 *
 * Dark maroon sidebar with the RAM-YUM logo and route-based navigation.
 * Icons are a local display mapping for each route and do not affect
 * the route configuration itself.
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
