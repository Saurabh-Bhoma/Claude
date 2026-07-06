Save the current session context so it can be resumed in a future conversation.

Steps:
1. Analyze what was accomplished in this session
2. Identify any unfinished work or blockers
3. Write a session file to `.claude/sessions/` with this structure:

```markdown
# Session: [Brief Title]
Date: [today's date]
Branch: [current git branch]

## What Was Done
- [completed work items]

## What Worked
- [approaches/patterns that were successful]

## What Failed / Blockers
- [approaches that didn't work and why]
- [unresolved issues]

## Current State
- [files modified but not committed]
- [tests passing/failing]
- [any temporary debug code left in place]

## Next Steps (exact actions to take)
1. [specific next action]
2. [specific next action]

## Key Context
- [important business logic decisions made]
- [non-obvious things the next session needs to know]
```

4. Create the `.claude/sessions/` directory if it doesn't exist
5. Name the file with date and branch: `YYYY-MM-DD_branch-name.md`
6. Confirm the file was saved and show the path

$ARGUMENTS
