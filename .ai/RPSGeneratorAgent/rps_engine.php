<?php

/**
 * RPS Engine - shared simulation functions extracted from test_rps_user.php.
 * Mirrors RepaymentController::paymentSchedule(), CommonHelper::calPMT(),
 * ApplicationHelper::getFirstEMIDate().
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

        $interestBase = ($trancheBpi > 0 && $trancheAmount > 0)
            ? ($principleRemaining - $trancheAmount)
            : $principleRemaining;
        $towardInterest = ceil($interestBase * ($roi * $interestMultiplier));

        if (($i - 1) === 0 || (!$hasPreEMI && ($emi - $towardInterest) >= $principleRemaining)) {
            $emi = $principleRemaining + $towardInterest;
            $i = 1;
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
