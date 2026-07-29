<?php


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
    'assistant'   => ['file' => 'modules/assistant/index.php',   'label' => 'AI Assistant', 'hidden' => true],
];
