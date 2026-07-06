<?php

/**
 * RPS VERIFICATION - Application 11849
 *
 * Verifies the user-supplied SQL INSERT for s_repayment_schedule against the
 * RPSGeneratorAgent simulation logic.
 *
 * Loan Details (from SQL + user confirmation):
 *   - Loan Amount: 16,00,000 (first repay_os)
 *   - Interest Rate: 16.50%
 *   - EMI Day: 10
 *   - Disbursement Date: 2026-05-25
 *   - Interest Method: monthly (30/36000)
 *   - Schedule: 1 pre-EMI (2026-06-10) + 180 EMIs (2026-07-10 .. 2041-06-10)
 */

require __DIR__ . '/rps_engine.php';

// ---- Loan inputs ----
$roi        = 16.50;
$tenure     = 180;
$emiDay     = 10;
$disbDate   = '2026-05-25';
$loanAmount = 1600000;
$method     = 'monthly';

// ---- Expected schedule from the user's SQL INSERT (parsed into expected_11849.php) ----
$expected = require __DIR__ . '/expected_11849.php';

// ---- Generate via engine ----
$result   = paymentSchedule($roi, $disbDate, $tenure, $loanAmount, $emiDay, $method);
$schedule = $result['schedule'];
$emi      = $result['emi'];

echo str_repeat('=', 110) . "\n";
echo "APPLICATION 11849 - RPS VERIFICATION\n";
echo "Disb 2026-05-25 | Amount 16,00,000 | ROI 16.50% | EMI Day 10 | 180 months | monthly(30/360)\n";
echo str_repeat('=', 110) . "\n";
echo "PMT EMI: " . number_format($emi) . "\n";
echo "Generated rows: " . count($schedule) . " | Expected rows: " . count($expected) . "\n\n";

// ---- Row-by-row diff ----
$hdr = str_pad('#', 4) . str_pad('Date', 13) . str_pad('Type', 7)
     . str_pad('GenAmt', 11) . str_pad('ExpAmt', 11)
     . str_pad('GenInt', 11) . str_pad('ExpInt', 11)
     . str_pad('GenPrin', 10) . str_pad('ExpPrin', 10)
     . str_pad('GenOS', 13) . str_pad('ExpOS', 13) . 'Match';
echo $hdr . "\n" . str_repeat('-', strlen($hdr) + 5) . "\n";

$mismatchCount = 0;
$max = max(count($schedule), count($expected));
for ($i = 0; $i < $max; $i++) {
    $g = $schedule[$i] ?? null;
    $e = $expected[$i] ?? null;

    $gDate = $g['repay_date']      ?? '--';
    $gType = $g ? ($g['transaction_type'] === 'pre_emi' ? 'pemi' : 'emi') : '--';
    $gAmt  = $g ? (int)round($g['repay_amount'])    : null;
    $gInt  = $g ? (int)round($g['repay_interest'])  : null;
    $gPrin = $g ? (int)round($g['repay_principle']) : null;
    $gOS   = $g ? (int)round($g['repay_os'])        : null;

    $eDate = $e[0] ?? '--';
    $eAmt  = $e[1] ?? null;
    $eInt  = $e[2] ?? null;
    $ePrin = $e[3] ?? null;
    $eOS   = $e[4] ?? null;
    $eType = $e[5] ?? '--';

    $ok = ($gDate === $eDate) && ($gType === $eType)
        && ($gAmt === $eAmt) && ($gInt === $eInt)
        && ($gPrin === $ePrin) && ($gOS === $eOS);
    if (!$ok) {
        $mismatchCount++;
    }

    // Print first 8, last 4, and every mismatch
    if ($i < 8 || $i >= $max - 4 || !$ok) {
        echo str_pad($i + 1, 4)
            . str_pad($gDate, 13)
            . str_pad($gType, 7)
            . str_pad(number_format((int)$gAmt), 11)
            . str_pad(number_format((int)$eAmt), 11)
            . str_pad(number_format((int)$gInt), 11)
            . str_pad(number_format((int)$eInt), 11)
            . str_pad(number_format((int)$gPrin), 10)
            . str_pad(number_format((int)$ePrin), 10)
            . str_pad(number_format((int)$gOS), 13)
            . str_pad(number_format((int)$eOS), 13)
            . ($ok ? 'OK' : 'XX')
            . "\n";
    } elseif ($i === 8) {
        echo str_pad('', 4) . "... (matching rows hidden) ...\n";
    }
}

echo "\n" . str_repeat('=', 110) . "\n";
echo $mismatchCount === 0
    ? "RESULT: PERFECT MATCH - all " . count($expected) . " rows identical.\n"
    : "RESULT: {$mismatchCount} mismatched row(s) out of {$max}.\n";
echo str_repeat('=', 110) . "\n";

// Totals comparison
$genInt = array_sum(array_map(fn($r) => round($r['repay_interest']), $schedule));
$genPrin = array_sum(array_map(fn($r) => round($r['repay_principle']), $schedule));
$expInt = array_sum(array_column($expected, 2));
$expPrin = array_sum(array_column($expected, 3));
echo "Total Interest : gen " . number_format($genInt) . " | exp " . number_format($expInt) . "\n";
echo "Total Principal: gen " . number_format($genPrin) . " | exp " . number_format($expPrin) . "\n";
