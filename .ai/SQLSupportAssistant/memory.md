# Agent Memory

## Schema Knowledge

### Databases
- `sng_production` — LMS (Loan Management System) database
- `sng_crmlos_production` — LOS (Loan Origination System) database
- Both databases share similar table structures for core tables, with exceptions noted below

### Tables & Key Columns

#### `s_applications`
- `id` (PK)
- `uuid`
- `application_status` — e.g. 'approved', 'disbursed', 'closed'
- `disbursed_at` — date of disbursement
- `disbursed_amount` — cumulative disbursed amount (additive via `+=` in code)
- `principal_outstanding` — cumulative outstanding (additive via `+=` in code)
- `loan_amount`
- `is_colending`
- `secondary_external_id` — links LMS app to LOS (format: `los:<los_app_id>`)
- `is_full_disbursed`

#### `s_disbursement_request_favouring`
- `id` (PK)
- `application_id` (FK -> s_applications.id)
- `disbursment_request_id` (FK -> s_disbursement_requests.id) — note: typo "disbursment" is in the actual schema
- `disburse_date`
- `transaction_date`
- `disburse_status` — e.g. 'pending', 'pushed-to-cib', 'processing', 'disbursed', 'rejected', 'failed'
- `trans_ref_no` — UTR number
- `transfer_mode` — e.g. 'electronic'
- `favouring_amount`
- `account_number`, `ifsc_code`, `holder_name`, `favouring_name`
- `source_bank_id`
- `reference_no_1`, `reference_no_2`
- `maker_id`, `disbursed_by`

#### `s_disbursement_requests`
- `id` (PK)
- `application_id` (FK -> s_applications.id)
- `request_id` — human-readable request identifier
- `request_amount`
- `total_transfer_amount`
- `total_deduction_amount`
- `disb_status` — e.g. 'created', 'approved', 'processing', 'disbursed', 'pending'
- `disbursed_at`, `transaction_date`
- `deductions` (JSON) — contains bp_deduction, pf_deduction, fc_deduction, etc.
- `request_date`

#### `s_repayment_schedule` *(LMS only — does not exist in LOS)*
- `id` (PK)
- `application_id` (FK -> s_applications.id)
- `repay_amount`
- `repay_interest`
- `bpi` — broken period interest
- `repay_date`
- Repayments are managed exclusively in LMS

#### `s_application_charges`
- `id` (PK)
- `application_id` (FK -> s_applications.id)
- `charge_id` — master charge config id (e.g. 24 for 'Change in EMI Date')
- `charge_type` — stores the iden_key slug (e.g. 'emi_date_change_fees', 'processing_fees', 'broken_interest'). NOT the display name.
- `charge_for` — e.g. 'user'
- `charge_applied_on` — datetime the charge was applied
- `charge_rate`, `charge_percentage` — nullable
- `total_amount` (gross, e.g. 590), `amount` (net, e.g. 500), `gst_amount` (e.g. 90)
- `charge_collected` — amount collected (mirrors total_amount when fully paid)
- `amount_waived_off` — nullable
- `charge_collected_at` — date
- `status` — e.g. 'pending', 'collected', 'waive_off', 'created'
- `collection_method` — e.g. 'upi', 'drf', etc.
- `transaction_at` — date
- `transaction_utr`
- `request_type`, `charge_ref_1`, `charge_ref_2` — nullable
- `invoice_id`, `invoice_created` (0/1)

#### `s_payment_orders`
- `id` (PK, BIGINT UNSIGNED)
- `application_id` (FK -> s_applications.id)
- `schedule_date` — scheduled payment date
- `amount` — payment amount (DECIMAL 19,2)
- `total_attempt`, `failed_attempt`
- `status` — ENUM: 'created', 'pending_approval', 'initiated', 'failed', 'success', 'settled', 'hold', 'approved', 'rejected', 'waived_off'
- `payment_channel` — ENUM: 'bank_transfer', 'nach', 'cash', 'upi', 'bbps', 'drf', 'approval', 'manual', 'payment_gateway', 'eb-easy'
- `payment_utr`
- `maker_id`, `checker_id`, `checker_action`, `checker_message`
- `extra`
- `is_customer_notified`

