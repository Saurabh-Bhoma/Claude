---
name: Systematic Debugging
description: Use when diagnosing errors, bugs, unexpected behavior, test failures, or when a previous fix attempt failed
---

# Systematic Debugging

Extends the Debugging Protocol in CLAUDE.md with enforcement rules:

## Enforcement

- **No fixes without root cause investigation first.** Read error -> trace backward -> check data -> reproduce mentally.
- **State hypothesis clearly** before attempting any fix: "The error occurs because X passes Y to Z, which expects W."
- **3-strike rule**: If 3 fix attempts fail, STOP and reassess the entire approach.
- **No mystery fixes**: If you can't explain WHY your fix works, it's not a fix.
- **Check blast radius**: A fix in a trait/helper may affect every caller. Search for usages first.

## Common Root Causes

- Wrong `$connection` on model (silent wrong data)
- Missing `s_` prefix on table name
- Stale config/route cache
- Trait method name collision
- `CommonHelper` edge cases (null, empty array)
