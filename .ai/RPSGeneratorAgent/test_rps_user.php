<?php

/**
 * RPS Generation - User Request
 *
 * Loan Details:
 *   - Loan Amount: 46,50,000
 *   - Interest Rate: 12.99%
 *   - EMI Day: 10
 *   - Tenure: 180 months
 *   - Interest Method: monthly (30/36000)
 *
 * Disbursement 1: 2025-09-15, Amount: 30,00,000
 * Disbursement 2: 2026-01-31, Am ount: 5,01,475
 */

function calPMT($interestRateInYear, $loanTermInMonths, $loanAmount)
{
    if (isset($interestRateInYear) && $interestRateInYear != 0 && $interestRateInYear != null) {
        $interestRate = (float)$interestRateInYear / 1200;
        $loanAmount = (float)$loanAmount;
        $loanTerm = (int)$loanTermInMonths;
        return ceil($interestRate * (-$loanAmount * pow((1 + $interestRate), $loanTerm) / (1 - pow((1 + $interestRate), $loanTerm))));
    } else {
        return ceil($loanAmount / $loanTermInMonths);
    }
}

function getFirstEMIDate($disburseDateStr, $interestRate, $emiDay, $forceEMIDate = false)
{
    $disburseDate = new DateTime($disburseDateStr);
    $repaymentDate = clone $disburseDate;
    $disburseDay = (int)$disburseDate->format('d');
    $firstDate = $emiDay ?: $disburseDay;
    $hasPreEMI = false;
    $daysDiff = (int)$disburseDate->format('t');
    $deducted = false;

    $origMonth = (int)$repaymentDate->format('m');
    $origYear = (int)$repaymentDate->format('Y');
    $nextMonth = $origMonth == 12 ? 1 : $origMonth + 1;
    $nextYear = $origMonth == 12 ? $origYear + 1 : $origYear;
    $maxDay = (int)(new DateTime("$nextYear-$nextMonth-01"))->format('t');
    $newDay = min($disburseDay, $maxDay);
    $repaymentDate->setDate($nextYear, $nextMonth, $newDay);
    $daysDiffinEMIDay = $emiDay - $disburseDay;

    if (!$forceEMIDate) {
        if ($interestRate) {
            $repaymentDate->setDate((int)$repaymentDate->format('Y'), (int)$repaymentDate->format('m'), $firstDate);

            if ($disburseDay >= ($firstDate + 1)) {
                $hasPreEMI = true;
                $daysDiff = abs((new DateTime($repaymentDate->format('Y-m-d')))->diff($disburseDate)->days);
            }
            if ($disburseDay <= ($firstDate - 1)) {
                $repaymentDate->modify('-1 month');
                $nearEMIDate = clone $disburseDate;
                $nearEMIDate->setDate((int)$nearEMIDate->format('Y'), (int)$nearEMIDate->format('m'), $firstDate);
                $hasPreEMI = true;
                $daysDiff = abs($nearEMIDate->diff($disburseDate)->days);
                $deducted = ($daysDiffinEMIDay > 0 && $daysDiffinEMIDay < 5) ? true : false;
            }
        }
    }

    return [
        'firsEMI' => $repaymentDate->format('Y-m-d'),
        'hasPreEMI' => $hasPreEMI,
        'diffInDays' => $daysDiff,
        'deducted' => $deducted
    ];
}

