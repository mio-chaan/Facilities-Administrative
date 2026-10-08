<?php
declare(strict_types=1);

function t8_facility_input_value(array $input, string $key): string
{
    return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
}

function t8_facility_validate(string $name, string $location, string $facilityType, string $capacity, array $validLocations): array
{
    $errors = [];
    if ($name === '') {
        $errors[] = 'Facility name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Facility name must be 150 characters or fewer.';
    }
    if ($location === '') {
        $errors[] = 'Location is required.';
    } elseif (mb_strlen($location) > 200) {
        $errors[] = 'Location must be 200 characters or fewer.';
    } elseif ($validLocations !== [] && !in_array($location, $validLocations, true)) {
        $errors[] = 'Please select a location from the list, or add it first with "+ Add Location".';
    }

    $facilityTypes = ['Room', 'Area', 'Equipment', 'Asset', 'Utility'];
    if (!in_array($facilityType, $facilityTypes, true)) {
        $errors[] = 'Please select a valid facility type.';
    }

    $isCapacityType = in_array($facilityType, ['Room', 'Area'], true);
    $measureName = $isCapacityType ? 'Capacity' : 'Quantity';
    $maximum = $isCapacityType ? 100 : 200;
    if (!ctype_digit($capacity)) {
        $errors[] = $measureName . ' must be a whole number.';
    } elseif ((int) $capacity < 1) {
        $errors[] = $measureName . ' must be at least 1.';
    } elseif ((int) $capacity > $maximum) {
        $errors[] = $measureName . ' cannot exceed ' . $maximum . '.';
    }

    return $errors;
}
