# RPSGeneratorAgent - Repayment Schedule Generator

## Purpose
Generate Repayment Payment Schedules (RPS) for loans with support for multiple tranche disbursements and Broken Period Interest (BPI). This agent simulates the logic from `RepaymentController::paymentSchedule()` without needing a database.

## When Invoked
Ask the user for:

### 1. Loan Details
- **Loan Amount** (total sanctioned amount)
- **Interest Rate** (annual %, e.g. 12.99)
- **Tenure** (in months, e.g. 180)
- **EMI Day** (day of month for EMI collection, e.g. 10)
- **Interest Calculation Method**:
  - `daily` = actual days in month / 365 (daysDiff/36500)
  - `monthly` = 30/365 (30/36000)

### 2. Disbursement Details (one or more tranches)
For each disbursement:
- **Disbursement Date** (YYYY-MM-DD)
- **Amount**

## Core Algorithms

### PMT (EMI Calculation)
```
EMI = ceil( r * (-P * (1+r)^n) / (1 - (1+r)^n) )
where:
  r = annual_rate / 1200 (monthly rate)
  P = principal amount
  n = tenure in months
```
If interest rate is 0: `EMI = ceil(P / n)`

### First EMI Date Calculation (`getFirstEMIDate`)
Given disbursement date and EMI day:

1. Start with `repaymentDate = disburseDate + 1 month` (no overflow)
2. Set the day to `emiDay`
3. **If disburseDay >= emiDay + 1**:
   - `hasPreEMI = true`
   - `daysDiff = repaymentDate - disburseDate` (in days)
4. **If disburseDay <= emiDay - 1**:
   - `hasPreEMI = true`
   - Go back 1 month on repaymentDate
   - `daysDiff = nearEMIDate - disburseDate` (where nearEMIDate = same month, emiDay)
   - `deducted = true` if `emiDay - disburseDay` is between 1-4
5. **If disburseDay == emiDay**: `hasPreEMI = false`, `daysDiff = daysInMonth`

### Interest Multiplier
- **Pre-EMI**: Always uses `daysDiff / 36500`
- **Regular EMI (daily method)**: `daysDiff / 36500` where daysDiff = days in previous month
- **Regular EMI (monthly method)**: `30 / 36000`

### BPI (Broken Period Interest) - Two Types

#### 1. Part Payment BPI (`$bpi`)
- Calculated from part payment details
- **Added ON TOP of EMI amount** (increases repay_amount)
- Formula: `round(partPaymentAmount * daysDiff * (rate / 36500))`

#### 2. Tranche BPI (`$trancheBpi`)
- Calculated when a new tranche is disbursed
- **Added ON TOP of EMI amount** (increases repay_amount for the first month only)
- Formula: `round(trancheAmount * daysDiff * (rate / 36500))`
- Added to `repay_amount` and `repay_interest`, NOT absorbed into base interest calculation

Both types force `hasPreEMI = false` (no separate pre-EMI entry).

### Payment Schedule Generation Loop

```
For each month i from tenureInMonth down to 1:
  1. Calculate interestMultiplier based on method and hasPreEMI
  2. towardInterest = ceil(principleRemaining * roi * interestMultiplier)
  3. If last EMI (i-1 == 0) OR (emi - towardInterest >= principleRemaining): emi = principleRemaining + towardInterest
  4. If hasPreEMI: towardPrinciple = 0 (interest-only)
     Else: towardPrinciple = emi - towardInterest
  5. principleRemaining -= towardPrinciple
  6. Record schedule entry:
     - repay_amount = emi + bpi + trancheBpi (BPI amounts added on top)
     - repay_interest = towardInterest + bpi + trancheBpi
     - repay_principle = towardPrinciple
  7. Reset: hasPreEMI = false, daysDiff = daysInMonth, bpi = 0, trancheBpi = 0
```

### Multi-Tranche Disbursement Flow
When a 2nd (or subsequent) tranche arrives:

1. Identify paid EMIs from Phase 1 (rows with dates before tranche disburse date)
2. Get `lastPaidOS` = outstanding after last paid EMI
3. `newPrincipal = trancheAmount + lastPaidOS`
4. `paidEMICount` = count of paid EMI-type rows (exclude pre_emi)
5. `remainingTenure = originalTenure - paidEMICount`
6. Generate new schedule: `paymentSchedule(roi, trancheDate, remainingTenure, newPrincipal, emiDay, method, trancheAmount)`
7. Final RPS = paid rows from Phase 1 + new Phase 2 schedule

## Output Format
Display as a table with columns:
| # | Date | Type | EMI Amt | Principal | Interest | Outstanding | ROI |

Show first 6 rows + last 3 rows. Use `--full` flag for all rows.

Include summary:
- Total Amount, Principal, Interest per phase
- First EMI Date, Last EMI Date
- Effective Tenure (EMI count)

## Reference Implementation
See `.ai/RPSGeneratorAgent/test_rps_user.php` for a standalone PHP simulation with the latest logic (tranche BPI on top).
See `test_rps_generation.php` in project root for the original simulation (tranche BPI absorbed - older logic).
See `app/Http/Controllers/V1/Cases/RepaymentController.php` for the actual Laravel implementation.

## Key Source Files
- `app/Http/Controllers/V1/Cases/RepaymentController.php` - `paymentSchedule()`, `generateRepaymentSchedule()`
- `app/Helpers/CommonHelper.php` - `calPMT()`
- `app/Helpers/ApplicationHelper.php` - `getFirstEMIDate()`
- `app/Traits/V1/RepaymentTraits.php` - Co-lending BPI methods
- `.ai/RPSGeneratorAgent/test_rps_user.php` - Latest standalone simulation (tranche BPI on top)
- `test_rps_generation.php` - Original standalone simulation (tranche BPI absorbed)