function paymentSchedule($roi, $disburseDate, $tenureInMonth, $principleAmount, $emiDay, $interestCalcMethod = 'monthly', $trancheAmount = 0)
{
    $repaymentSchedule = [];
    $emi = calPMT($roi, $tenureInMonth, $principleAmount);
    $principleRemaining = $principleAmount;

    $emiDayAndPreEMI = getFirstEMIDate($disburseDate, $roi, $emiDay);
    $paymentDate = new DateTime($emiDayAndPreEMI['firsEMI']);
    $hasPreEMI = $emiDayAndPreEMI['hasPreEMI'];
    $daysDiff = $emiDayAndPreEMI['diffInDays'];
    $deducted = $emiDayAndPreEMI['deducted'];
    $bpi = 0;

    $trancheBpi = 0;
    if ($trancheAmount > 0 && $daysDiff > 0) {
        $trancheBpi = round($trancheAmount * $daysDiff * ($roi / 36500));
        echo "********** Tranche BPI Days " . $daysDiff . "\n";
        echo "********** Tranche BPI " . $trancheBpi . "\n";
    }

    $hasPreEMI = ((!empty($bpi) && $bpi > 0) || $trancheBpi > 0) ? false : $hasPreEMI;

    $tenureInMonth = $hasPreEMI ? $tenureInMonth + 1 : $tenureInMonth;
    $towardPrinciple = 0;

    for ($i = $tenureInMonth; $i > 0; $i--) {
        if (!$hasPreEMI) {
            if ($interestCalcMethod == 'daily') {
                $interestMultiplier = $daysDiff / 36500;
            } else {
                $interestMultiplier = 30 / 36000;
            }
        } else {
            $interestMultiplier = $daysDiff / 36500;
        }

        // For first EMI with tranche BPI: base interest only on OLD outstanding (exclude tranche)
        // Tranche BPI separately covers interest on the tranche for the broken period
        $interestBase = ($trancheBpi > 0 && $trancheAmount > 0)
            ? ($principleRemaining - $trancheAmount)
            : $principleRemaining;
        $towardInterest = ceil($interestBase * ($roi * $interestMultiplier));

        // Close loan if this is the last iteration OR if EMI would pay off remaining principal
        if (($i - 1) === 0 || (!$hasPreEMI && ($emi - $towardInterest) >= $principleRemaining)) {
            $emi = $principleRemaining + $towardInterest;
            $i = 1; // force last iteration
        }

        $towardPrinciple = 0;
        if (!$hasPreEMI) {
            $towardPrinciple = $emi - $towardInterest;
        }

        $principleRemaining = $principleRemaining - $towardPrinciple;

        $schedule = [
            'repay_date' => $paymentDate->format('Y-m-d'),
            'transaction_type' => $hasPreEMI ? 'pre_emi' : 'emi',
            'repay_amount' => $hasPreEMI ? ($towardInterest + $bpi + $trancheBpi) : ($emi + $bpi + $trancheBpi),
            'repay_interest' => $towardInterest + $bpi + $trancheBpi,
            'repay_principle' => $towardPrinciple,
            'repay_os' => $principleRemaining,
            'repay_roi' => $roi,
            'bpi' => $bpi + $trancheBpi,
        ];
        $repaymentSchedule[] = $schedule;

        $hasPreEMI = false;
        $deducted = false;
        $daysDiff = (int)$paymentDate->format('t');
        $paymentDate->modify('+1 month');
        $bpi = 0;
        $trancheBpi = 0;
    }

    return ['schedule' => $repaymentSchedule, 'emi' => calPMT($roi, ($tenureInMonth - ($emiDayAndPreEMI['hasPreEMI'] ? 1 : 0)), $principleAmount)];
}

// ================================================================
// === PHASE 1: Disbursement 1 ===
// ================================================================
echo "=" . str_repeat("=", 120) . "\n";
echo "PHASE 1: AFTER DISBURSEMENT 1 (2025-09-15, Amount: 30,00,000)\n";
echo "Interest Calculation Method: monthly (30/36000)\n";
echo "=" . str_repeat("=", 120) . "\n";

$roi = 12.99;
$tenure = 180;
$emiDay = 10;
$disbDate1 = '2025-09-15';
$disbAmount1 = 3000000;
$method = 'monthly';

$phase1 = paymentSchedule($roi, $disbDate1, $tenure, $disbAmount1, $emiDay, $method);
$phase1Schedule = $phase1['schedule'];
$emi1 = $phase1['emi'];