#### `s_payment_order_transactions`
- `id` (PK, INT UNSIGNED)
- `application_id` (FK -> s_applications.id)
- `payment_order_id` (FK -> s_payment_orders.id)
- `trans_identifier` — unique transaction ID
- `mandate_id`
- `amount` — debit amount (DECIMAL 19,2)
- `status` — ENUM: 'created', 'initiated', 'schedule_failed', 'success', 'failed', 'settled', 'waived_off', 'approved', 'rejected'
- `trans_status` — ENUM: 'success', 'failed'
- `trans_channel` — ENUM: same as payment_channel
- `schedule_date`, `scheduled_at`, `transaction_date`, `settled_at`
- `transaction_utr`, `partner_trans_identifier`
- `settlement_id` — FK to bank settlements
- `payment_link`, `extras` (JSON), `message`
- `recon_status` — reconciliation flag (0/1)

#### `s_payment_dues`
- `id` (PK)
- `application_id` (FK -> s_applications.id)
- `due_date` — date the payment was due
- `due_amount` — original due amount
- `unpaid_amount` — remaining unpaid
- `paid_amount` — amount paid
- `type` — e.g. 'interest', 'principal', 'repayment', 'application' (for charge dues, type='application' confirmed 2026-04-22)
- `sub_type` — e.g. 'pre_emi', 'bpi', 'emi', 'charges' (for charge-type dues, sub_type='charges' confirmed 2026-04-22)
- `repayment_id` (FK -> s_repayment_schedule.id) — nullable
- `charge_id` (FK -> s_application_charges.id) — nullable. Note: this references the s_application_charges PK (e.g. 33870), NOT the master charge config id.
- `dpd` — days past due
- `category` — for charge dues, set to the iden_key (e.g. 'emi_date_change_fees'). For repayment dues: 'pre_emi', 'paid', 'unpaid'.
- `created_by`, `updated_by`
- Model: `app/Models/V1/Collection/PaymentDues.php`
- Static helpers: `PaymentDues::createDuePost()`, `PaymentDues::saveDue()`

#### `s_payment_dues_order_mapping`
- `id` (PK)
- `application_id` (FK -> s_applications.id)
- `payment_order_id` (FK -> s_payment_orders.id)
- `due_id` (FK -> s_payment_dues.id)
- `amount`
- Model: `app/Models/V1/Collection/PaymentDuesOrderMapping.php`
- Relationship: `PaymentOrders` has many-to-many with `PaymentDues` through this table

#### `s_sync_master`
- Maps IDs between LMS and LOS systems
- `ref_type`, `parameter`, `ref_id`, `ref_parameter_value` (LMS side)
- `sync_from`, `sync_ref_id`, `sync_parameter_value` (LOS side)
- Parameters: 'dbr_id', 'dbf_id', 'charge_id', 'payment_due_id'

### Cross-Database ID Mapping (LMS <-> LOS)
- Application IDs differ between LMS and LOS
- `s_application_charges.id` values can coincidentally match across databases but represent different records
- `s_sync_master` is the authoritative mapping table

## Business Rules

### Disbursement Flow
1. Disbursement request is created (status: 'created')
2. Request is approved (status: 'approved')
3. Favouring is pushed to CIB (status: 'pushed-to-cib')
4. Cron `transactionStatusCheck()` polls CIB for status
5. On SUCCESS: favouring -> 'disbursed', then `updateDRFAfterFavouringDisbursal()` runs
6. After all favourings disbursed: DRF status -> 'disbursed', application status -> 'disbursed'
7. `disbursed_amount` and `principal_outstanding` are incremented additively (`+=`)
8. HTTP callback is sent to LOS with disbursement data
9. Repayment schedule is generated
10. Accounting ledger events are dispatched (with 1-minute delay)

### Charge Types on Disbursement
- Processing fees (can be deducted, waived, or pre-paid)
- Broken period interest (BPI)
- Subvention deduction (for subvented products)
- Foreclosure of old loan deduction

