<?php
// Unit test for conflict annotation logic (no DB required)
$now = time();
function fmt($t) { return date('Y-m-d H:i:s', $t); }

$rows = [
    // existing approved ongoing
    ['id'=>1,'facility_id'=>1,'start_time'=>fmt($now-60),'end_time'=>fmt($now+60),'status'=>'approved'],
    // reservation under test (ongoing) - should show conflict
    ['id'=>2,'facility_id'=>1,'start_time'=>fmt($now-30),'end_time'=>fmt($now+30),'status'=>'approved'],
    // future overlapping - should NOT show conflict now
    ['id'=>3,'facility_id'=>1,'start_time'=>fmt($now+3600),'end_time'=>fmt($now+3660),'status'=>'approved'],
    // past overlapping - should NOT show conflict now
    ['id'=>4,'facility_id'=>1,'start_time'=>fmt($now-7200),'end_time'=>fmt($now-7100),'status'=>'approved'],
];

function overlaps($aStart,$aEnd,$bStart,$bEnd) {
    return strtotime($aStart) < strtotime($bEnd) && strtotime($aEnd) > strtotime($bStart);
}

function has_conflict_in_set($rows,$row) {
    foreach ($rows as $r) {
        if ($r['id'] === $row['id']) continue;
        if ($r['facility_id'] !== $row['facility_id']) continue;
        if ($r['status'] !== 'approved') continue;
        if (overlaps($row['start_time'],$row['end_time'],$r['start_time'],$r['end_time'])) return true;
    }
    return false;
}

function annotate_conflicts(array $rows) {
    $now = time();
    foreach ($rows as &$row) {
        $start = isset($row['start_time']) && $row['start_time'] !== '' ? strtotime($row['start_time']) : false;
        $end = isset($row['end_time']) && $row['end_time'] !== '' ? strtotime($row['end_time']) : false;
        if ($start !== false && $end !== false && $now >= $start && $now <= $end) {
            $row['has_conflict'] = has_conflict_in_set($rows,$row);
        } else {
            $row['has_conflict'] = false;
        }
    }
    unset($row);
    return $rows;
}

$result = annotate_conflicts($rows);
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

// Assert expected outcomes
$expected = [
    1 => true, // existing ongoing should be true (there is another ongoing approved)
    2 => true, // ongoing should be true
    3 => false,
    4 => false,
];
foreach ($result as $r) {
    $id = $r['id'];
    $got = $r['has_conflict'] ? true : false;
    $exp = $expected[$id];
    echo "Row {$id}: has_conflict={$got} expected={$exp} -> " . ($got === $exp ? "OK" : "FAIL") . "\n";
}

return 0;
