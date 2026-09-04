<?php

declare(strict_types=1);

$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/2026_09_04_audit_hardening.sql');

if ($schema === false || $migration === false) {
    throw new RuntimeException('Facility-location schema files could not be read.');
}
foreach ([$schema, $migration] as $source) {
    if (!str_contains($source, 'team8_facility_locations')) {
        throw new RuntimeException('Facility-location table definition is missing.');
    }
}
if (!str_contains($schema, 'name       VARCHAR(150) NOT NULL UNIQUE')) {
    throw new RuntimeException('Facility locations must be unique by name.');
}

echo "facility location schema test passed\n";