### Application Status Flow
- approved -> disbursed -> closed

### Marking a Repayment as Paid via DRF Channel (Confirmed 2026-03-31)

When manually marking a repayment as paid where the channel is DRF (deducted from disbursement), the full workflow requires 5 steps in a single transaction:

```sql
START TRANSACTION;

-- Step 1: Payment order
INSERT INTO s_payment_orders
    (application_id, schedule_date, amount, status, payment_channel, payment_utr, extra, created_at, updated_at)
VALUES
    (:app_id, :disburse_date, :amount, 'success', 'drf', :utr,
     JSON_OBJECT('drf_id', :drf_id, 'drf_favouring_id', :favouring_id),
     NOW(), NOW());
SET @po_id = LAST_INSERT_ID();

-- Step 2: Payment order transaction
INSERT INTO s_payment_order_transactions
    (application_id, payment_order_id, amount, status, trans_status, trans_channel,
     schedule_date, transaction_date, settled_at, transaction_utr, recon_status, created_at, updated_at)
VALUES
    (:app_id, @po_id, :amount, 'success', 'success', 'drf',
     :disburse_date, :disburse_date, :disburse_date, :utr, 0, NOW(), NOW());

-- Step 3: Payment due
-- type='repayment', sub_type='interest', category='pre_emi', paid_amount=0.00 at creation
INSERT INTO s_payment_dues
    (application_id, due_date, due_amount, paid_amount, type, sub_type, repayment_id, charge_id, dpd, category, created_at, updated_at)
VALUES
    (:app_id, :disburse_date, :amount, 0.00, 'repayment', 'interest', :repayment_id, NULL, 0, 'pre_emi', NOW(), NOW());
SET @due_id = LAST_INSERT_ID();

-- Step 4: Due-order mapping
INSERT INTO s_payment_dues_order_mapping
    (application_id, payment_order_id, due_id, amount, created_at, updated_at)
VALUES
    (:app_id, @po_id, @due_id, :amount, NOW(), NOW());

-- Step 5: Mark repayment schedule paid (no pending_emi_amount update)
UPDATE s_repayment_schedule SET
    repay_status        = 1,
    repaid_at           = :disburse_datetime,
    settled_at          = :disburse_datetime,
    interest_collected  = :interest_amount,
    principle_collected = :principal_amount,
    last_order_status   = 'success',
    transaction_utr     = :utr,
    updated_at          = NOW()
WHERE id = :repayment_id AND application_id = :app_id AND repay_status = 0;

SELECT ROW_COUNT() AS rows_updated; -- Must be 1 before COMMIT

COMMIT;
```

**Key confirmed values for DRF pre_emi repayment:**
- `s_payment_dues.type` = `'repayment'`
- `s_payment_dues.sub_type` = `'interest'`
- `s_payment_dues.category` = `'pre_emi'`
- `s_payment_dues.paid_amount` = `0.00` at INSERT time
- `s_payment_dues.unpaid_amount` — omitted from INSERT
- `s_repayment_schedule.pending_emi_amount` — NOT updated in this workflow

### Marking a Charge as Collected via UPI (Confirmed 2026-04-22)

Full 5-step workflow for recording a charge payment (e.g. EMI date change fee) collected via UPI:

