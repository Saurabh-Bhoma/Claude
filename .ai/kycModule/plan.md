# KYC Module — Implementation Plan

## Phase 0 — Foundation (Database & Models)

**Goal:** Create all tables and Eloquent models before any feature work.

### Tasks

1. **Create migrations** in dependency order:
   ```
   s_accounts
     → s_applicant_kyc
       → s_account_kyc_links
       → s_applicants
         → s_applicant_documents
         → s_applicant_kyc_documents
         → s_applicant_kyc_history
   s_consents
   s_kyc_verifications
   s_kyc_match_candidates
   s_kyc_audit_logs
   ```

2. **Create Eloquent Models** (`app/Models/V1/`) for each table:
   - Set `$connection`, `$table` (with `s_` prefix), `$fillable`
   - Define relationships (`hasMany`, `belongsTo`, `belongsToMany`)

---

## Phase 1 — Account & Authentication Layer

**Goal:** Customer can register, log in, and pass identity re-verification.

### Tasks

3. **OTP Service** — generate, send (SMS mandatory, email optional), verify (time-bound TTL).

4. **Account creation endpoints** for all 3 journeys:
   - Customer Portal: registration form → OTP → `PENDING → ACTIVE`
   - Partner API: create `PENDING` account, link to lead
   - RM: create `PENDING` account, notify customer via SMS + email

5. **Inactivity check** on login — compare `last_login_at` against 180-day threshold.

6. **Identity re-verification form** — Individual (Name on PAN, Mobile, PAN, DOB) / Entity (Entity Name, PAN/CIN, Incorporation date). Handle: Match → update timestamps, Mismatch → block + create new account, Partial → contact support.

7. **Multi-KYC profile selection** — when `s_account_kyc_links` has >1 record, display profile picker on login.

8. **RM linking controls**:
   - Relationship declaration (spouse, parent, child, etc.)
   - Hard caps (4 individual / 2 entity KYCs per mobile; 2 new linkages per mobile per month)
   - Supervisor OTP override when caps hit
   - Customer consent OTP before linking
   - Full audit trail in `s_kyc_audit_logs`

9. **Anomaly detection daily report** to RM supervisors:
   - RMs who linked >2 applicants to same mobile in a day
   - Accounts with >3 KYCs linked
   - New linkages to accounts with a loan in default

---

## Phase 2 — Applicant & KYC Staging

**Goal:** Applicant records created correctly per journey with consent captured.

### Tasks

10. **Applicant creation** per source (`portal`, `api`, `rm`) with `source` metadata on `s_applicants`.

11. **KYC reuse flow** (existing KYC found):
    - Display masked KYC details
    - OTP consent → log in `s_consents` (`kyc_reuse`)
    - Copy `kyc_id` to `s_applicants`, snapshot status

12. **KYC change flow** (customer reports changes):
    - Pre-fill form with existing KYC data
    - Collect supporting documents with `change_metadata` JSON
    - OTP consent → log in `s_consents` (`kyc_change`)
    - Set `s_applicants.kyc_status = CHANGE_IN_KYC_DETAILS`

13. **Fresh KYC collection** (no existing KYC):
    - Collect all fields per entity type (Individual / Entity)
    - Store documents in `s_applicant_documents`
    - Set `s_applicants.kyc_status = PENDING`

---

## Phase 3 — KYC Matching Engine

**Goal:** Automated scoring to detect duplicate KYC records.

### Tasks

14. **`KycMatchingService`** in `app/Services/`:
    - Primary identifier check (PAN, Aadhaar, etc.) — exact match short-circuits to 100%
    - Fuzzy matching (Levenshtein) for name/address with pre-processing:
      - Uppercase → strip special chars → normalize whitespace → remove prefixes (Mr., Pvt., Ltd.)
    - Weighted scoring: Individual (name 35%, address 20%, DOB 15%, parent names 15%, contact 10%, other IDs 5%) / Entity (name 30%, address 25%, key personnel 20%, business details 15%, contact 10%)
    - Output: score + classification (`exact`, `strong`, `medium`, `weak`, `none`)

