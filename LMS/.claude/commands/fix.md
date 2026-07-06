Delegate this task to the @coder agent.

I'm seeing this error: $ARGUMENTS

The coder agent has the systematic-debugging and verification-before-completion skills. It must follow this protocol:

1. **Root cause first** -- parse the error, read the source, trace backward through the call chain. NO fixes without investigation.
2. **Pattern analysis** -- has this happened elsewhere? Is it a symptom of a deeper issue?
3. **Hypothesis testing** -- state hypothesis clearly, find evidence, confirm or reject.
4. **Implementation** -- fix the root cause (not the symptom), run `php -l`, run tests, verify the fix.

Guardrail: if 3+ fix attempts fail, STOP and reassess the approach.
