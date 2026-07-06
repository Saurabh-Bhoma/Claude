<?php
// Parse sql_11849.txt VALUES into expected_11849.php
// Column order in SQL: application_id, repay_date, repay_amount, repay_interest,
//                      repay_principle, repay_os, repay_roi, transaction_type
$raw = file_get_contents(__DIR__ . '/sql_11849.txt');
preg_match_all("/\(\s*\d+\s*,\s*'([\d-]+)'\s*,\s*'?(\d+)'?\s*,\s*'?(\d+)'?\s*,\s*'?(\d+)'?\s*,\s*'?(\d+)'?\s*,\s*'?[\d.]+'?\s*,\s*'(\w+)'\s*\)/", $raw, $m, PREG_SET_ORDER);

$rows = [];
foreach ($m as $r) {
    $rows[] = [
        $r[1],            // repay_date
        (int)$r[2],       // repay_amount
        (int)$r[3],       // repay_interest
        (int)$r[4],       // repay_principle
        (int)$r[5],       // repay_os
        $r[6],            // transaction_type (pemi / emi)
    ];
}

$out = "<?php\nreturn " . var_export($rows, true) . ";\n";
file_put_contents(__DIR__ . '/expected_11849.php', $out);
echo "Parsed " . count($rows) . " rows into expected_11849.php\n";
