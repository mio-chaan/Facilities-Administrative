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
    'party_registry' => ['file' => 'modules/contracts/party_registry.php', 'label' => 'Party Registry', 'roles' => ['admin', 'legal_officer', 'facilities_staff', 'employee']],
    'compliance_reports' => ['file' => 'modules/compliance_reports/index.php', 'label' => 'Compliance Reports', 'roles' => ['admin'], 'hidden' => true],
    'compliance_reports_all' => ['file' => 'modules/compliance_reports/index.php', 'label' => 'All Compliance Reports', 'roles' => ['admin'], 'hidden' => true],
    'assistant'     => ['file' => 'modules/assistant/index.php',     'label' => 'AI Assistant', 'hidden' => true],
    'notifications' => ['file' => 'modules/notifications/index.php', 'label' => 'Notifications', 'hidden' => true],
    'audit'         => ['file' => 'modules/audit/index.php',         'label' => 'Audit Logs', 'roles' => ['admin']],
];
