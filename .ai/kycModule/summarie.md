# KYC Module — Summary

## What it covers
A complete technical & functional spec for a KYC (Know Your Customer) system within the Loan Origination System. It governs how customer identities are verified, stored, reused, and audited across the loan lifecycle.

---

## 10 Sections at a Glance

| # | Section | Key Points |
|---|---------|------------|
| 1 | **Customer Account Creation** | 3 entry points: Portal, Partner API, RM. One account can link to many KYC records via `s_account_kyc_links`. Identity re-verification after 180 days (TRAI mobile recycling rule). Status: `PENDING → ACTIVE → BLOCKED/DORMANT`. |
| 2 | **Applicant Creation & KYC Dedupe** | Staging table (`s_applicants`) vs Master KYC table (`s_applicant_kyc`). Dedupe matching differs by channel: portal checks linked account, API/RM use weighted scoring. Two independent status fields per applicant and per master KYC. |
| 3 | **KYC Matching Logic** | PAN is highest priority (exact match wins). Secondary: fuzzy name/address (Levenshtein, 80-85% thresholds). Weighted scores: name 35%, address 20%, DOB 15%, etc. Match bands: Exact (100%), Strong (85-99%), Medium (70-84%), Weak (50-69%), No Match (<50%). |
| 4 | **KYC Officer Workflow** | 4 queues: Flagged, Pending, Change Requests, Final Decision. Officers can: select a match, reject all matches, or manually initiate KYC. Change requests require side-by-side comparison and approval. Final approval generates UCIC. |
| 5 | **KYC Verification Process** | 5 steps: API verification (Aadhaar/PAN/DL/Passport via govt APIs) → CKYC check → AML screening → Document verification (OCR + forensics) → Risk assessment. Risk drives KYC validity: Low=10yr, Medium=8yr, High=2yr. |
| 6 | **Status Management** | Full state machines documented for both `s_applicants.kyc_status` and `s_applicant_kyc.kyc_status`. Scheduled daily job for expiry. 30-day advance expiry notifications. |
| 7 | **Database Schema** | 10 tables defined with columns, types, indexes, and FK constraints. Key tables: `s_accounts`, `s_account_kyc_links`, `s_applicant_kyc`, `s_applicants`, `s_kyc_verifications`, `s_kyc_audit_logs`, `s_consents`, `s_kyc_match_candidates`. |
| 8 | **Data Migration** | 4-phase strategy: Analyse → Schema setup → Pilot (1%) → Full migration. Ordered dependency chain. Rollback trigger: >1% validation failures. |
| 9 | **Account Management (CAMS)** | Blocked account recovery (video KYC / in-person), account merging for duplicate mobile / fraud scenarios, mobile number updates. |
| 10 | **Appendix** | Open items scattered throughout each section (vendors, thresholds, templates, policies). |

---

## Key Design Decisions

- **Staging vs Master**: `s_applicants` is the working copy per application. `s_applicant_kyc` is the golden record reused across applications.
- **Dual Status Fields**: `s_applicants.kyc_status` tracks the applicant's journey; `s_applicant_kyc.kyc_status` tracks the master record's state. They can diverge (e.g., change request pending).
- **180-day Rule**: Based on TRAI regulation — prepaid numbers recycled after 90-day inactivity + 90-day quarantine. Re-verification protects against recycled number access.
- **Match Thresholds**: Only 100% score auto-links. Anything 50-99% goes to officer review queue (classified as Strong/Medium/Weak).
- **Consent-First**: Every KYC reuse, change, or Aadhaar verification requires OTP-confirmed consent logged in `s_consents`.
- **Risk-Based KYC Validity**: Low risk = 10yr, Medium = 8yr, High = 2yr.

---

## Need to Look

### Section 1 — Account Creation
- **30-day lead dedup window**: Same mobile cannot get a new lead via API within 30 days. After 30 days, a new lead is allowed and linked to the existing account.
- **PENDING account 24-hour TTL**: Accounts in `PENDING` for >24 hours are eligible for cleanup. Re-registration on the same mobile is allowed after cleanup.
- **Scenario B — No linked KYC**: If an account has no linked KYC records, skip identity re-verification entirely and allow login directly (log for monitoring as incomplete onboarding signal).
- **Mismatch handling detail**: On identity mismatch during re-verification, the original account is **blocked, not deleted** — preserved for audit and potential recovery via CAMS. A new account is created with the same mobile number.

