Resume a previous session from saved context.

Steps:
1. List files in `.claude/sessions/` sorted by date (most recent first)
2. If $ARGUMENTS specifies a session file, load that one. Otherwise, load the most recent.
3. Read the session file completely
4. Present a briefing to the user:
   - What was done last time
   - What the next steps are
   - Current branch and any uncommitted changes (run `git status` and `git diff --stat`)
5. Ask if the user wants to proceed with the documented next steps or take a different direction
6. Wait for confirmation before starting any work

$ARGUMENTS
