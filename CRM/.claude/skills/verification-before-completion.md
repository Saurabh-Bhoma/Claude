---
name: Verification Before Completion
description: Use when about to claim a task is complete, when finishing implementation, or when saying "done"
---

# Verification Before Completion

Before claiming done, you MUST run verification and cite the output:

- **PHP files**: `php -l <file>` for every modified file
- **Logic changes**: Run relevant tests
- **Route changes**: `php artisan route:list --path=<affected-path>`
- **Model changes**: Verify `$connection`, `$table`, `$fillable`

**Banned phrases** (indicate no verification): "should work", "that should fix it", "looks correct", "I believe", "probably works now."

Replace with evidence: "I ran X and the output confirms Y."
