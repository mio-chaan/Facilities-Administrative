<?php
/**
 * routes.php
 * Whitelist map for the front controller (index.php).
 *
 * key = value of ?page=
 *   file  = module entry file, relative to project root
 *   label = text shown in the sidebar nav
 *   roles = OPTIONAL. If present, only these roles can see the nav
 *           link (sidebar.php) or reach the page directly by URL
 *           (index.php calls t8_require_role() before requiring the
 *           module file). Omit entirely for routes open to any
 *           authenticated user - this keeps every existing route
 *           backward compatible with no changes needed.
 *
 * This is intentionally a flat array, not a "router class" — the project
 * has no framework, and a single lookup table is enough for 8 pages.
 * If the number of routes grows a lot (nested/module-internal actions),
 * revisit with a slightly smarter dispatcher — not needed yet.
 */

declare(strict_types=1);

return [
    'dashboard'   => ['file' => 'modules/dashboard/index.php',   'label' => 'Dashboard'],
    'reservation' => ['file' => 'modules/reservation/index.php', 'label' => 'Facilities Reservation'],
    'facilities'  => ['file' => 'modules/facilities/index.php',  'label' => 'Facility Management', 'roles' => ['admin']],
    'visitor'     => ['file' => 'modules/visitor/index.php',     'label' => 'Visitor Management'],
    'documents'   => ['file' => 'modules/documents/index.php',   'label' => 'Document Management'],
    'retention'   => ['file' => 'modules/retention/index.php',   'label' => 'Records Retention'],
    'legal'       => ['file' => 'modules/legal/index.php',       'label' => 'Legal Management'],
    'contracts'   => ['file' => 'modules/contracts/index.php',   'label' => 'Contract Management'],
];
