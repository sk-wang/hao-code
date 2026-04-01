---
description: Create a git commit with staged changes
argument-hint: optional commit message
allowed-tools: Bash, Read, Glob, Grep
---

Create a git commit following these steps:

1. Run `git status` to see all staged and unstaged changes
2. Run `git diff --cached` to see staged changes
3. If nothing is staged, run `git add -A` to stage all changes
4. Look at recent `git log --oneline -5` for commit message style
5. Write a clear, descriptive commit message
6. Create the commit with: `git commit -m "message"`

The commit message should:
- Use the imperative mood
- Be under 72 characters
- Accurately describe the changes

$ARGUMENTS
