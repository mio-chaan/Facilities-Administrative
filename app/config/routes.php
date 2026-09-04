<?php


declare(strict_types=1);

return [
    'dashboard'     => ['file' => 'modules/dashboard/index.php',     'label' => 'Dashboard'],
    'reservation'   => ['file' => 'modules/reservation/index.php',   'label' => 'Facilities Reservation'],
    'facilities'    => ['file' => 'modules/facilities/index.php',    'label' => 'Facilities'],
    'visitor'       => ['file' => 'modules/visitor/index.php',       'label' => 'Visitor Management'],
    'documents'     => ['file' => 'modules/documents/index.php',     'label' => 'Document Management'],
    'retention'     => ['file' => 'modules/retention/index.php',     'label' => 'Records Retention'],
    'legal'         => ['file' => 'modules/legal/index.php',         'label' => 'Legal Management'],
    'contracts'     => ['file' => 'modules/contracts/index.php',     'label' => 'Contract Management'],
    'assistant'     => ['file' => 'modules/assistant/index.php',     'label' => 'AI Assistant', 'hidden' => true],
    // DASHBOARD UPDATE: "View all notifications" destination from the
    // bell popover. Not a sidebar item — hidden => true, same pattern
    // as 'assistant' above.
    'notifications' => ['file' => 'modules/notifications/index.php', 'label' => 'Notifications', 'hidden' => true],
    'audit'         => ['file' => 'modules/audit/index.php',         'label' => 'Audit Logs', 'roles' => ['admin']],
];
