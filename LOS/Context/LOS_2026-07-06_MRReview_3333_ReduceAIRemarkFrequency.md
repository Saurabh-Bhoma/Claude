# LOS | 2026-07-06 | MR Review !3333 — Reduce AI Remark Frequency

---

## Context

### Summary
Reviewed MR **!3333** (`58050_reduce_ai_remark_frequency`) on `velocity-los-api`.
- **Author:** Kiran Singh (`kiran.singh@homevillegroup.com`)
- **File changed:** `app/Http/Controllers/V1/CommentController.php`
- **Goal:** Rate-limit AI credit-remark generation to once per hour per application

### Review Findings

#### 🔴 Blocking
- **`aiCallback()` fatal crash (L514):** The new `ai_remark_initiated` delete uses `->first()->delete()`, but the mass delete immediately above already removes those rows — so `first()` always returns `null`, causing `Call to a member function delete() on null`. Since `aiCallback()` has no try/catch, the error callback 500s and never writes `is_ai_remark_producible`, breaking the very path meant to clear the block.
- **Fix:** Remove the redundant block (mass delete already covers it), or mass-delete without `->first()`.

#### 🟡 Should Fix
- **Race condition on the guard (L263):** The `ai_remark_initiated` marker is written only *after* the external guzzle call succeeds. Two near-simultaneous requests both pass the check and both fire the AI call. Suggest `Cache::lock($application->id)` or pre-inserting the marker before the call.

#### 🟢 Minor / Confirm
- `diffInMinutes(..., false)` + `(int)` — signed flag is Carbon-version-fragile; truncation can show "0 minute(s)". Use `ceil()` instead.
- Confirm `negativeResponse()` accepts a 3rd data arg (all other calls in the file pass only `message, code`).
- Confirm `type` column (enum/validation) accepts new value `ai_remark_initiated`.
- Rate-limit guard only applies to async (non-co-lending) path — co-lending is unguarded. Fine if intentional.

### Actions Taken
- ✅ 3 inline threads posted on MR !3333 via GitLab MCP (`gitlab:create_merge_request_thread`)
- ✅ Summary discussion thread posted on MR !3333
- ✅ Review summary sent to **Kiran Singh** as a 1:1 Teams DM via `teams-mcp`
- ✅ Teams MCP confirmed connected (authenticated as Saurabh Bhoma)
- ✅ Memory updated: added Teams DM rule to MR review protocol
- ✅ Memory cleared and reset to GitHub-based memory/instruction references
- ✅ Instruction file loaded from `https://github.com/Saurabh-Bhoma/Claude/blob/main/Instruction.md`

---

## Token Usage

> _Exact token counts are not exposed by the Claude web interface at runtime._
> Approximate usage based on session activity:

| Component | Estimate |
|---|---|
| Input tokens (prompts + tool results + file reads) | ~18,000 |
| Output tokens (responses + review content + GitLab comments) | ~4,500 |
| **Total estimated** | **~22,500** |

_For precise usage, check your Anthropic usage dashboard at [console.anthropic.com](https://console.anthropic.com)._
