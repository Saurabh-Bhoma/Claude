# LOS — MR !3345 "N+1 query fix" — Code Review

**Repo:** velocity/velocity-los-api
**MR:** !3345 (branch `N+1_query_fix` → `master`)
**Author:** Saurabh Bhoma (saurabh.bhoma)
**Date:** 2026-07-11

## Context

Reviewed MR !3345, which applies N+1 query optimizations across 18 files in the LOS backend. The consistent pattern is: batch-fetch related rows before a loop using `whereIn(...)->get()->keyBy()/groupBy()`, or eager-load relations via `->with()`, replacing per-iteration `::where(...)->first()/get()` calls.

**Overall:** Approach is correct and consistently applied. No SQL injection or auth concerns. Several items to verify/fix before merge, plus two unrelated changes riding along.

### High — verify before merge
1. **CaseTraits.php (~L206)** — `$employmentByApplicantId = $getData->co_applicants_employment->keyBy('applicant_id')` is now the single source for primary employment, `primaryApplicantEmployment`, `coApplicants`, and `$getData->applicants`. Confirm that relation covers every applicant in all those sets (scoped to this application); otherwise some employments silently become null vs the old per-row query.
2. **ApplicationsOverdueController.php (~L59)** — inconsistent date comparison. First filter normalizes via `Carbon::parse(...)->toDateString()`; `dueButNotCollectedCount` compares raw `$repayment->repay_date <= $tillDate`. If repay_date carries a time component, a repayment due exactly on tillDate is excluded by the raw compare but included by the normalized one → wrong count. Normalize both.

### Medium
3. **keyBy() first-vs-last semantics** — `->where(col,X)->first()` returns the first match; `->get()->keyBy(col)` keeps the last on collision. Affected where key may be non-unique: DebtorDump.php (~L43, EntityAddress/EntityContactDetail by entity_id), CaseTraits.php (~L216, EntityContactDetail by pan_no), CaseTraits.php (~L1409/1533/1565, ApplicationCharges by charge_id). Confirm uniqueness or emulate "first" via `reverse()->keyBy()` or `groupBy()->map->first()`.
4. **AfterApplicationCreated.php + AMLController.php** — amlCheck now called once with all applicant IDs (was per-applicant). Coordinated with the controller refactor; confirm no per-call early-return/side effect assuming a single applicant, and note per-applicant failures are no longer isolated.
5. **ApplicationsOverdueController.php** — leftover N+1s in the same method: notDueAmount/notDueCount still use `->repayments()` query builder (repayments already eager-loaded); per-application queries still inside the loop: ApplicationChecks (x2), RepaymentSchedule pre_emi count, ApplicantEmploymentDetails, PaymentOrderTransactions, and `Applicants::where('id', $legal['id'])->first()` in the legal-owners loop.

### Low / nits
6. **CLAUDE.md** — version bump (PHP 8.1→8.3, Laravel 9.5→12.0) unrelated to N+1; split out or drop if accidental.
7. **CKYCController.php (~L66)** — `File::deleteDirectory($localPath)` before `extractTo` is a functional/safety change, not N+1. Confirm `$localPath` is unique per-operation; stray blank line at ~L160.
8. **Verify added eager-loads are consumed** — EMIOverdueReminders `.lp`, ApplicationAutoWithdrawn `disbursalRequest`, DailyOverdueEMIsMail `applicant.bureau`/`assets.legal_validation`/`repayments`, ReconTraits `applications.applicant`.
9. **Broad whereIn fetches** — LedgerController::restructureForeClosure (IN across all closed apps) and CaseTraits doc fetch; consider chunking if volume is large.

**Clean, no notes:** AMLController, AnalysisEngineController, AIFaceMatchEvents, BHNDriver, BHNTraits, DailyOverduesJob, LedgerTraits, ApplicationCharges batch refactors.

**Status:** Review delivered in chat. MR inline comments + summary note NOT yet posted (awaiting confirmation). Teams DM to author skipped (author = Saurabh).

## Token Usage

Exact token metering isn't exposed in this chat interface, so the following is a rough estimate:

- Input: ~45–55K tokens (system + memory + full MR diff for 18 files + full read of ApplicationsOverdueController.php + tool schemas)
- Output: ~4–5K tokens (review + this log)
- Tool calls: GitHub raw fetch (instructions/memory), GitLab MCP (diffs, MR metadata, file contents), tool_search loads

*Estimate only — not exact.*
