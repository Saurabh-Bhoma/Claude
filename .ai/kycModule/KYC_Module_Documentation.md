# KYC Module - Technical and Functional Documentation

## Table of Contents

1. [Customer Account Creation Scenarios](#1-customer-account-creation-scenarios)
2. [Applicant Creation and KYC Dedupe/Matching](#2-applicant-creation-and-kyc-dedupematching)
3. [KYC Matching Criteria and Logic](#3-kyc-matching-criteria-and-logic)
4. [KYC Officer Workflow](#4-kyc-officer-workflow)
5. [KYC Verification Process](#5-kyc-verification-process)
6. [KYC Status Management](#6-kyc-status-management)
7. [Tables and Schema](#7-tables-and-schema)
8. [Data Migration Strategy](#8-data-migration-strategy)
9. [Customer Account Management System](#9-customer-account-management-system)
10. [Appendix](#10-appendix)

---

## 1. Customer Account Creation Scenarios

### 1.1 Overview

Customer accounts (`s_accounts`) serve as the authentication layer for the loan origination system. An account is created when a customer:

- Registers via the **Customer Portal**
- Is pushed via **Partner API**
- Is logged by an **RM (Relationship Manager)**

**Key Design Decisions:**

| Aspect | Decision |
|--------|----------|
| Account ↔ KYC Relationship | One account can be linked to multiple KYC records (via pivot table `s_account_kyc_links`) |
| Mobile Number Recycling Protection | Identity re-verification triggered after 180 days of inactivity |
| Account Status Values | `PENDING`, `ACTIVE`, `BLOCKED`, `DORMANT` |

---

### 1.2 Customer Portal Flow

#### 1.2.1 Login Flow Diagram

```
Customer enters mobile number
            │
            ▼
┌─────────────────────────┐
│ Does account exist?     │
└─────────────────────────┘
        │           │
       Yes          No
        │           │
        ▼           ▼
  [Existing User   [New User
     Flow]          Registration]
```

---

#### 1.2.2 Existing User Flow

**Step 1: OTP Generation & Delivery**

| Action | Details |
|--------|---------|
| Generate OTP | System generates time-bound OTP |
| Send to Mobile | Mandatory |
| Send to Email | Optional — only if email exists in system. Email contains the OTP. |

**Step 2: Post OTP Verification — Profile Selection**

After successful OTP verification, system checks if multiple KYC profiles are linked to the account:

```
OTP Verified
     │
     ▼
┌────────────────────────────────────────┐
│ Are multiple KYC profiles linked to    │
│ this account?                          │
└────────────────────────────────────────┘
          │                    │
         Yes                   No
          │                    │
          ▼                    ▼
   Display profile         Auto-select
   selection screen        single/primary profile
          │                    │
          ▼                    │
   Customer selects            │
   which profile               │
          │                    │ 
          └────────────────────┘
                    │
                    ▼
          [Inactivity Check]
```

**Profile Selection Screen:**
- List all linked applicant names (from `s_account_kyc_links`)
- Show applicant type indicator (Individual/Entity)
- Customer selects which profile they are logging in as
- Store selected `kyc_id` in session for subsequent operations

**Step 3: Inactivity Check**

After profile selection, system checks **inactivity period**:

```
Profile Selected
     │
     ▼
┌────────────────────────────────────────┐
│ Has customer logged in within 180 days │
│ after account creation?                │
└────────────────────────────────────────┘
          │                    │
         Yes                   No
          │                    │
          ▼                    ▼
   Update last_login_at   [Identity Re-verification]
   Redirect to portal
```

> **Why 180 days?**
> As per TRAI regulations, prepaid mobile numbers are deactivated after 90 days of inactivity and go into a 90-day quarantine before being recycled. The 180-day threshold accounts for the full cycle, protecting against scenarios where a recycled number could grant access to another customer's account.

---

#### 1.2.3 Identity Re-verification (Inactive User)

Triggered when a user hasn't logged in for **180+ days** and the account has linked KYC records.

**Scenario A: Account has linked KYC record(s)**

Since the customer has already selected their profile in Step 2, proceed directly to verification:

1. Display identity verification form based on selected profile:

| Applicant Type | Fields to Verify |
|----------------|------------------|
| Individual | Name on PAN, Mobile Number, PAN Number, Date of Birth |
| Entity | Entity Name, PAN/CIN, Date of Incorporation |

2. Verify submitted details against the selected KYC record:

| Verification Result | System Action |
|---------------------|---------------|
| **Match** | Update `s_accounts.identity_verified_at` and `last_login_at`. Proceed to portal. |
| **Mismatch** | Mark account as `BLOCKED`. Create new account with same mobile number. Log customer into new account. |
| **Partial Match / Unsure** | Redirect to "Contact Support" flow. Do not auto-block. |

> **Rationale for Mismatch Handling:**
> This safeguards against recycled mobile numbers, identity mismatch, data leakage, and incorrect KYC linking. The original account is preserved (blocked, not deleted) for audit and potential recovery via Customer Account Management System.

**Scenario B: Account has NO linked KYC record**

- No sensitive KYC data to protect
- Allow login without identity verification
- Update `last_login_at`
- Log this scenario for monitoring (potential indicator of incomplete onboarding)

---

#### 1.2.4 New User Registration Flow

Triggered when the mobile number does not exist in the system.

**Step 1: Registration Form**

Collect the following details:

| Field | Required | Notes |
|-------|----------|-------|
| Full Name | Yes | |
| Email Address | No | Used for notifications if provided |
| Pin Code | Yes | For geographic profiling |
| Additional Demographics | Configurable | As per product requirements |

> **Security Note:** The registration form must not reveal whether a mobile number is already registered. Error messages should be generic to prevent enumeration attacks.

**Step 2: Account Creation**

- Create account record with `status = PENDING`
- Store submitted demographic details

**Step 3: OTP Verification**

| Channel | Required | Notes |
|---------|----------|-------|
| Mobile OTP | Yes | Must be verified to activate account |
| Email OTP | No | Sent only if email provided; verification optional |

**Step 4: Account Activation**

- On successful mobile OTP verification, update `status = ACTIVE`
- Update `last_login_at`
- Redirect to customer portal

**Edge Case: Registration Abandonment**

| Scenario | Handling |
|----------|----------|
| User fills form but never verifies OTP | Account remains `PENDING` |
| PENDING account TTL | Accounts in `PENDING` status for >24 hours should be eligible for cleanup |
| Re-registration with same mobile | If existing account is `PENDING` and expired, allow fresh registration |

---

### 1.3 API Integration Flow

Partner systems can create customer accounts via API, typically during lead creation.

#### 1.3.1 Lead Creation API Flow

```
Partner calls Lead Creation API with mobile number
                    │
                    ▼
┌─────────────────────────────────────────────────┐
│ Does a lead exist in s_opportunities with the   │
│ same mobile number created in the last 30 days? │
└─────────────────────────────────────────────────┘
              │                    │
             Yes                   No
              │                    │
              ▼                    ▼
       Reject API call      Create new lead in
       ("Lead already       s_opportunities
        exists")                   │
                                   ▼
                    ┌─────────────────────────────┐
                    │ Does account exist with     │
                    │ this mobile number?         │
                    └─────────────────────────────┘
                            │             │
                           Yes            No
                            │             │
                            ▼             ▼
                      Link existing    Create new Account
                      account_id to    (PENDING status) &
                      the lead         link to lead
```

**Key Points:**
- Lead deduplication is based on `s_opportunities` table, not `s_accounts`
- 30-day window for duplicate lead check — same mobile can have a new lead after 30 days
- Account is either linked (if exists) or created (if new) and attached to the lead

#### 1.3.2 New Account via API

| Attribute | Value |
|-----------|-------|
| Account Status | `PENDING` |
| Activation Trigger | Customer logs in via portal |
| Customer Notification | None via API; customer notified when they attempt portal login |

#### 1.3.3 Duplicate Lead Handling

| Scenario | API Response |
|----------|--------------|
| Lead with same mobile exists in last 30 days | Reject with error: "Lead already exists" |
| Lead with same mobile exists but older than 30 days | Allow — create new lead, link to existing account |
| Mobile exists but no recent lead | Allow — create new lead, link to existing account |

> **Note:** The 30-day deduplication window allows partners to retry leads that may have gone cold, while preventing immediate duplicate submissions.

---

### 1.4 RM Journey Flow

Relationship Managers can create accounts while logging new loan applications.

#### 1.4.1 Account Creation During Applicant Logging

```
RM enters applicant KYC details
            │
            ▼
RM enters mobile number
            │
            ▼
┌─────────────────────────────┐
│ Does mobile number exist    │
│ in s_accounts?              │
└─────────────────────────────┘
        │             │
       Yes            No
        │             │
        ▼             ▼
   [Existing Mobile  Create new Account
      Flow]          (PENDING status)
                          │
                          ▼
                   Notify customer
                   via SMS & Email
```

#### 1.4.2 New Account Created by RM

| Attribute | Value |
|-----------|-------|
| Account Status | `PENDING` |
| Customer Notification | SMS + Email informing them of account creation |
| Activation | When customer logs in via portal |

#### 1.4.3 Existing Mobile Number — Linking Controls

When RM enters a mobile number that already exists, the system must determine whether to link the new applicant to the existing account or create a fresh account.

**Key Risk:** RMs may misuse this feature by linking unrelated applicants to the same mobile number to expedite case logging.

**Control Framework:**

```
Mobile exists in system
         │
         ▼
┌─────────────────────────────────────┐
│  Is this application for the same   │
│  person as the existing account?    │
└─────────────────────────────────────┘
         │                │
        Yes               No
         │                │
         ▼                ▼
   Auto-link to       [New Applicant
   existing KYC        Linking Flow]
   (Repeat customer)
```

**Same Person (Repeat Customer):**
- Auto-link application to existing KYC record
- No new applicant KYC creation needed
- Proceed directly to loan application

**Different Person — New Applicant Linking Flow:**

1. **Mandatory Relationship Declaration**

   RM must select relationship between new applicant and existing account holder:

   | Relationship Type | Max Allowed per Account |
   |-------------------|-------------------------|
   | Spouse | 1 |
   | Parent | 2 |
   | Child | 4 |
   | Business Partner (Entity) | 2 |
   | Authorized Signatory | 2 |
   | Other | Requires justification text |

2. **Hard Caps & Limits**

   | Rule | Limit | Override Authority |
   |------|-------|--------------------|
   | Max individual KYCs per mobile | 4 | Supervisor approval |
   | Max entity KYCs per mobile | 2 | Supervisor approval |
   | Max new linkages per mobile per month | 2 | Credit Manager approval |

   When limit is reached, RM cannot proceed without supervisor entering credentials and providing approval reason.

3. **Customer Consent via OTP**

   - System sends OTP to the registered mobile number
   - Customer must share OTP with RM
   - RM enters OTP to confirm the customer consents to this linkage
   - This proves the customer is aware another applicant is being linked to their mobile

4. **Audit Trail**

   All linking actions are logged with:
   - RM ID
   - Relationship type declared
   - Supervisor approval (if applicable)
   - OTP verification timestamp
   - Justification (if "Other" relationship)

**Anomaly Detection & Reporting:**

Daily reports to RM supervisors flagging:
- RMs who linked >2 applicants to same mobile in a single day
- Accounts with >3 KYCs linked
- New linkages to accounts that have a loan in default status (fraud indicator)

---

### 1.5 Account Status Definitions

| Status | Description | Transitions To |
|--------|-------------|----------------|
| `PENDING` | Account created but OTP not verified | `ACTIVE` (on OTP verification) |
| `ACTIVE` | Fully functional account | `BLOCKED`, `DORMANT` |
| `BLOCKED` | Account disabled due to identity mismatch or security concern | `ACTIVE` (via Account Management System) |
| `DORMANT` | Account inactive for extended period | `ACTIVE` (on re-verification) |

---

### 1.6 Key Tables Involved

| Table | Role |
|-------|------|
| `s_accounts` | Primary account table with authentication details |
| `s_account_kyc_links` | Pivot table linking accounts to KYC records (one-to-many) |
| `s_applicant_kyc` | Master KYC records |

**Proposed `s_account_kyc_links` Structure:**

| Column | Type | Description |
|--------|------|-------------|
| `id` | PK | Primary key |
| `account_id` | FK | Reference to `s_accounts.id` |
| `kyc_id` | FK | Reference to `s_applicant_kyc.id` |
| `is_primary` | Boolean | Designates primary KYC for login verification |
| `relationship_type` | Enum | self, spouse, parent, child, business_partner, authorized_signatory, other |
| `relationship_justification` | Text | Required if relationship_type = 'other' |
| `linked_at` | Timestamp | When the link was created |
| `linked_by` | String | 'self', 'system', 'rm:{user_id}', 'support:{user_id}' |
| `created_at` | Timestamp | |
| `updated_at` | Timestamp | |

---

### 1.7 Open Items for Section 1

| Item | Status | Notes |
|------|--------|-------|
| Email notification content/template | To be defined | Currently sends OTP |
| PENDING account cleanup job | To be built later | 24-hour TTL proposed |
| Contact Support flow for partial match | To be documented | Part of Account Management System |
| Account recovery for blocked accounts | To be documented | Part of Account Management System |

---

---

## 2. Applicant Creation and KYC Dedupe/Matching

### 2.1 Overview

**Applicant** (`s_applicants`) represents a person or entity applying for a loan within a specific application. The same customer may have multiple applicant records across different applications, but only one master KYC record (`s_applicant_kyc`).

**Key Concept: Staging vs Master**

| Table | Purpose | Lifecycle |
|-------|---------|-----------|
| `s_applicants` | Working area — holds KYC data + application-specific data | New record per application |
| `s_applicant_documents` | Working area — holds documents for this application | New record per application |
| `s_applicant_kyc` | Master KYC record (golden record) | One per customer, reused across applications |
| `s_applicant_kyc_documents` | Master KYC documents | Copied from staging upon KYC approval |

**Why This Design?**

1. **`s_applicants` always contains KYC fields** — serves as working copy for KYC Officer to review/correct
2. **Data flows from staging to master** — at `PENDING → INITIATED` transition, data moves from `s_applicants` to `s_applicant_kyc`
3. **Point-in-time snapshot** — each application preserves what was submitted/approved at that time
4. **Non-KYC data isolation** — income, employment, etc. change per application; master KYC remains stable

**Data Flow:**

```
[Fresh KYC]
Customer/RM/API submits data
            │
            ▼
    s_applicants (PENDING)
    [KYC data stored here]
            │
            ▼
    KYC Officer reviews/corrects
    [Works directly on s_applicants]
            │
            ▼
    PENDING → INITIATED transition
            │
    ┌───────┴───────┐
    ▼               ▼
s_applicant_kyc   s_applicants.kyc_id
[Master created]  [Linked to master]

[KYC Reuse]
Existing s_applicant_kyc found
            │
            ▼
    Copy KYC data TO s_applicants
    [Pre-fill for this application]
            │
            ▼
    Link s_applicants.kyc_id
    [Snapshot preserved]
```

---

### 2.2 Entity Hierarchy

```
Lead (s_opportunities)
    │
    │  Contains: Basic info, loan ask, source, PAN
    │  Eligibility: Bureau check performed here
    │
    ▼ [If eligible]
Application (s_applications)
    │
    │  Contains: Loan details, status, workflow state
    │
    ▼
Applicants (s_applicants)
    │
    │  Contains: Per-application snapshot
    │  Types: Primary Borrower, Co-Applicant, Guarantor
    │
    ▼ [KYC Verification]
Master KYC (s_applicant_kyc)
    │
    │  Contains: Verified, reusable KYC data
    │  Linked via: s_applicants.kyc_id
```

---

### 2.3 Lead to Application Flow

Before applicant creation, a lead must pass eligibility:

```
Customer/Partner/RM initiates loan request
                │
                ▼
        Collect Basic Info
        (Name, PAN, Loan Amount, etc.)
                │
                ▼
        Create Lead in s_opportunities
                │
                ▼
┌───────────────────────────────────┐
│ Bureau Check (using PAN)          │
│ - Credit Score                    │
│ - Existing obligations            │
│ - Eligibility rules               │
└───────────────────────────────────┘
          │              │
      Eligible      Ineligible
          │              │
          ▼              ▼
   Continue to      Mark lead as
   Application      INELIGIBLE
   Creation         (Can re-apply after 30 days)
```

**Ineligibility Handling:**
- Lead status set to `INELIGIBLE`
- Account remains active (not blocked)
- Customer can re-apply after 30 days (assuming improved credit score, income, etc.)

---

### 2.4 Applicant Creation Triggers

Applicant is created when:

| Journey | Trigger Point |
|---------|---------------|
| Customer Portal | Customer proceeds past eligibility check |
| Partner API | Partner sends applicant details after eligibility confirmation |
| RM Journey | RM fills applicant form after eligibility check |

**Applicant Types:**
- Primary Borrower
- Co-Applicant (spouse, business partner, etc.)
- Guarantor

Each applicant type follows the same KYC flow independently.

---

### 2.5 Customer Portal KYC Flow

#### 2.5.1 Flow Overview

```
Customer initiates new application
            │
            ▼
┌────────────────────────────────────┐
│ Check for linked KYC via           │
│ s_account_kyc_links (account_id)   │
└────────────────────────────────────┘
          │                │
    KYC Found         No KYC Found
          │                │
          ▼                ▼
   [KYC Confirmation   [Fresh KYC
      Flow]              Collection]
```

---

#### 2.5.2 No KYC Found — Fresh KYC Collection

When customer has no existing KYC linked to their account:

1. **Collect KYC Details**
   - Display KYC form based on applicant type (Individual/Entity)
   - Collect all required fields as per Section 7 (Tables)

2. **Document Upload**
   - Collect required identity and address proof documents
   - Validate document formats and file sizes
   - Store in `s_applicant_documents` table

3. **Create Applicant Record**
   - Store data in `s_applicants` table
   - Set `s_applicants.kyc_status = PENDING`
   - No `kyc_id` linked yet (KYC verification pending)

4. **Proceed to KYC Verification**
   - KYC Officer or automated system picks up for verification
   - See Section 5: KYC Verification Process

---

#### 2.5.3 KYC Found — KYC Confirmation Flow

When customer has existing KYC linked to their account:

```
Existing KYC found
        │
        ▼
Display existing KYC details
(partially masked for security)
        │
        ▼
┌─────────────────────────────────────┐
│ "Are these details still correct?" │
└─────────────────────────────────────┘
        │                │
       Yes               No
        │                │
        ▼                ▼
[No Changes Flow]   [Changes Flow]
```

---

##### 2.5.3.1 No Changes in KYC

Customer confirms existing KYC is accurate:

1. **Consent & Declaration**
   - Send OTP to registered mobile (separate from login OTP)
   - Customer enters OTP to confirm:
     - *"I confirm my KYC details are still accurate"*
     - *"I consent to reusing my existing KYC for this application"*
   - Log consent in `s_consents` table with timestamp, IP, consent text

2. **Create Applicant Record**
   - Store application-specific data in `s_applicants` table
   - Link `s_applicant_kyc.id` to `s_applicants.kyc_id`
   - Copy `s_applicant_kyc.kyc_status` to `s_applicants.kyc_status`

3. **Document Handling**
   - If additional documents required for this application, store in `s_applicant_documents`
   - Existing KYC documents remain in `s_applicant_kyc_documents` (not copied back)

4. **Audit Log**
   - Log entry: `KYC_REUSED`
   - Include: declaration details, consent reference, document references, timestamp

---

##### 2.5.3.2 Changes in KYC Details

Customer indicates their KYC details have changed:

1. **Identify Changes**
   - Display form pre-filled with existing KYC data
   - Customer updates changed fields (name, address, marital status, etc.)

2. **Collect Supporting Documents**
   - Request documents supporting the changes
   - Store in `s_applicant_documents` with change reason metadata
   - Example metadata: `{ "change_type": "address", "reason": "relocation" }`

3. **Consent & Declaration**
   - Send OTP to registered mobile
   - Customer confirms declaration via OTP
   - Log consent in `s_consents` table

4. **Create Applicant Record**
   - Store modified data in `s_applicants` table
   - Link existing `s_applicant_kyc.id` to `s_applicants.kyc_id`
   - Set `s_applicants.kyc_status = CHANGE_IN_KYC_DETAILS`

5. **Audit Log**
   - Log entry: `KYC_CHANGE_REQUESTED`
   - Include: old values, new values, supporting document references

6. **Trigger Review**
   - Changes require KYC Officer review before master KYC is updated
   - See Section 4: KYC Officer Workflow

---

### 2.6 API KYC Flow

Partner systems submit applicant KYC details via API.

#### 2.6.1 Flow Overview

```
Partner submits applicant details via API
                │
                ▼
        Store in s_applicants
        Store documents in s_applicant_documents
        (with API source metadata)
                │
                ▼
┌────────────────────────────────────────┐
│ Run automated matching against         │
│ s_applicant_kyc table                  │
│ (using weighted scoring algorithm)     │
└────────────────────────────────────────┘
          │           │           │
    Exact Match  Partial Match  No Match
      (100%)      (50-99%)       (<50%)
          │           │           │
          ▼           ▼           ▼
      [Auto-link]  [Flag for   [New KYC
                    Review]      Flow]
```

---

#### 2.6.2 Exact Match Found (100%)

Automated handling when perfect match is found:

1. Link `s_applicant_kyc.id` to `s_applicants.kyc_id`
2. Set `s_applicants.kyc_status = FINAL_DECISION_PENDING`
3. Log entry: `KYC_AUTO_LINKED` with match score and matched fields
4. Proceed to final KYC decision workflow

---

#### 2.6.3 Partial Match Found (50-99%)

Requires manual review:

1. Set `s_applicants.kyc_status = FLAGGED_FOR_REVIEW`
2. Store match candidates with confidence scores
3. KYC Officer reviews and decides (see Section 4)

---

#### 2.6.4 No Match Found (<50%)

New KYC record needs to be created:

```
No Match Found
      │
      ▼
Attempt Automated Verification
(PAN API, Aadhaar XML, etc.)
      │
      ├─── Success ───┐
      │               │
      ▼               ▼
┌─────────────┐  ┌─────────────────┐
│ Create new  │  │ If verification │
│ KYC record  │  │ fails, set      │
│ in s_appli- │  │ status to       │
│ cant_kyc    │  │ PENDING for     │
└─────────────┘  │ manual review   │
      │          └─────────────────┘
      ▼
Copy documents from
s_applicant_documents to
s_applicant_kyc_documents
      │
      ▼
Set s_applicants.kyc_status = INITIATED
Set s_applicant_kyc.kyc_status = INITIATED
      │
      ▼
Trigger remaining verification steps
```

---

### 2.7 RM Journey KYC Flow

RM-assisted applicant creation with KYC matching.

#### 2.7.1 Flow Overview

```
RM enters applicant KYC details
(Name, PAN, DOB, Address, etc.)
            │
            ▼
┌──────────────────────────────────────┐
│ System searches s_applicant_kyc for  │
│ exact matches based on all entered   │
│ KYC fields                           │
└──────────────────────────────────────┘
            │
            ▼
┌──────────────────────────────────────┐
│ Display matching KYC records to RM   │
│ (if any found)                       │
└──────────────────────────────────────┘
          │                │
    Match(es) Found    No Match
          │                │
          ▼                ▼
   [RM Selection      [Fresh KYC
      Flow]             Flow]
```

---

#### 2.7.2 No Match Found

Standard fresh KYC collection:

1. Store applicant details in `s_applicants` table
2. Collect and store documents in `s_applicant_documents`
3. Validate document formats and file sizes
4. Set `s_applicants.kyc_status = PENDING`
5. Proceed to KYC verification

---

#### 2.7.3 Match(es) Found — RM Selection

When system finds matching KYC records:

1. **Display Matches**
   - Show list of potential matching KYC records
   - Display key identifiers (name, PAN - masked, DOB)

2. **RM Selects Match**
   - RM reviews and selects the appropriate match
   - Or rejects all matches (proceeds to fresh KYC)

3. **Confirm Change Status**

   RM must confirm: *"Are there any changes in the customer's KYC details?"*

   **If No Changes:**
   - Link `s_applicant_kyc.id` to `s_applicants.kyc_id`
   - Copy `s_applicant_kyc.kyc_status` to `s_applicants.kyc_status`
   - Log: `KYC_REUSED_BY_RM`

   **If Changes Exist:**
   - Collect updated data and supporting documents
   - Store in `s_applicants` and `s_applicant_documents` with change metadata
   - Record customer self-declaration via OTP
   - Link `s_applicant_kyc.id` to `s_applicants.kyc_id`
   - Set `s_applicants.kyc_status = CHANGE_IN_KYC_DETAILS`
   - Trigger KYC Officer review for master update

---

### 2.8 Consent Management

All KYC-related consents are logged in `s_consents` table.

| Consent Type | When Captured | Consent Text (Example) |
|--------------|---------------|------------------------|
| Bureau Fetch | Lead stage | "I authorize [Company] to fetch my credit report from credit bureaus" |
| KYC Reuse | Application stage | "I confirm my KYC details are accurate and consent to reuse for this application" |
| KYC Change Declaration | Application stage | "I declare that the updated information provided is true and accurate" |
| Aadhaar Verification | KYC verification | "I voluntarily provide my Aadhaar for verification purposes" |
| CKYC Fetch | KYC verification (future) | "I authorize [Company] to fetch my KYC from Central KYC Registry" |

**Consent Record Structure:**
- Consent type
- Consent text (full legal text)
- Applicant ID / KYC ID
- Timestamp
- IP Address
- Device info
- OTP verification reference (if applicable)

---

### 2.9 Key Differences by Journey

| Aspect | Customer Portal | Partner API | RM Journey |
|--------|-----------------|-------------|------------|
| KYC Matching | Check linked KYC via account | Weighted algorithm against all KYC records | Weighted algorithm against all KYC records |
| Match Confirmation | Customer confirms via OTP | Automated (exact) or Officer review (partial) | RM confirms, customer OTP for changes |
| Fresh KYC Trigger | No linked KYC exists | No match found (<50%) | No match found or RM rejects matches |
| Change Handling | Customer self-service + OTP | N/A (API submits final data) | RM collects + Customer OTP |

---

### 2.10 KYC Status Model

The system maintains **two separate status fields** that track different things:

| Field | What It Tracks |
|-------|----------------|
| `s_applicants.kyc_status` | This applicant's KYC journey within this application |
| `s_applicant_kyc.kyc_status` | The master KYC record's verification state |

#### 2.10.1 `s_applicants.kyc_status` Values

| Status | Description | Next States |
|--------|-------------|-------------|
| `PENDING` | KYC details submitted, not yet initiated (no master record) | `INITIATED`, `FLAGGED_FOR_REVIEW` |
| `FLAGGED_FOR_REVIEW` | Potential duplicate match requires officer review | `INITIATED`, `PENDING` |
| `INITIATED` | KYC processing has begun, master record created/linked | `COMPLETED`, `REJECTED` |
| `CHANGE_IN_KYC_DETAILS` | Customer reported changes in existing KYC | `COMPLETED`, `REJECTED` |
| `COMPLETED` | KYC successfully completed for this application | `EXPIRED` |
| `REJECTED` | KYC application rejected | *(terminal)* |
| `EXPIRED` | Linked KYC validity has expired | `INITIATED` (re-KYC) |

#### 2.10.2 `s_applicant_kyc.kyc_status` Values

| Status | Description | Next States |
|--------|-------------|-------------|
| `INITIATED` | KYC record created, verification in progress | `FINAL_DECISION_PENDING`, `REJECTED` |
| `FINAL_DECISION_PENDING` | All verifications complete, awaiting final approval | `COMPLETED`, `REJECTED` |
| `COMPLETED` | KYC approved and active | `CHANGE_IN_KYC_DETAILS`, `EXPIRED` |
| `REJECTED` | KYC application rejected | *(terminal)* |
| `CHANGE_IN_KYC_DETAILS` | Changes reported and under review | `COMPLETED`, `REJECTED` |
| `EXPIRED` | KYC validity period expired | `INITIATED` (re-KYC) |

#### 2.10.3 Status Relationship Examples

| Scenario | `s_applicants.kyc_status` | `s_applicant_kyc.kyc_status` |
|----------|---------------------------|------------------------------|
| Fresh applicant, just submitted | `PENDING` | *(no record yet)* |
| Partial match needs review | `FLAGGED_FOR_REVIEW` | *(no link yet)* |
| KYC verification in progress | `INITIATED` | `INITIATED` |
| Awaiting final approval | `INITIATED` | `FINAL_DECISION_PENDING` |
| KYC approved | `COMPLETED` | `COMPLETED` |
| Customer reports change on existing KYC | `CHANGE_IN_KYC_DETAILS` | `COMPLETED` *(master still valid)* |
| Change approved, master updated | `COMPLETED` | `COMPLETED` |
| KYC reuse, no changes | `COMPLETED` *(copied)* | `COMPLETED` |
| Master KYC expired | `EXPIRED` *(copied)* | `EXPIRED` |

> **Key Insight:** The statuses are often aligned but not always. For example, when a customer reports changes, `s_applicants.kyc_status = CHANGE_IN_KYC_DETAILS` while `s_applicant_kyc.kyc_status` remains `COMPLETED` until the officer reviews and updates the master.

---

### 2.11 Open Items for Section 2

| Item | Status | Notes |
|------|--------|-------|
| Masked field display rules | To be defined | Which fields to mask, masking format |
| Change reason categories | To be defined | Standardize change types for metadata |
| API response structure for match results | To be defined | What to return for partial matches |
| Entity KYC creation via Customer Portal | Currently RM-only | Future enhancement consideration |

---

---

## 3. KYC Matching Criteria and Logic

### 3.1 Overview

KYC matching determines whether an incoming applicant's details correspond to an existing KYC record in `s_applicant_kyc`. This enables KYC reuse and prevents duplicate records.

**When Matching is Triggered:**
- API flow: Automated matching against all KYC records
- RM flow: Real-time matching as RM enters details
- Customer Portal: Check linked KYC via account (no global matching)

---

### 3.2 Individual Matching

#### 3.2.1 Primary Matching (Identity Documents)

Primary identifiers are checked first. A match on any primary identifier with exact match is strong evidence of same person.

| Priority | Identifier | Match Type |
|----------|------------|------------|
| 1 (Highest) | PAN Number | Exact |
| 2 | Aadhaar Number | Exact |
| 3 | Voter ID Number | Exact |
| 4 | Passport Number | Exact |
| 5 | Driving License Number | Exact |

> **Note:** If PAN matches exactly, this is typically sufficient for identity confirmation. Other identifiers serve as fallbacks.

#### 3.2.2 Secondary Matching (Demographic Data)

When primary identifiers don't yield exact match, or to strengthen confidence, secondary matching is applied.

| Field | Match Type | Threshold |
|-------|------------|-----------|
| Name fields | Fuzzy | 85% similarity |
| Parent names (Father/Mother) | Fuzzy | 80% similarity |
| Date of Birth | Exact | 100% |
| Gender | Exact | 100% |
| Address | Fuzzy | 80% similarity |

#### 3.2.3 Weighted Scoring Algorithm (Individual)

When multiple fields are available, a weighted score determines overall match confidence.

| Field | Weight |
|-------|--------|
| Name fields | 35% |
| Parent names | 15% |
| Date of Birth | 15% |
| Address | 20% |
| Contact information | 10% |
| Other identifiers | 5% |
| **Total** | **100%** |

---

### 3.3 Entity Matching

#### 3.3.1 Primary Matching (Registration Documents)

| Priority | Identifier | Match Type |
|----------|------------|------------|
| 1 | PAN Number | Exact |
| 2 | Corporate Identification Number (CIN) | Exact |
| 3 | LLPIN (for LLPs) | Exact |
| 4 | UDYAM Registration Number | Exact |
| 5 | TIN/GSTIN | Exact |
| 6 | Combined registration details | Exact (all components) |

**Combined Registration Details:**
- Registration number + Registration type + Issuing authority + Issue date

#### 3.3.2 Secondary Matching (Entity Details)

| Field | Match Type | Threshold |
|-------|------------|-----------|
| Entity Name | Fuzzy | 85% similarity |
| Date of Incorporation | Exact | 100% |
| Address | Fuzzy | 80% similarity |

#### 3.3.3 Weighted Scoring Algorithm (Entity)

| Field | Weight |
|-------|--------|
| Entity name | 30% |
| Address | 25% |
| Key personnel | 20% |
| Business details | 15% |
| Contact information | 10% |
| **Total** | **100%** |

---

### 3.4 Match Classification

Based on the weighted score, matches are classified as:

| Classification | Score Range | Action |
|----------------|-------------|--------|
| **Exact Match** | 100% | Auto-link with audit log |
| **Strong Match** | 85-99% | Flag for officer review |
| **Medium Match** | 70-84% | Flag for officer review |
| **Weak Match** | 50-69% | Flag for officer review |
| **No Significant Match** | <50% | Proceed with new KYC |

> **Design Decision:** All matches between 50-99% are flagged for review. The classification (Strong/Medium/Weak) helps officers prioritize and understand confidence level.

---

### 3.5 Fuzzy Matching Algorithm

For name and address matching, the system uses fuzzy string matching.

**Algorithm:** Levenshtein distance with normalization

**Pre-processing:**
1. Convert to uppercase
2. Remove special characters
3. Normalize whitespace
4. Remove common prefixes/suffixes (Mr., Mrs., Pvt., Ltd., etc.)

**Example:**
```
Input 1: "RAJESH KUMAR SHARMA"
Input 2: "RAJESH K. SHARMA"
Similarity: 89% → Strong Match
```

---

### 3.6 Open Items for Section 3

| Item | Status | Notes |
|------|--------|-------|
| Fuzzy matching library/algorithm | To be decided | Levenshtein vs Jaro-Winkler vs Soundex |
| Threshold tuning | To be validated | May need adjustment based on false positive/negative rates |
| Name variation handling | To be defined | Nicknames, transliteration differences |
| Address normalization rules | To be defined | Pin code mismatch handling |

---

## 4. KYC Officer Workflow

### 4.1 Overview

KYC Officers handle:
1. **Flagged reviews** — Partial matches requiring manual decision
2. **Fresh KYC initiation** — Moving applicants from PENDING to INITIATED
3. **Change reviews** — Approving/rejecting customer-reported changes
4. **Final decisions** — Approving/rejecting KYC after all verifications

---

### 4.2 Case Assignment and Fetching

#### 4.2.1 Case Queue

Officers see cases based on `s_applicants.kyc_status`:

| Queue | Status Filter | Priority |
|-------|---------------|----------|
| Flagged for Review | `FLAGGED_FOR_REVIEW` | High |
| Pending Initiation | `PENDING` | Medium |
| Change Requests | `CHANGE_IN_KYC_DETAILS` | Medium |
| Final Decision | Linked to `s_applicant_kyc.kyc_status = FINAL_DECISION_PENDING` | High |

#### 4.2.2 Case Details View

For each case, officer sees:
- Applicant details from `s_applicants`
- Uploaded documents from `s_applicant_documents`
- Potential matches with confidence scores (for flagged cases)
- Side-by-side comparison of data fields (for flagged cases)
- Change history (for change requests)

---

### 4.3 Match Resolution (Flagged Cases)

When reviewing `FLAGGED_FOR_REVIEW` cases:

```
Officer reviews potential matches
            │
            ▼
┌─────────────────────────────────┐
│ Officer Decision                │
├─────────────────────────────────┤
│ (A) Select a match              │
│ (B) Reject all matches          │
│ (C) Manual KYC initiation       │
└─────────────────────────────────┘
```

#### 4.3.1 Option A: Match Selection

Officer confirms applicant matches an existing KYC:

1. Select the matching `s_applicant_kyc` record
2. System updates:
   - `s_applicants.kyc_id` → linked to selected KYC
   - `s_applicants.kyc_status` → copies from `s_applicant_kyc.kyc_status`
3. Log entry: Officer decision with reasoning

#### 4.3.2 Option B: No Match Selection

Officer determines no suggested match is correct:

1. Officer rejects all suggested matches
2. Create new `s_applicant_kyc` record from `s_applicants` data
3. Copy documents from `s_applicant_documents` to `s_applicant_kyc_documents`
4. Set `s_applicants.kyc_status = INITIATED`
5. Set `s_applicant_kyc.kyc_status = INITIATED`
6. Trigger fresh KYC verification process

#### 4.3.3 Option C: Manual KYC Initiation

Officer reviews and initiates KYC manually:

1. Review applicant details and documents
2. Validate document completeness and quality
3. Make corrections if needed (update `s_applicants`)
4. Create `s_applicant_kyc` record with reviewed data
5. Copy documents to `s_applicant_kyc_documents`
6. Set statuses to `INITIATED`

---

### 4.4 Change Request Review

When `s_applicants.kyc_status = CHANGE_IN_KYC_DETAILS`:

```
Officer reviews change request
            │
            ▼
┌─────────────────────────────────┐
│ Compare:                        │
│ - Existing master KYC data      │
│ - New data in s_applicants      │
│ - Supporting documents          │
└─────────────────────────────────┘
            │
            ▼
┌─────────────────────────────────┐
│ Officer Decision                │
├─────────────────────────────────┤
│ (A) Approve changes             │
│ (B) Reject changes              │
│ (C) Request more documents      │
└─────────────────────────────────┘
```

#### 4.4.1 Approve Changes

1. Update `s_applicant_kyc` with new data from `s_applicants`
2. Copy new documents to `s_applicant_kyc_documents`
3. Create history record in `s_applicant_kyc_history`
4. Set `s_applicants.kyc_status = COMPLETED`
5. `s_applicant_kyc.kyc_status` remains `COMPLETED`
6. Log: Changes approved with officer notes

#### 4.4.2 Reject Changes

1. `s_applicant_kyc` remains unchanged
2. Set `s_applicants.kyc_status = REJECTED` (or revert to previous)
3. Log: Rejection reason

---

### 4.5 Final KYC Decision

When `s_applicant_kyc.kyc_status = FINAL_DECISION_PENDING`:

#### 4.5.1 Pre-Approval Checklist

Officer verifies:
- [ ] All API verifications completed
- [ ] All documents verified
- [ ] Risk assessment completed
- [ ] Deviations resolved (if any)
- [ ] AML screening cleared

#### 4.5.2 Approval

1. Generate and assign UCIC to `s_applicant_kyc`
2. Set `s_applicant_kyc.kyc_status = COMPLETED`
3. Set `s_applicants.kyc_status = COMPLETED`
4. Set `kyc_expiry_date` based on risk category
5. Log: Approval with officer notes

#### 4.5.3 Rejection

1. Set `s_applicant_kyc.kyc_status = REJECTED`
2. Set `s_applicants.kyc_status = REJECTED`
3. Log: Rejection reason (mandatory)

---

### 4.6 Deviation Handling

If any verification fails or data is inconsistent:

1. Officer documents the deviation
2. Provides justification
3. High-risk deviations require supervisor approval
4. All deviations logged in audit trail

---

### 4.7 Open Items for Section 4

| Item | Status | Notes |
|------|--------|-------|
| Case assignment logic | To be defined | Round-robin, skill-based, or manual pickup |
| SLA tracking | To be defined | Time limits per case type |
| Supervisor approval workflow | To be defined | For high-risk deviations |
| Officer permission matrix | To be defined | Who can approve what |

---

## 5. KYC Verification Process

### 5.1 Overview

KYC verification confirms the authenticity of submitted identity documents and data. This happens after `s_applicant_kyc` record is created (status = `INITIATED`).

---

### 5.2 Verification Steps

```
s_applicant_kyc created (INITIATED)
            │
            ▼
┌─────────────────────────────────┐
│ 1. Third-party API Verification │
├─────────────────────────────────┤
│ 2. CKYC Check                   │
├─────────────────────────────────┤
│ 3. AML Screening                │
├─────────────────────────────────┤
│ 4. Document Verification        │
├─────────────────────────────────┤
│ 5. Risk Assessment              │
└─────────────────────────────────┘
            │
            ▼
All checks complete → FINAL_DECISION_PENDING
```

---

### 5.3 Third-Party API Verification

| Document Type | Verification API | Data Validated |
|---------------|------------------|----------------|
| Aadhaar | UIDAI XML/OTP | Name, DOB, Address, Photo |
| PAN | NSDL/UTI | Name, DOB, PAN status |
| Voter ID | ECI | Name, Address, Voter ID status |
| Passport | MEA | Name, DOB, Passport validity |
| Driving License | Vahan/Parivahan | Name, DOB, DL validity |

**Verification Result Handling:**

| Result | Action |
|--------|--------|
| Success | Mark verification as passed, store response |
| Failure | Mark as failed, flag for manual review |
| API Error | Retry with backoff, escalate if persistent |

---

### 5.4 CKYC Integration

#### 5.4.1 CKYC Search (Future Enhancement)

1. Search CKYC registry using PAN or Aadhaar + DOB
2. If KIN found:
   - Fetch CKYC record
   - Compare with submitted data
   - Flag discrepancies for review
3. Store KIN in `s_applicant_ckyc.ckyc_no`

#### 5.4.2 CKYC Upload (Post-Approval)

After KYC is approved:
1. Generate CKYC XML in prescribed format
2. Upload to CKYC registry
3. Store returned KIN

---

### 5.5 AML Screening

Anti-Money Laundering checks against:
- PEP (Politically Exposed Persons) lists
- Sanctions lists (UN, OFAC, etc.)
- Negative news screening
- Internal watchlists

**Result Handling:**

| Result | Action |
|--------|--------|
| Clear | Proceed |
| Potential Match | Flag for compliance review |
| Confirmed Match | Escalate to compliance officer |

---

### 5.6 Document Verification

Verify authenticity of uploaded documents in `s_applicant_kyc_documents`:

| Check | Method |
|-------|--------|
| Document quality | Image quality score |
| Tampering detection | Forensic analysis |
| Data extraction | OCR + validation |
| Expiry check | Compare with current date |
| Cross-reference | Match extracted data with submitted data |

---

### 5.7 Risk Assessment

Calculate risk score based on:

| Factor | Weight | Source |
|--------|--------|--------|
| Geographic risk | 20% | Pin code, state |
| Occupation risk | 20% | Employment type |
| Transaction patterns | 30% | Bank statement analysis |
| AML screening results | 30% | AML check outcome |

**Risk Categories:**

| Category | Score Range | KYC Validity | Re-KYC Frequency |
|----------|-------------|--------------|------------------|
| Low | 0-30 | 10 years | Every 10 years |
| Medium | 31-60 | 8 years | Every 8 years |
| High | 61-100 | 2 years | Every 2 years |

---

### 5.8 Verification Completion

When all verifications pass:
1. Set `s_applicant_kyc.kyc_status = FINAL_DECISION_PENDING`
2. Case appears in officer's Final Decision queue
3. Officer reviews and makes final decision

---

### 5.9 Open Items for Section 5

| Item | Status | Notes |
|------|--------|-------|
| API vendor selection | To be decided | For each verification type |
| Verification retry policy | To be defined | Max retries, backoff strategy |
| AML vendor integration | To be decided | Which screening service |
| Risk scoring model | To be refined | Weights may need tuning |

---

## 6. KYC Status Management

> **Note:** This section consolidates status information. Detailed status definitions are in Section 2.10.

### 6.1 Status Flow Diagram

#### 6.1.1 `s_applicants.kyc_status` Flow

```
                    ┌─────────────┐
                    │   PENDING   │
                    └──────┬──────┘
                           │
           ┌───────────────┼───────────────┐
           │               │               │
           ▼               ▼               ▼
   ┌───────────────┐ ┌───────────┐  ┌──────────────────────┐
   │ FLAGGED_FOR_  │ │ INITIATED │  │ CHANGE_IN_KYC_       │
   │ REVIEW        │ │           │  │ DETAILS              │
   └───────┬───────┘ └─────┬─────┘  └──────────┬───────────┘
           │               │                   │
           │               ▼                   │
           │       ┌───────────────┐           │
           └──────►│  COMPLETED    │◄──────────┘
                   └───────┬───────┘
                           │
              ┌────────────┼────────────┐
              ▼            │            ▼
      ┌───────────┐        │     ┌───────────┐
      │  EXPIRED  │        │     │ REJECTED  │
      └───────────┘        │     └───────────┘
                           │
                    (Re-KYC triggers
                     new application)
```

#### 6.1.2 `s_applicant_kyc.kyc_status` Flow

```
              ┌───────────┐
              │ INITIATED │
              └─────┬─────┘
                    │
                    ▼
        ┌───────────────────────┐
        │ FINAL_DECISION_       │
        │ PENDING               │
        └───────────┬───────────┘
                    │
         ┌──────────┴──────────┐
         ▼                     ▼
   ┌───────────┐         ┌───────────┐
   │ COMPLETED │         │ REJECTED  │
   └─────┬─────┘         └───────────┘
         │
         ▼
┌─────────────────────┐
│ CHANGE_IN_KYC_      │
│ DETAILS             │
└──────────┬──────────┘
           │
    ┌──────┴──────┐
    ▼             ▼
┌───────────┐ ┌───────────┐
│ COMPLETED │ │ REJECTED  │
└─────┬─────┘ └───────────┘
      │
      ▼
┌───────────┐
│  EXPIRED  │
└───────────┘
```

---

### 6.2 Status Transition Rules

#### 6.2.1 `s_applicants.kyc_status` Transitions

| From | To | Trigger | Actor |
|------|-----|---------|-------|
| `PENDING` | `FLAGGED_FOR_REVIEW` | Partial match found | System |
| `PENDING` | `INITIATED` | Officer initiates KYC | KYC Officer |
| `FLAGGED_FOR_REVIEW` | `INITIATED` | Officer resolves match | KYC Officer |
| `FLAGGED_FOR_REVIEW` | `PENDING` | Officer rejects matches | KYC Officer |
| `INITIATED` | `COMPLETED` | KYC approved | KYC Officer |
| `INITIATED` | `REJECTED` | KYC rejected | KYC Officer |
| `CHANGE_IN_KYC_DETAILS` | `COMPLETED` | Changes approved | KYC Officer |
| `CHANGE_IN_KYC_DETAILS` | `REJECTED` | Changes rejected | KYC Officer |
| `COMPLETED` | `EXPIRED` | KYC validity expired | System (scheduled) |

#### 6.2.2 `s_applicant_kyc.kyc_status` Transitions

| From | To | Trigger | Actor |
|------|-----|---------|-------|
| `INITIATED` | `FINAL_DECISION_PENDING` | All verifications complete | System |
| `INITIATED` | `REJECTED` | Verification failed | KYC Officer |
| `FINAL_DECISION_PENDING` | `COMPLETED` | Final approval | KYC Officer |
| `FINAL_DECISION_PENDING` | `REJECTED` | Final rejection | KYC Officer |
| `COMPLETED` | `CHANGE_IN_KYC_DETAILS` | Customer reports changes | Customer/RM |
| `CHANGE_IN_KYC_DETAILS` | `COMPLETED` | Changes approved | KYC Officer |
| `CHANGE_IN_KYC_DETAILS` | `REJECTED` | Changes rejected | KYC Officer |
| `COMPLETED` | `EXPIRED` | Validity period ended | System (scheduled) |

---

### 6.3 KYC Expiry and Re-KYC

#### 6.3.1 Expiry Calculation

`kyc_expiry_date` is set based on risk category at approval:

| Risk Category | Validity Period |
|---------------|-----------------|
| Low | 10 years from approval |
| Medium | 8 years from approval |
| High | 2 years from approval |

#### 6.3.2 Expiry Handling

**Scheduled Job:** Daily check for expiring/expired KYCs

| Condition | Action |
|-----------|--------|
| 30 days before expiry | Send reminder notification to customer |
| On expiry date | Set `s_applicant_kyc.kyc_status = EXPIRED` |
| Expired KYC used in active application | Set `s_applicants.kyc_status = EXPIRED` |

#### 6.3.3 Re-KYC Process

When customer with expired KYC applies for new loan:
1. System detects linked KYC is `EXPIRED`
2. Customer prompted to update KYC
3. Fresh KYC collection flow initiated
4. On approval, new expiry date set

---

### 6.4 Open Items for Section 6

| Item | Status | Notes |
|------|--------|-------|
| Expiry notification templates | To be defined | Email/SMS content |
| Re-KYC grace period | To be decided | Allow transactions during re-KYC? |
| Bulk expiry handling | To be defined | For large expiry batches |

---

---

## 7. Tables and Schema

### 7.1 Overview

This section defines the database schema for the KYC module. Tables are organized into:
- **Account Layer** — Authentication and account management
- **Lead/Application Layer** — Loan journey tracking
- **Applicant Layer** — Per-application staging area
- **Master KYC Layer** — Verified, reusable KYC records
- **Supporting Tables** — Documents, consents, audit logs

---

### 7.2 Account Layer

#### 7.2.1 `s_accounts`

Primary account table for customer authentication.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `mobile` | VARCHAR(15) | No | Mobile number (unique) |
| `email` | VARCHAR(255) | Yes | Email address |
| `name` | VARCHAR(255) | Yes | Display name |
| `pin_code` | VARCHAR(10) | Yes | Geographic pin code |
| `status` | ENUM | No | `PENDING`, `ACTIVE`, `BLOCKED`, `DORMANT` |
| `last_login_at` | TIMESTAMP | Yes | Last successful login |
| `identity_verified_at` | TIMESTAMP | Yes | Last identity re-verification |
| `created_at` | TIMESTAMP | No | Account creation time |
| `updated_at` | TIMESTAMP | No | Last update time |

**Indexes:**
- `UNIQUE(mobile)`
- `INDEX(status)`
- `INDEX(last_login_at)`

---

#### 7.2.2 `s_account_kyc_links`

Pivot table linking accounts to multiple KYC records.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `account_id` | BIGINT (FK) | No | Reference to `s_accounts.id` |
| `kyc_id` | BIGINT (FK) | No | Reference to `s_applicant_kyc.id` |
| `is_primary` | BOOLEAN | No | Primary KYC for login verification (default: false) |
| `relationship_type` | ENUM | No | `self`, `spouse`, `parent`, `child`, `business_partner`, `authorized_signatory`, `other` |
| `relationship_justification` | TEXT | Yes | Required if `relationship_type = 'other'` |
| `linked_at` | TIMESTAMP | No | When the link was created |
| `linked_by` | VARCHAR(100) | No | `'self'`, `'system'`, `'rm:{user_id}'`, `'support:{user_id}'` |
| `approved_by` | BIGINT (FK) | Yes | Supervisor who approved (if limit override) |
| `approval_reason` | TEXT | Yes | Reason for override approval |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `UNIQUE(account_id, kyc_id)`
- `INDEX(account_id)`
- `INDEX(kyc_id)`

**Constraints:**
- Foreign key to `s_accounts(id)` ON DELETE CASCADE
- Foreign key to `s_applicant_kyc(id)` ON DELETE RESTRICT

---

### 7.3 Lead/Application Layer

#### 7.3.1 `s_opportunities` (Leads)

Lead tracking table.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `account_id` | BIGINT (FK) | Yes | Reference to `s_accounts.id` |
| `mobile` | VARCHAR(15) | No | Applicant mobile |
| `name` | VARCHAR(255) | No | Applicant name |
| `pan` | VARCHAR(10) | Yes | PAN for bureau check |
| `loan_amount` | DECIMAL(15,2) | Yes | Requested loan amount |
| `source` | ENUM | No | `portal`, `api`, `rm` |
| `partner_code` | VARCHAR(50) | Yes | Partner identifier (API leads) |
| `rm_id` | BIGINT (FK) | Yes | RM who logged the lead |
| `status` | ENUM | No | `NEW`, `ELIGIBLE`, `INELIGIBLE`, `CONVERTED`, `EXPIRED` |
| `eligibility_checked_at` | TIMESTAMP | Yes | Bureau check timestamp |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(mobile, created_at)` — For 30-day deduplication check
- `INDEX(status)`
- `INDEX(account_id)`

---

#### 7.3.2 `s_applications`

Loan application tracking.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `opportunity_id` | BIGINT (FK) | No | Reference to `s_opportunities.id` |
| `account_id` | BIGINT (FK) | Yes | Reference to `s_accounts.id` |
| `application_no` | VARCHAR(50) | No | System-generated application number |
| `loan_product` | VARCHAR(100) | No | Loan product type |
| `loan_amount` | DECIMAL(15,2) | No | Applied loan amount |
| `status` | ENUM | No | Application workflow status |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `UNIQUE(application_no)`
- `INDEX(opportunity_id)`
- `INDEX(account_id)`
- `INDEX(status)`

---

### 7.4 Applicant Layer (Staging)

#### 7.4.1 `s_applicants`

Per-application applicant record with KYC staging data.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `application_id` | BIGINT (FK) | No | Reference to `s_applications.id` |
| `kyc_id` | BIGINT (FK) | Yes | Reference to `s_applicant_kyc.id` (linked after KYC approval) |
| `applicant_type` | ENUM | No | `primary_borrower`, `co_applicant`, `guarantor` |
| `entity_type` | ENUM | No | `individual`, `entity` |
| `kyc_status` | ENUM | No | See Section 2.10.1 |
| **— Individual KYC Fields —** | | | |
| `first_name` | VARCHAR(100) | Yes | |
| `middle_name` | VARCHAR(100) | Yes | |
| `last_name` | VARCHAR(100) | Yes | |
| `father_name` | VARCHAR(255) | Yes | |
| `mother_name` | VARCHAR(255) | Yes | |
| `spouse_name` | VARCHAR(255) | Yes | |
| `date_of_birth` | DATE | Yes | |
| `gender` | ENUM | Yes | `male`, `female`, `other` |
| `marital_status` | ENUM | Yes | `single`, `married`, `divorced`, `widowed` |
| `pan` | VARCHAR(10) | Yes | |
| `aadhaar` | VARCHAR(12) | Yes | Encrypted |
| `voter_id` | VARCHAR(20) | Yes | |
| `passport_no` | VARCHAR(20) | Yes | |
| `driving_license` | VARCHAR(20) | Yes | |
| **— Entity KYC Fields —** | | | |
| `entity_name` | VARCHAR(255) | Yes | |
| `entity_type_detail` | ENUM | Yes | `proprietorship`, `partnership`, `llp`, `pvt_ltd`, `public_ltd`, `trust`, `society` |
| `cin` | VARCHAR(21) | Yes | Corporate Identification Number |
| `llpin` | VARCHAR(20) | Yes | LLP Identification Number |
| `udyam_no` | VARCHAR(25) | Yes | UDYAM Registration Number |
| `gstin` | VARCHAR(15) | Yes | |
| `date_of_incorporation` | DATE | Yes | |
| **— Address Fields —** | | | |
| `current_address_line1` | VARCHAR(255) | Yes | |
| `current_address_line2` | VARCHAR(255) | Yes | |
| `current_city` | VARCHAR(100) | Yes | |
| `current_state` | VARCHAR(100) | Yes | |
| `current_pin_code` | VARCHAR(10) | Yes | |
| `permanent_address_line1` | VARCHAR(255) | Yes | |
| `permanent_address_line2` | VARCHAR(255) | Yes | |
| `permanent_city` | VARCHAR(100) | Yes | |
| `permanent_state` | VARCHAR(100) | Yes | |
| `permanent_pin_code` | VARCHAR(10) | Yes | |
| **— Contact Fields —** | | | |
| `mobile` | VARCHAR(15) | No | |
| `email` | VARCHAR(255) | Yes | |
| `alternate_mobile` | VARCHAR(15) | Yes | |
| **— Application-Specific Fields —** | | | |
| `occupation` | VARCHAR(100) | Yes | |
| `employer_name` | VARCHAR(255) | Yes | |
| `monthly_income` | DECIMAL(15,2) | Yes | |
| `source_of_income` | VARCHAR(255) | Yes | |
| **— Metadata —** | | | |
| `source` | ENUM | No | `portal`, `api`, `rm` |
| `logged_by` | BIGINT (FK) | Yes | RM user ID (if RM journey) |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(application_id)`
- `INDEX(kyc_id)`
- `INDEX(kyc_status)`
- `INDEX(pan)`
- `INDEX(mobile)`

---

#### 7.4.2 `s_applicant_documents`

Documents uploaded during application (staging).

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `applicant_id` | BIGINT (FK) | No | Reference to `s_applicants.id` |
| `document_type` | ENUM | No | `pan_card`, `aadhaar`, `voter_id`, `passport`, `driving_license`, `utility_bill`, `bank_statement`, `coi`, `moa`, `aoa`, `gst_certificate`, `other` |
| `document_name` | VARCHAR(255) | No | Original file name |
| `file_path` | VARCHAR(500) | No | Storage path |
| `file_size` | INT | No | Size in bytes |
| `mime_type` | VARCHAR(100) | No | File MIME type |
| `ocr_extracted` | JSON | Yes | OCR extracted data |
| `verification_status` | ENUM | No | `pending`, `verified`, `failed`, `manual_review` |
| `change_metadata` | JSON | Yes | `{ "change_type": "address", "reason": "relocation" }` |
| `uploaded_by` | VARCHAR(100) | No | `'customer'`, `'rm:{user_id}'`, `'api:{partner}'` |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(applicant_id)`
- `INDEX(document_type)`
- `INDEX(verification_status)`

---

### 7.5 Master KYC Layer

#### 7.5.1 `s_applicant_kyc`

Master KYC record (golden record).

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `ucic` | VARCHAR(20) | Yes | Unique Customer Identification Code (assigned on approval) |
| `entity_type` | ENUM | No | `individual`, `entity` |
| `kyc_status` | ENUM | No | See Section 2.10.2 |
| `risk_category` | ENUM | Yes | `low`, `medium`, `high` |
| `kyc_approved_at` | TIMESTAMP | Yes | Approval timestamp |
| `kyc_expiry_date` | DATE | Yes | Calculated based on risk category |
| `ckyc_no` | VARCHAR(20) | Yes | CKYC KIN (if registered) |
| **— Individual KYC Fields —** | | | |
| `first_name` | VARCHAR(100) | Yes | |
| `middle_name` | VARCHAR(100) | Yes | |
| `last_name` | VARCHAR(100) | Yes | |
| `father_name` | VARCHAR(255) | Yes | |
| `mother_name` | VARCHAR(255) | Yes | |
| `spouse_name` | VARCHAR(255) | Yes | |
| `date_of_birth` | DATE | Yes | |
| `gender` | ENUM | Yes | `male`, `female`, `other` |
| `marital_status` | ENUM | Yes | `single`, `married`, `divorced`, `widowed` |
| `pan` | VARCHAR(10) | Yes | |
| `aadhaar` | VARCHAR(12) | Yes | Encrypted |
| `voter_id` | VARCHAR(20) | Yes | |
| `passport_no` | VARCHAR(20) | Yes | |
| `driving_license` | VARCHAR(20) | Yes | |
| **— Entity KYC Fields —** | | | |
| `entity_name` | VARCHAR(255) | Yes | |
| `entity_type_detail` | ENUM | Yes | |
| `cin` | VARCHAR(21) | Yes | |
| `llpin` | VARCHAR(20) | Yes | |
| `udyam_no` | VARCHAR(25) | Yes | |
| `gstin` | VARCHAR(15) | Yes | |
| `date_of_incorporation` | DATE | Yes | |
| **— Address Fields —** | | | |
| `current_address_line1` | VARCHAR(255) | Yes | |
| `current_address_line2` | VARCHAR(255) | Yes | |
| `current_city` | VARCHAR(100) | Yes | |
| `current_state` | VARCHAR(100) | Yes | |
| `current_pin_code` | VARCHAR(10) | Yes | |
| `permanent_address_line1` | VARCHAR(255) | Yes | |
| `permanent_address_line2` | VARCHAR(255) | Yes | |
| `permanent_city` | VARCHAR(100) | Yes | |
| `permanent_state` | VARCHAR(100) | Yes | |
| `permanent_pin_code` | VARCHAR(10) | Yes | |
| **— Contact Fields —** | | | |
| `mobile` | VARCHAR(15) | No | |
| `email` | VARCHAR(255) | Yes | |
| `alternate_mobile` | VARCHAR(15) | Yes | |
| **— Metadata —** | | | |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `UNIQUE(ucic)` (where not null)
- `INDEX(kyc_status)`
- `INDEX(pan)`
- `INDEX(aadhaar)` (if searchable)
- `INDEX(mobile)`
- `INDEX(kyc_expiry_date)`

---

#### 7.5.2 `s_applicant_kyc_documents`

Verified documents linked to master KYC.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `kyc_id` | BIGINT (FK) | No | Reference to `s_applicant_kyc.id` |
| `document_type` | ENUM | No | Same as `s_applicant_documents` |
| `document_name` | VARCHAR(255) | No | Original file name |
| `file_path` | VARCHAR(500) | No | Storage path |
| `file_size` | INT | No | Size in bytes |
| `mime_type` | VARCHAR(100) | No | File MIME type |
| `ocr_extracted` | JSON | Yes | OCR extracted data |
| `verified_at` | TIMESTAMP | Yes | When document was verified |
| `verified_by` | BIGINT (FK) | Yes | KYC Officer who verified |
| `source_applicant_document_id` | BIGINT | Yes | Original staging document ID |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(kyc_id)`
- `INDEX(document_type)`

---

#### 7.5.3 `s_applicant_kyc_history`

History of changes to master KYC records.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `kyc_id` | BIGINT (FK) | No | Reference to `s_applicant_kyc.id` |
| `change_type` | ENUM | No | `created`, `updated`, `status_change`, `expired` |
| `changed_fields` | JSON | Yes | `{ "field": { "old": "value1", "new": "value2" } }` |
| `changed_by` | BIGINT (FK) | Yes | User who made the change |
| `change_reason` | TEXT | Yes | Reason for change |
| `source_applicant_id` | BIGINT | Yes | `s_applicants.id` that triggered change |
| `created_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(kyc_id)`
- `INDEX(created_at)`

---

### 7.6 Supporting Tables

#### 7.6.1 `s_consents`

Consent tracking for all KYC-related consents.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `account_id` | BIGINT (FK) | Yes | Reference to `s_accounts.id` |
| `applicant_id` | BIGINT (FK) | Yes | Reference to `s_applicants.id` |
| `kyc_id` | BIGINT (FK) | Yes | Reference to `s_applicant_kyc.id` |
| `consent_type` | ENUM | No | `bureau_fetch`, `kyc_reuse`, `kyc_change`, `aadhaar_verification`, `ckyc_fetch` |
| `consent_text` | TEXT | No | Full legal text of consent |
| `consent_version` | VARCHAR(20) | No | Version of consent text |
| `ip_address` | VARCHAR(45) | Yes | Client IP address |
| `device_info` | JSON | Yes | Device/browser information |
| `otp_reference` | VARCHAR(100) | Yes | OTP verification reference |
| `consented_at` | TIMESTAMP | No | When consent was given |
| `created_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(account_id)`
- `INDEX(applicant_id)`
- `INDEX(kyc_id)`
- `INDEX(consent_type)`

---

#### 7.6.2 `s_kyc_verifications`

Track third-party verification results.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `kyc_id` | BIGINT (FK) | No | Reference to `s_applicant_kyc.id` |
| `verification_type` | ENUM | No | `pan`, `aadhaar`, `voter_id`, `passport`, `driving_license`, `cin`, `gstin`, `aml`, `ckyc` |
| `vendor` | VARCHAR(100) | No | Verification vendor name |
| `request_payload` | JSON | Yes | Request sent (masked PII) |
| `response_payload` | JSON | Yes | Response received (masked PII) |
| `status` | ENUM | No | `success`, `failed`, `pending`, `error` |
| `match_score` | DECIMAL(5,2) | Yes | Confidence score (0-100) |
| `error_message` | TEXT | Yes | Error details if failed |
| `retry_count` | INT | No | Number of retries (default: 0) |
| `verified_at` | TIMESTAMP | Yes | Successful verification time |
| `created_at` | TIMESTAMP | No | |
| `updated_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(kyc_id)`
- `INDEX(verification_type)`
- `INDEX(status)`

---

#### 7.6.3 `s_kyc_match_candidates`

Store potential matches for officer review.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `applicant_id` | BIGINT (FK) | No | Reference to `s_applicants.id` |
| `matched_kyc_id` | BIGINT (FK) | No | Reference to potentially matching `s_applicant_kyc.id` |
| `match_score` | DECIMAL(5,2) | No | Overall weighted score (0-100) |
| `match_classification` | ENUM | No | `exact`, `strong`, `medium`, `weak` |
| `field_scores` | JSON | No | `{ "name": 95, "dob": 100, "address": 85, ... }` |
| `resolution_status` | ENUM | No | `pending`, `accepted`, `rejected` |
| `resolved_by` | BIGINT (FK) | Yes | KYC Officer who resolved |
| `resolution_reason` | TEXT | Yes | Officer's notes |
| `resolved_at` | TIMESTAMP | Yes | |
| `created_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(applicant_id)`
- `INDEX(matched_kyc_id)`
- `INDEX(resolution_status)`

---

#### 7.6.4 `s_kyc_audit_logs`

Comprehensive audit trail for all KYC operations.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | BIGINT (PK) | No | Primary key |
| `entity_type` | ENUM | No | `account`, `applicant`, `kyc`, `document` |
| `entity_id` | BIGINT | No | ID of the entity |
| `action` | VARCHAR(100) | No | Action performed (e.g., `KYC_INITIATED`, `KYC_APPROVED`) |
| `actor_type` | ENUM | No | `customer`, `rm`, `officer`, `system`, `api` |
| `actor_id` | BIGINT | Yes | User ID if applicable |
| `actor_identifier` | VARCHAR(255) | Yes | Additional identifier (API key, partner code) |
| `ip_address` | VARCHAR(45) | Yes | |
| `old_values` | JSON | Yes | Previous state |
| `new_values` | JSON | Yes | New state |
| `metadata` | JSON | Yes | Additional context |
| `created_at` | TIMESTAMP | No | |

**Indexes:**
- `INDEX(entity_type, entity_id)`
- `INDEX(action)`
- `INDEX(actor_type, actor_id)`
- `INDEX(created_at)`

---

### 7.7 Open Items for Section 7

| Item | Status | Notes |
|------|--------|-------|
| Aadhaar encryption strategy | To be defined | Encryption at rest, key management |
| Soft delete vs hard delete | To be decided | For compliance requirements |
| Data retention policy | To be defined | How long to keep audit logs |
| Partitioning strategy | To be decided | For large tables like audit logs |

---

## 8. Data Migration Strategy

### 8.1 Overview

This section outlines the strategy for migrating existing customer data to the new KYC module schema. Migration must:
- Preserve data integrity
- Minimize downtime
- Handle edge cases gracefully
- Maintain audit trail

---

### 8.2 Migration Scope

#### 8.2.1 Source Data

Existing data that needs migration:

| Source Table | Target Table(s) | Notes |
|--------------|-----------------|-------|
| Legacy customer accounts | `s_accounts` | Mobile-based deduplication |
| Legacy KYC records | `s_applicant_kyc` | Identify and merge duplicates |
| Legacy documents | `s_applicant_kyc_documents` | Verify file integrity |
| Historical applications | `s_applicants` | Snapshot preservation |

---

### 8.3 Migration Phases

#### 8.3.1 Phase 1: Data Analysis & Cleansing

**Objectives:**
1. Identify data quality issues
2. Map source to target schema
3. Create deduplication rules

**Tasks:**
- [ ] Profile source data (null rates, patterns, outliers)
- [ ] Identify duplicate records (same PAN, mobile, name+DOB)
- [ ] Document data quality issues
- [ ] Define transformation rules for each field
- [ ] Create exception handling procedures

**Deduplication Strategy:**

```
For each unique PAN:
  1. Find all records with this PAN
  2. If single record → Direct migration
  3. If multiple records:
     a. Compare other identifiers (Aadhaar, mobile)
     b. If all match → Merge (keep most recent)
     c. If conflicts → Flag for manual review
```

---

#### 8.3.2 Phase 2: Schema Setup

**Tasks:**
- [ ] Create new tables in target database
- [ ] Set up indexes (create after bulk load for performance)
- [ ] Configure foreign key constraints (disable during migration)
- [ ] Set up audit logging infrastructure

---

#### 8.3.3 Phase 3: Pilot Migration

**Objectives:**
1. Validate migration scripts
2. Measure performance
3. Identify edge cases

**Approach:**
- Migrate 1% sample of records
- Run validation checks
- Measure time per record
- Identify and fix issues

---

#### 8.3.4 Phase 4: Full Migration

**Pre-Migration:**
- [ ] Take full backup of source system
- [ ] Disable foreign key constraints
- [ ] Disable triggers (re-enable post-migration)
- [ ] Communicate downtime window

**Migration Order:**

```
1. s_accounts (foundation)
        │
        ▼
2. s_applicant_kyc (master records)
        │
        ▼
3. s_account_kyc_links (relationships)
        │
        ▼
4. s_applicant_kyc_documents (documents)
        │
        ▼
5. s_opportunities → s_applications → s_applicants (loan journey)
        │
        ▼
6. s_kyc_verifications, s_consents (supporting data)
        │
        ▼
7. s_kyc_audit_logs (create migration audit entries)
```

**Post-Migration:**
- [ ] Re-enable foreign key constraints
- [ ] Create indexes
- [ ] Run validation queries
- [ ] Enable triggers

---

### 8.4 Data Transformation Rules

#### 8.4.1 Account Migration

| Source Field | Target Field | Transformation |
|--------------|--------------|----------------|
| `phone_number` | `s_accounts.mobile` | Normalize to 10 digits |
| `email_address` | `s_accounts.email` | Lowercase, trim |
| `customer_name` | `s_accounts.name` | Title case |
| `last_activity_date` | `s_accounts.last_login_at` | Direct |
| *(derived)* | `s_accounts.status` | See rules below |

**Status Derivation:**
```
IF last_activity > 2 years → DORMANT
ELSE IF verified = true → ACTIVE
ELSE → PENDING
```

---

#### 8.4.2 KYC Record Migration

| Scenario | Action |
|----------|--------|
| Single record per PAN | Migrate directly to `s_applicant_kyc` |
| Multiple records, identical data | Merge → single `s_applicant_kyc` |
| Multiple records, conflicting data | Flag for manual review |
| Missing mandatory fields | Migrate with `kyc_status = PENDING` |
| No KYC documents | Migrate with `kyc_status = PENDING` |

**UCIC Generation:**
- Existing UCIC: Preserve as-is
- No UCIC: Generate new UCIC for `COMPLETED` records
- Format: `{YEAR}{SEQUENCE}` (e.g., `2024000001`)

---

### 8.5 Validation Checks

#### 8.5.1 Pre-Migration Validation

| Check | Query/Method | Expected |
|-------|--------------|----------|
| Source record count | `SELECT COUNT(*) FROM source_table` | Document for comparison |
| Null analysis | `SELECT COUNT(*) WHERE field IS NULL` | Document null rates |
| Duplicate detection | Group by PAN/mobile | Identify duplicates |

#### 8.5.2 Post-Migration Validation

| Check | Query/Method | Expected |
|-------|--------------|----------|
| Record count match | Compare source vs target | Match within tolerance |
| Foreign key integrity | Enable constraints | No violations |
| Data spot checks | Sample 100 random records | 100% accuracy |
| Document file integrity | Verify file paths exist | All documents accessible |

---

### 8.6 Rollback Plan

**Trigger Conditions:**
- Data validation failures >1%
- Foreign key constraint violations
- Performance degradation
- Critical bugs discovered

**Rollback Steps:**
1. Stop migration process
2. Truncate target tables
3. Restore from pre-migration backup
4. Re-enable legacy system
5. Document issues for remediation

---

### 8.7 Open Items for Section 8

| Item | Status | Notes |
|------|--------|-------|
| Source system schema documentation | To be obtained | Need ERD from legacy system |
| Downtime window approval | To be scheduled | Coordinate with stakeholders |
| Migration scripts | To be developed | Based on transformation rules |
| Manual review queue | To be designed | For conflicting records |

---

## 9. Customer Account Management System

### 9.1 Overview

The Customer Account Management System (CAMS) handles account-related operations that are not part of the standard KYC flow:
- Account recovery for blocked accounts
- Account merging/deduplication
- Mobile number updates
- Profile management
- Support workflows

---

### 9.2 Account Recovery

#### 9.2.1 Blocked Account Scenarios

| Reason for Block | Recovery Path |
|------------------|---------------|
| Identity mismatch (180-day re-verification) | Support verification flow |
| Security concern (suspicious activity) | Security team review |
| Fraud detection | Compliance review (may not be recoverable) |
| Customer request | Self-service unblock with OTP |

---

#### 9.2.2 Support Verification Flow

For blocked accounts due to identity mismatch:

```
Customer contacts support
         │
         ▼
Support agent verifies identity
(Video call / In-person / Document upload)
         │
         ▼
┌─────────────────────────────────┐
│ Identity Verified?              │
└─────────────────────────────────┘
       │              │
      Yes             No
       │              │
       ▼              ▼
  Unblock account   Maintain block
  Link correct KYC  Create incident
       │              report
       ▼
  Log: ACCOUNT_UNBLOCKED
  (with verification method)
```

**Required Documentation:**
- Video KYC recording (if video call)
- Uploaded identity documents
- Support agent notes
- Supervisor approval (for high-risk cases)

---

### 9.3 Account Merging

#### 9.3.1 When Merging is Needed

| Scenario | Description |
|----------|-------------|
| Duplicate accounts | Same customer has multiple accounts (different mobile numbers) |
| Mobile number change | Customer's primary mobile changed, old account exists |
| Fraud recovery | Legitimate customer's mobile was fraudulently used |

---

#### 9.3.2 Merge Process

```
Support initiates merge request
         │
         ▼
Identify source account (to be merged)
Identify target account (to be retained)
         │
         ▼
Validate:
- Both accounts verified as same customer
- No active loans on source account OR transfer handled
- KYC records compatible
         │
         ▼
┌─────────────────────────────────┐
│ Execute Merge:                  │
│ 1. Move KYC links to target     │
│ 2. Update application references│
│ 3. Merge loan history           │
│ 4. Block source account         │
│ 5. Create audit trail           │
└─────────────────────────────────┘
         │
         ▼
Notify customer of merge completion
```

**Merge Rules:**
- Target account retains its `account_id`
- Source account is `BLOCKED` (not deleted) with `blocked_reason = 'merged'`
- All `s_account_kyc_links` from source are moved to target
- All `s_applications` are re-linked to target account
- Comprehensive audit log entry created

---

### 9.4 Mobile Number Update

#### 9.4.1 Self-Service Mobile Update

Customer can update mobile number if:
- Account is `ACTIVE`
- KYC is `COMPLETED`
- No pending loan applications

```
Customer requests mobile update
         │
         ▼
Verify current identity (OTP to old mobile)
         │
         ▼
Enter new mobile number
         │
         ▼
┌─────────────────────────────────┐
│ Is new mobile already in system?│
└─────────────────────────────────┘
       │              │
      Yes             No
       │              │
       ▼              ▼
  Reject request   Send OTP to new mobile
  (Contact support)        │
                           ▼
                   Verify new mobile OTP
                           │
                           ▼
                   Update s_accounts.mobile
                   Log: MOBILE_UPDATED
```

---

#### 9.4.2 Support-Assisted Mobile Update

For cases where self-service is not possible:

1. Customer provides identity proof
2. Support verifies via video call or in-person
3. Support initiates mobile update with supervisor approval
4. Both old and new mobile receive notification
5. 24-hour cooling period before update is effective
6. Customer can cancel during cooling period

---

### 9.5 Profile Management

#### 9.5.1 Profile Selection

Customers with multiple KYC profiles linked:

- On login: Display profile selection screen
- Show: Name, applicant type, last used date
- Allow: Switching profiles within session
- Store: Selected `kyc_id` in session

---

#### 9.5.2 Primary Profile Management

| Action | Description |
|--------|-------------|
| View linked profiles | List all KYC records linked to account |
| Set primary profile | Change which profile is auto-selected |
| Remove profile link | Unlink a KYC from account (support only) |

**Remove Link Rules:**
- Cannot remove if active loan exists under that profile
- Cannot remove the only profile (account would have no KYC)
- Requires OTP confirmation
- Logged in audit trail

---

### 9.6 Support Workflows

#### 9.6.1 Support Dashboard Features

| Feature | Description |
|---------|-------------|
| Account search | Search by mobile, email, name, PAN, UCIC |
| Account details | View account status, linked KYCs, applications |
| Action history | View all actions taken on account |
| Impersonation | View portal as customer (read-only) |
| Document download | Access uploaded documents |

---

#### 9.6.2 Support Actions Matrix

| Action | Support Agent | Senior Support | Supervisor | Compliance |
|--------|---------------|----------------|------------|------------|
| View account | ✓ | ✓ | ✓ | ✓ |
| Unblock account (low risk) | ✓ | ✓ | ✓ | ✓ |
| Unblock account (high risk) | | ✓ | ✓ | ✓ |
| Merge accounts | | | ✓ | ✓ |
| Update mobile | | ✓ | ✓ | ✓ |
| Remove KYC link | | | ✓ | ✓ |
| Override KYC status | | | | ✓ |

---

### 9.7 Audit & Compliance

#### 9.7.1 Account Management Audit Events

| Event | Logged Data |
|-------|-------------|
| `ACCOUNT_BLOCKED` | Reason, blocker ID, timestamp |
| `ACCOUNT_UNBLOCKED` | Verification method, approver, notes |
| `ACCOUNT_MERGED` | Source ID, target ID, merged data |
| `MOBILE_UPDATED` | Old mobile (masked), new mobile (masked), method |
| `KYC_LINK_REMOVED` | KYC ID, reason, approver |
| `PROFILE_SWITCHED` | Previous KYC ID, new KYC ID |

---

#### 9.7.2 Compliance Reporting

| Report | Frequency | Contents |
|--------|-----------|----------|
| Blocked accounts | Daily | New blocks, unblock requests pending |
| Account merges | Weekly | All merges with supporting documentation |
| Mobile updates | Weekly | All mobile changes with verification details |
| Support actions | Monthly | All support actions by agent |

---

### 9.8 Open Items for Section 9

| Item | Status | Notes |
|------|--------|-------|
| Video KYC integration | To be decided | Vendor selection for video verification |
| Cooling period duration | To be confirmed | 24 hours proposed |
| Impersonation audit requirements | To be defined | What to log, how long to retain |
| Support ticketing integration | To be designed | CRM/ticketing system integration |

---

## 10. Appendix

### 10.1 Status Quick Reference

#### `s_accounts.status`
| Status | Description |
|--------|-------------|
| `PENDING` | Created but OTP not verified |
| `ACTIVE` | Fully functional |
| `BLOCKED` | Disabled (security/identity issue) |
| `DORMANT` | Inactive for extended period |

#### `s_applicants.kyc_status`
| Status | Description |
|--------|-------------|
| `PENDING` | Submitted, not initiated |
| `FLAGGED_FOR_REVIEW` | Potential duplicate |
| `INITIATED` | Processing begun |
| `CHANGE_IN_KYC_DETAILS` | Changes reported |
| `COMPLETED` | Successfully completed |
| `REJECTED` | Rejected |
| `EXPIRED` | Linked KYC expired |

#### `s_applicant_kyc.kyc_status`
| Status | Description |
|--------|-------------|
| `INITIATED` | Verification in progress |
| `FINAL_DECISION_PENDING` | Awaiting approval |
| `COMPLETED` | Approved and active |
| `REJECTED` | Rejected |
| `CHANGE_IN_KYC_DETAILS` | Under change review |
| `EXPIRED` | Validity expired |

---

### 10.2 Document Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | TBD | Team | Initial documentation |

---

*End of Document*