echo "EMI (PMT): " . number_format($emi1) . "\n";
echo "Schedule rows: " . count($phase1Schedule) . "\n\n";

echo str_pad("#", 5) . str_pad("Date", 14) . str_pad("EMI Amt", 14) . str_pad("Interest", 14) . str_pad("Principal", 14) .  str_pad("Outstanding", 16) . str_pad("Type", 10) . str_pad("ROI", 8) . "\n";
echo str_repeat("-", 95) . "\n";

$showAllRows = in_array('--full', $argv ?? []);

$printRows = function ($schedule, $startIdx = 0, $showAll = false) use ($showAllRows) {
    $showAll = $showAll || $showAllRows;
    $count = count($schedule);
    foreach ($schedule as $idx => $row) {
        $sn = $startIdx + $idx + 1;
        if ($showAll || $idx < 6 || $idx >= $count - 3) {
            echo str_pad($sn, 5)
                . str_pad($row['repay_date'], 14)
                . str_pad(number_format($row['repay_amount']), 14)
                . str_pad(number_format($row['repay_interest']), 14)
                . str_pad(number_format($row['repay_principle']), 14)
                . str_pad(number_format($row['repay_os']), 16)
                . str_pad($row['transaction_type'], 10)
                . str_pad($row['repay_roi'], 8)
                . "\n";
        } elseif ($idx == 6) {
            echo str_pad("", 5) . "... (" . ($count - 9) . " more rows) ...\n";
        }
    }
};

$printRows($phase1Schedule);

// ================================================================
// === Paid EMIs before Disbursement 2 ===
// ================================================================
echo "\n" . str_repeat("=", 121) . "\n";
echo "PAID REPAYMENTS BEFORE DISBURSEMENT 2 (up to 2026-01-31)\n";
echo str_repeat("=", 121) . "\n";

$paidRows = [];
$disbDate2 = '2026-01-31';
foreach ($phase1Schedule as $idx => $row) {
    if ($row['repay_date'] < $disbDate2) {
        $paidRows[] = $row;
    }
}

echo str_pad("#", 5) . str_pad("Date", 14) . str_pad("EMI Amt", 14) . str_pad("Interest", 14) . str_pad("Principal", 14) .  str_pad("Outstanding", 16) . str_pad("Type", 10) . str_pad("Status", 10) . "\n";
echo str_repeat("-", 95) . "\n";

foreach ($paidRows as $idx => $row) {
    echo str_pad($idx + 1, 5)
        . str_pad($row['repay_date'], 14)
        . str_pad($row['transaction_type'], 10)
        . str_pad(number_format($row['repay_amount']), 14)
        . str_pad(number_format($row['repay_principle']), 14)
        . str_pad(number_format($row['repay_interest']), 14)
        . str_pad(number_format($row['repay_os']), 16)
        . str_pad("PAID", 10)
        . "\n";
}

$lastPaidOS = end($paidRows)['repay_os'];
echo "\nPrincipal Outstanding after last paid EMI: " . number_format($lastPaidOS) . "\n";

// ================================================================
// === PHASE 2: Disbursement 2 ===
// ================================================================
echo "\n" . str_repeat("=", 121) . "\n";
echo "PHASE 2: AFTER DISBURSEMENT 2 (2026-01-31, Amount: 5,01,475)\n";
echo str_repeat("=", 121) . "\n";

$disbAmount2 = 501475;
$newPrincipal = $disbAmount2 + $lastPaidOS;
echo "New Principal = Tranche Amount + Principal Outstanding = " . number_format($disbAmount2) . " + " . number_format($lastPaidOS) . " = " . number_format($newPrincipal) . "\n";

$paidEMICount = count(array_filter($paidRows, fn($r) => $r['transaction_type'] === 'emi'));
$remainingTenure = $tenure - $paidEMICount;
echo "Paid EMIs: {$paidEMICount} | Remaining Tenure: {$remainingTenure} months\n";

