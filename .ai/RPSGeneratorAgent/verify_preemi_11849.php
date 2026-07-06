<?php
// Verify the pre-EMI of 13,140 as sum of broken-period interest on both tranches.
$roi = 16.50;
$firstEmi = new DateTime('2026-06-10');

$tranches = [
    ['amt' => 495193,  'disb' => '2026-05-18'],
    ['amt' => 1104807, 'disb' => '2026-05-25'],
];

echo "Pre-EMI breakdown (broken-period interest to first EMI 2026-06-10):\n";
echo str_repeat('-', 70) . "\n";
$total = 0;
foreach ($tranches as $t) {
    $d = new DateTime($t['disb']);
    $days = $d->diff($firstEmi)->days;
    // try both ceil and round, daily basis /36500
    $rawCeil  = ceil($t['amt'] * $days * ($roi / 36500));
    $rawRound = round($t['amt'] * $days * ($roi / 36500));
    printf("Tranche %s | %s -> %d days | ceil=%s round=%s\n",
        number_format($t['amt']), $t['disb'], $days,
        number_format($rawCeil), number_format($rawRound));
    $total += $rawCeil;
}
echo str_repeat('-', 70) . "\n";
echo "Sum (ceil each): " . number_format($total) . "\n";

// Also: ceil of the summed raw
$rawSum = 0;
foreach ($tranches as $t) {
    $d = new DateTime($t['disb']);
    $days = $d->diff($firstEmi)->days;
    $rawSum += $t['amt'] * $days * ($roi / 36500);
}
echo "Ceil of summed raw: " . number_format(ceil($rawSum)) . "\n";
echo "Round of summed raw: " . number_format(round($rawSum)) . "\n";
echo "\nExpected from SQL: 13,140\n";