15. **Match result handling**:
    - Exact (100%): auto-link `kyc_id`, log `KYC_AUTO_LINKED`
    - Partial (50-99%): populate `s_kyc_match_candidates`, set `kyc_status = FLAGGED_FOR_REVIEW`
    - No match (<50%): proceed to fresh KYC flow

---

## Phase 4 — KYC Verification Pipeline

**Goal:** Automated verification of identity documents and AML screening.

### Tasks

16. **Third-party API wrappers** in `app/Services/` (one per document type):
    - PAN → NSDL/UTI
    - Aadhaar → UIDAI XML/OTP
    - Voter ID → ECI
    - Passport → MEA
    - Driving License → Vahan/Parivahan

17. **Retry with exponential backoff** on API errors. Store all results (request/response, masked PII) in `s_kyc_verifications`.

18. **AML screening** integration — PEP lists, UN/OFAC sanctions, negative news, internal watchlists. Flag for compliance review on potential match; escalate on confirmed match.

19. **Document verification pipeline**:
    - Image quality score
    - Tamper/forensic detection
    - OCR extraction → store in `ocr_extracted` JSON
    - Expiry date check
    - Cross-reference OCR data vs submitted data

20. **Risk assessment** calculation:
    - Geographic risk (20%) + Occupation risk (20%) + Transaction patterns (30%) + AML result (30%)
    - Map score → `risk_category` (low/medium/high)
    - Set `kyc_expiry_date` (Low: +10yr, Medium: +8yr, High: +2yr)

21. **Pipeline completion** → auto-transition `s_applicant_kyc.kyc_status = FINAL_DECISION_PENDING`.

---

## Phase 5 — KYC Officer Workflow (Admin)

**Goal:** Officers can manage all queues and make final decisions.

### Tasks

22. **Officer queue API** — 4 queues with priority:
    | Queue | Filter | Priority |
    |-------|--------|----------|
    | Flagged for Review | `kyc_status = FLAGGED_FOR_REVIEW` | High |
    | Pending Initiation | `kyc_status = PENDING` | Medium |
    | Change Requests | `kyc_status = CHANGE_IN_KYC_DETAILS` | Medium |
    | Final Decision | master `kyc_status = FINAL_DECISION_PENDING` | High |

23. **Case detail view** — applicant data, documents, match candidates with scores, side-by-side diff for flagged/change cases.

24. **Match resolution endpoints**:
    - Accept match → link `kyc_id`, copy status
    - Reject all → create new `s_applicant_kyc`, set both statuses to `INITIATED`
    - Manual initiation → officer reviews/corrects, creates master record

25. **Change request review** — approve (update master, copy documents, create history record) / reject / request more documents.

26. **Final decision** — pre-approval checklist, generate UCIC (`{YEAR}{SEQUENCE}`), set `kyc_status = COMPLETED`, set `kyc_expiry_date`.

27. **Deviation handling** — document deviation with justification; high-risk deviations require supervisor approval before final decision.

---

## Phase 6 — Status Management & Scheduled Jobs

**Goal:** Enforce valid state transitions and automate lifecycle events.

### Tasks

28. **Status transition service** — guard clauses enforcing the state machine. Reject invalid transitions with a clear exception.

    `s_applicants.kyc_status` valid transitions:
    ```
    PENDING → FLAGGED_FOR_REVIEW | INITIATED
    FLAGGED_FOR_REVIEW → INITIATED | PENDING
    INITIATED → COMPLETED | REJECTED
    CHANGE_IN_KYC_DETAILS → COMPLETED | REJECTED
    COMPLETED → EXPIRED
    ```

    `s_applicant_kyc.kyc_status` valid transitions:
    ```
    INITIATED → FINAL_DECISION_PENDING | REJECTED
    FINAL_DECISION_PENDING → COMPLETED | REJECTED
    COMPLETED → CHANGE_IN_KYC_DETAILS | EXPIRED
    CHANGE_IN_KYC_DETAILS → COMPLETED | REJECTED
    ```