$phase2 = paymentSchedule($roi, '2026-01-31', $remainingTenure, $newPrincipal, $emiDay, $method, $disbAmount2);
$phase2Schedule = $phase2['schedule'];
$emi2 = $phase2['emi'];

$emi2Actual = calPMT($roi, $remainingTenure, $newPrincipal);
echo "New EMI (PMT): " . number_format($emi2Actual) . " (for {$remainingTenure} months on " . number_format($newPrincipal) . ")\n";
echo "New Schedule: " . count($phase2Schedule) . " rows\n\n";

echo str_pad("#", 5) . str_pad("Date", 14) . str_pad("EMI Amt", 14) . str_pad("Interest", 14) . str_pad("Principal", 14) .  str_pad("Outstanding", 16) . str_pad("Type", 10) . str_pad("ROI", 8) . "\n";
echo str_repeat("-", 95) . "\n";

$printRows($phase2Schedule);

// ================================================================
// === FINAL COMBINED RPS ===
// ================================================================
echo "\n" . str_repeat("=", 121) . "\n";
echo "FINAL COMBINED RPS (Paid rows from Phase 1 + New rows from Phase 2)\n";
echo str_repeat("=", 121) . "\n";

$finalSchedule = array_merge($paidRows, $phase2Schedule);
echo "Total Rows: " . count($finalSchedule) . "\n";
echo "EMI Rows: " . count(array_filter($finalSchedule, fn($r) => $r['transaction_type'] === 'emi')) . "\n";
echo "Pre-EMI Rows: " . count(array_filter($finalSchedule, fn($r) => $r['transaction_type'] === 'pre_emi')) . "\n\n";

echo str_pad("#", 5) . str_pad("Date", 14) . str_pad("EMI Amt", 14) . str_pad("Interest", 14) . str_pad("Principal", 14) .  str_pad("Outstanding", 16) . str_pad("Type", 10) . str_pad("ROI", 8) . "\n";
echo str_repeat("-", 95) . "\n";

$printRows($finalSchedule);

// ================================================================
// === SUMMARY ===
// ================================================================
echo "\n" . str_repeat("=", 121) . "\n";
echo "SUMMARY\n";
echo str_repeat("=", 121) . "\n";

$totalInterest1 = array_sum(array_column($paidRows, 'repay_interest'));
$totalPrincipal1 = array_sum(array_column($paidRows, 'repay_principle'));
$totalAmount1 = array_sum(array_column($paidRows, 'repay_amount'));

$totalInterest2 = array_sum(array_column($phase2Schedule, 'repay_interest'));
$totalPrincipal2 = array_sum(array_column($phase2Schedule, 'repay_principle'));
$totalAmount2 = array_sum(array_column($phase2Schedule, 'repay_amount'));

$totalInterest = $totalInterest1 + $totalInterest2;
$totalPrincipal = $totalPrincipal1 + $totalPrincipal2;
$totalAmount = $totalAmount1 + $totalAmount2;

echo "Phase 1 Paid - Total Amount: " . number_format($totalAmount1) . " | Principal: " . number_format($totalPrincipal1) . " | Interest: " . number_format($totalInterest1) . "\n";
echo "Phase 2 New  - Total Amount: " . number_format($totalAmount2) . " | Principal: " . number_format($totalPrincipal2) . " | Interest: " . number_format($totalInterest2) . "\n";
echo "Grand Total  - Total Amount: " . number_format($totalAmount) . " | Principal: " . number_format($totalPrincipal) . " | Interest: " . number_format($totalInterest) . "\n";

echo "\nFirst EMI Date: " . $finalSchedule[0]['repay_date'] . "\n";
$emiRows = array_filter($finalSchedule, fn($r) => $r['transaction_type'] === 'emi');
echo "First Regular EMI: " . reset($emiRows)['repay_date'] . "\n";
echo "Last EMI Date: " . end($finalSchedule)['repay_date'] . "\n";
echo "Effective Tenure (EMI count): " . count($emiRows) . " months\n";
