Delegate this task to the @coder agent.

Refactor the following: $ARGUMENTS

The coder agent has the verification-before-completion skill. It must follow these rules:

- Do NOT change external behavior -- this is a pure refactor
- Run `php -l` on changed files after each edit
- Preserve all existing public method signatures (they may be called from other controllers/traits)
- Follow existing patterns -- do not introduce new architectural patterns without discussion
- Keep changes minimal and reviewable
- If the refactor is large, break it into small logical steps
- Search for reusable logic in traits/helpers/services before writing new code