29. **KYC expiry scheduled command** (daily):
    - 30 days before `kyc_expiry_date`: send reminder notification
    - On expiry date: set `s_applicant_kyc.kyc_status = EXPIRED` and linked `s_applicants.kyc_status = EXPIRED`

30. **PENDING account cleanup command** (daily):
    - Delete / mark `s_accounts` records in `PENDING` for >24 hours with no OTP verification
    - Allow re-registration on same mobile after cleanup

---

## Phase 7 — Customer Account Management (CAMS)

**Goal:** Support team can recover, merge, and update accounts.

### Tasks

31. **Account recovery flow** for blocked accounts:
    - Support agent identity verification (video call / document upload)
    - Unblock account + link correct KYC
    - Log `ACCOUNT_UNBLOCKED` with verification method and supervisor approval

32. **Account merge endpoint**:
    - Validate both accounts belong to same customer
    - Move `s_account_kyc_links` to target account
    - Update application/loan history references
    - Block source account
    - Full audit trail

33. **Mobile number update flow** — verify new number via OTP, update `s_accounts.mobile`, log change.

---

## Phase 8 — Data Migration

**Goal:** Migrate legacy customer and KYC data into the new schema.

### Tasks

34. **Phase 1 — Data Analysis**:
    - Profile source tables (null rates, duplicates, outliers)
    - Deduplication by PAN: single → direct migrate; multiple identical → merge; conflicting → flag
    - Document transformation rules per field

35. **Phase 2 — Schema Setup**:
    - Run migrations with FK constraints disabled
    - Create indexes after bulk load

36. **Phase 3 — Pilot Migration** (1% sample):
    - Run scripts, validate record counts, spot-check 100 records
    - Fix issues, measure time/record

37. **Phase 4 — Full Migration**:
    - Take full backup
    - Migrate in dependency order (accounts → master KYC → links → documents → loan journey → supporting tables → audit entries)
    - Re-enable FK constraints + indexes
    - Run post-migration validation (counts, FK integrity, file paths)

38. **Rollback plan** — documented trigger conditions (>1% failures, FK violations, performance degradation). Steps: stop → truncate target → restore backup → re-enable legacy.

---

## Cross-Cutting Concerns (all phases)

- **Audit logging**: Every status change and significant operation must write to `s_kyc_audit_logs` with `entity_type`, `entity_id`, `action`, `actor_type`, `old_values`, `new_values`.
- **Aadhaar encryption**: Store encrypted at rest. Define key management strategy before Phase 0 is complete.
- **No debug statements**: Never commit `dd()`, `dump()`, `Log::debug()`.
- **Response format**: Use `$this->positiveResponse()` / `$this->negativeResponse()` from `Responder` trait in all controllers.
- **StyleCI**: Single-space assignments, no unused imports added, no aligned padding.

---

## Open Items to Resolve Before Starting

| Item | Blocks |
|------|--------|
| Fuzzy matching library (Levenshtein vs Jaro-Winkler vs Soundex) | Phase 3 |
| API vendor selection (PAN/Aadhaar/DL/Passport/AML) | Phase 4 |
| Aadhaar encryption key management strategy | Phase 0 |
| Case assignment logic (round-robin vs skill-based vs manual pickup) | Phase 5 |
| Masked field display rules for portal | Phase 2 |
| Re-KYC grace period — allow transactions during re-KYC? | Phase 6 |
| Email/SMS notification templates | Phase 1, 6 |
| Soft delete vs hard delete policy | Phase 0 |
| Data retention period for audit logs + partitioning strategy | Phase 0 |
| Source system ERD for legacy data | Phase 8 |
