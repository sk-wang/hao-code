---
description: Review recent code changes for quality issues
argument-hint: optional file or directory path
allowed-tools: Bash, Read, Glob, Grep
---

Review the code changes in the current repository:

1. Run `git diff HEAD~1` to see the latest changes (or use `git diff` if not committed)
2. If $ARGUMENTS is provided, review changes in that specific path
3. Analyze the code for:
   - Security vulnerabilities (XSS, injection, etc.)
   - Error handling issues
   - Performance problems
   - Code style inconsistencies
   - Missing tests
   - Logic errors
4. Provide a summary with severity levels (Critical/Warning/Info)

Focus on actionable feedback with specific line references.
