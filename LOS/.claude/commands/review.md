Delegate this task to the @reviewer agent.

Review the code changes in the current branch compared to master.

The reviewer agent has the verification-before-completion skill and will perform a two-stage review:

**Stage 1: Spec Compliance** -- Did we build what was asked?
- Read git log and full diff against master
- Verify each change accomplishes its stated intent
- Flag anything missing, extra, or half-finished

**Stage 2: Code Quality** -- Did we build it well?
- Style, security (OWASP), data integrity, performance, pattern compliance, debug leftovers

Categorize findings as CRITICAL / IMPORTANT / SUGGESTION. Lead with what was done well.

$ARGUMENTS