```sql
START TRANSACTION;

-- 1. Application charge row
INSERT INTO s_application_charges
    (application_id, charge_id, charge_type, charge_for, charge_applied_on,
     total_amount, amount, gst_amount, charge_collected, charge_collected_at,
     status, collection_method, transaction_at, transaction_utr,
     invoice_created, created_at, updated_at)
VALUES
    (:app_id, :master_charge_id, :iden_key, 'user', :applied_at,
     :total, :net_amount, :gst, :total, :date,
     'collected', :channel, :date, :utr,
     0, NOW(), NOW());
SET @charge_id = LAST_INSERT_ID();

-- 2. Payment order
INSERT INTO s_payment_orders
    (application_id, schedule_date, amount, total_attempt, failed_attempt,
     status, payment_channel, payment_utr, is_customer_notified, created_at, updated_at)
VALUES
    (:app_id, :date, :total, 1, 0, 'success', :channel, :utr, 0, NOW(), NOW());
SET @po_id = LAST_INSERT_ID();

-- 3. Payment order transaction
INSERT INTO s_payment_order_transactions
    (application_id, payment_order_id, amount, status, trans_status, trans_channel,
     schedule_date, scheduled_at, transaction_date, settled_at,
     transaction_utr, recon_status, created_at, updated_at)
VALUES
    (:app_id, @po_id, :total, 'success', 'success', :channel,
     :datetime, :datetime, :datetime, :datetime, :utr, 0, NOW(), NOW());

-- 4. Payment due
INSERT INTO s_payment_dues
    (application_id, category, type, sub_type, due_date, due_amount, paid_amount,
     repayment_id, charge_id, dpd, created_at, updated_at)
VALUES
    (:app_id, :iden_key, 'application', 'charges', :date, :total, :total,
     NULL, @charge_id, 0, NOW(), NOW());
SET @due_id = LAST_INSERT_ID();

-- 5. Due-order mapping
INSERT INTO s_payment_dues_order_mapping
    (application_id, payment_order_id, due_id, amount, created_at, updated_at)
VALUES
    (:app_id, @po_id, @due_id, :total, NOW(), NOW());

COMMIT;
```

**Key confirmed values for charge collection via UPI:**
- `s_application_charges.charge_type` = the iden_key slug (e.g. `'emi_date_change_fees'`), not the display name
- `s_application_charges.charge_id` = the master charge config id (e.g. `24` for EMI date change)
- `s_application_charges.charge_for` = `'user'`
- `s_application_charges.charge_collected` = `total_amount` when fully paid
- `s_payment_dues.type` = `'application'` (for charge dues)
- `s_payment_dues.sub_type` = `'charges'`
- `s_payment_dues.category` = the iden_key (e.g. `'emi_date_change_fees'`)
- `s_payment_dues.charge_id` = `s_application_charges.id` (the newly created row's PK), NOT the master charge config id
- GST split: for 18% GST on ₹500 -> total 590 = net 500 + gst 90

## Common Issue Patterns

### 1. Callback-before-commit rollback (confirmed 2026-02-04)
- **Root cause**: `transactionStatusCheck()` cron wraps DB operations in a transaction, calls `updateDRFAfterFavouringDisbursal()` which sends HTTP callback to LOS inside the transaction scope. If exception occurs after callback but before commit, LMS rolls back but LOS already processed the data.
- **Effect**: Cron re-runs, finds favouring still in 'pushed-to-cib', re-sends callback. LOS `disbursed_amount` and `principal_outstanding` get incremented repeatedly.
- **Example**: 50 Lakh -> 95 Crores over 4 days of cron runs.
- **Fix approach**:
  - **LMS**: Dates only (`disbursed_at`, `disburse_date`, `transaction_date`, `charge_collected_at`, `transaction_at`) + BPI/repayment amounts. LMS amounts (`disbursed_amount`, `principal_outstanding`) remain correct because the transaction rolled back.
  - **LOS**: Dates + correct `disbursed_amount` and `principal_outstanding` (these got inflated from repeated callbacks).
- **Affected tables**:
  - LMS: s_applications, s_disbursement_requests, s_disbursement_request_favouring, s_repayment_schedule, s_application_charges
  - LOS: s_applications, s_disbursement_requests, s_disbursement_request_favouring, s_application_charges (no s_repayment_schedule in LOS)
- **Code location**: TransactionController.php:169-231, DisbursementController.php:431-551

## Verified Assumptions

- LMS database: `sng_production`
- LOS database: `sng_crmlos_production`
- LMS and LOS share the same table structures but have different application IDs
- `disbursed_amount` and `principal_outstanding` use additive assignment (`+=`) — not idempotent
- HTTP callbacks to LOS cannot be rolled back
- The `disbursment_request_id` column name contains a typo (missing 'e') — this is intentional/legacy