### Section 2 — Applicant & KYC
- **Bureau check at lead stage**: Credit/bureau check happens before application creation, not after. Ineligible leads are marked `INELIGIBLE` and can re-apply after 30 days.
- **Entity hierarchy**: Lead (`s_opportunities`) → Application (`s_applications`) → Applicants (`s_applicants`) → Master KYC (`s_applicant_kyc`).
- **Documents staging vs master**: `s_applicant_documents` is the staging area per application. `s_applicant_kyc_documents` holds verified documents on the master. Documents are **copied** from staging to master at KYC approval — not moved.

### Section 3 — KYC Matching
- **Entity primary identifiers**: CIN, LLPIN, UDYAM registration number, GSTIN — in addition to PAN. All are exact-match identifiers.
- **Combined registration details**: Registration number + Registration type + Issuing authority + Issue date — treated as a single composite exact-match identifier for entities.
- **Individual weight breakdown (full)**: Name 35%, Address 20%, DOB 15%, Parent names (Father/Mother) 15%, Contact 10%, Other IDs 5%. The summary's "etc." skips parent names.

### Section 4 — Officer Workflow
- **Option C — Manual KYC Initiation**: A third resolution option for flagged cases. Officer reviews applicant details and documents, corrects data if needed directly on `s_applicants`, then manually creates the `s_applicant_kyc` master record and sets both statuses to `INITIATED`.
- **Request more documents**: A third option in change request review (alongside approve/reject). Officer can request additional supporting documents before making a decision.

### Section 5 — KYC Verification
- **CKYC is a future enhancement**: Not part of the initial build. CKYC search (fetch KIN from registry) and CKYC upload (post-approval XML generation) are planned but not in scope yet.
- **AML escalation levels**: Potential match → flag for compliance review. Confirmed match → escalate to compliance officer (not just flag).

### Section 7 — Database Schema
- **Tables missing from key tables list**: `s_applicant_documents` (staging docs), `s_applicant_kyc_documents` (master verified docs), `s_applicant_kyc_history` (change history on master KYC).

### Section 9 — CAMS
- **Mobile update conditions**: Self-service mobile update only allowed when account is `ACTIVE`, KYC is `COMPLETED`, and there are no pending loan applications.
- **New mobile already in system**: If the requested new mobile already exists in `s_accounts`, the update is rejected and customer is directed to contact support.
- **Merge rule**: Source account is set to `BLOCKED` with `blocked_reason = 'merged'` — never deleted. All `s_account_kyc_links` and `s_applications` are re-linked to the target account.

### Key Design Decisions (missing from summary)
- **Lead deduplication is on `s_opportunities`**, not `s_accounts`. Mobile existence in `s_accounts` is checked separately to link or create an account.
- **Bureau check is at lead stage** — a lead must pass eligibility before an application is ever created.
- **Point-in-time snapshot**: Each `s_applicants` record preserves KYC data as it was at the time of submission, even after the master KYC is updated.

### Open Items (missing from list)
- SLA tracking per officer queue (time limits per case type — to be defined)
- Entity KYC creation via Customer Portal (currently RM-only — future enhancement)
- CKYC fetch and upload vendor selection (future scope)
- Source system ERD required for migration (to be obtained from legacy team)
- Bulk expiry handling strategy for large expiry batches
- Audit log partitioning strategy for large table growth
- Migration downtime window approval (stakeholder coordination needed)

---

## Open Items (unresolved)

| Item | Impact Area |
|------|-------------|
| Fuzzy matching library (Levenshtein vs Jaro-Winkler) | Matching Engine |
| API vendor selection (PAN, Aadhaar, DL, AML) | Verification Pipeline |
| Aadhaar encryption key management | Security / Schema |
| Case assignment logic (round-robin vs manual pickup) | Officer Workflow |
| Masked field display rules | Customer Portal |
| Re-KYC grace period policy | Status Management |
| Email/SMS notification templates | Expiry & Alerts |
| Soft delete vs hard delete policy | Schema / Compliance |
| Data retention period for audit logs | Schema / Compliance |
