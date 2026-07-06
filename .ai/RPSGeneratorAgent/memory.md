# RPSGeneratorAgent Memory

## Business Rules Learned

### BPI Types - Critical Distinction
- **Part Payment BPI** (`$bpi`): Added ON TOP of EMI. Customer pays more in that month.
- **Tranche BPI** (`$trancheBpi`): ABSORBED into interest. EMI stays the same, principal component decreases.
- Both force `hasPreEMI = false` — no separate pre-EMI row is generated.

### Interest Calculation Methods
- `daily`: Uses actual days in month / 365 (`daysDiff / 36500`). Each month's interest varies by days.
- `monthly` (standard): Uses fixed 30/365 (`30 / 36000`). Same multiplier every month.
- Pre-EMI ALWAYS uses daily method regardless of config.
- The user's default preference is `daily` method.

### EMI Day Logic
- If disburseDay == emiDay: no pre-EMI, daysDiff = daysInMonth
- If disburseDay > emiDay: pre-EMI, daysDiff = next emiDay - disburseDate
- If disburseDay < emiDay: pre-EMI, daysDiff = current emiDay - disburseDate, deducted if gap < 5 days

### Tranche Disbursement
- When new tranche arrives: delete unpaid RPS, recalculate from new total outstanding
- `newPrincipal = trancheAmount + principal_outstanding` (from last paid EMI)
- `remainingTenure = tenure_months - paidEMICount` (only count 'emi' type, not 'pre_emi')
- `emiPerMonth` stored on application = regular PMT EMI (without BPI)

### Last EMI Adjustment
- Always adjusts to close the loan: `emi = principleRemaining + towardInterest`
- With tranche BPI absorbed, last EMI will be larger than regular EMI (because less principal is paid in first month)

## Test Scenario (Reference)
- ROI: 12.99%, Tenure: 180 months, EMI Day: 10
- Disb 1: 2025-09-15, 3,000,000 -> EMI = 37,938, Pre-EMI = 26,692
- Disb 2: 2026-01-31, 501,475 -> New EMI = 44,315
  - Tranche BPI = round(501,475 * 10 * 12.99/36500) = 1,785
  - Feb 10 EMI = 44,315 (interest 39,510 includes BPI, principal 4,805)
  - Mar 10+ EMI = 44,315 (regular split)

## Pending Tasks / Future Work
- Consider whether daily interest method should NOT add tranche BPI (since daysDiff-based interest already covers the broken period on total outstanding including tranche)
- Part payment + tranche in same cycle: both BPIs should coexist (part payment adds to EMI, tranche absorbs into interest)

## Code Changes Made (Feb 2026)
1. `RepaymentController::paymentSchedule()` - Added `$trancheAmount` (9th param), `$trancheBpi` variable
2. `RepaymentController::generateRepaymentSchedule()` - Passes `$trancheAmount` through to `paymentSchedule`
3. `test_rps_generation.php` - Updated simulation with tranche BPI logic, default method changed to `daily`
