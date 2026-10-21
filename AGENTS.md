# Strictly always read AGENT.md & AGENT.local.md both files when starting implement the prompt.  

# Mandatory Git Read-Only Policy

These rules are mandatory for every coding task in this repository.

## 1. Git Commands: Read-Only Only

The coding agent is strictly prohibited from executing any Git command that can create, modify, delete, overwrite, or otherwise change repository state, Git metadata, commits, branches, tags, remotes, indexes, or worktrees.

The agent may execute read-only Git commands only, including:
- `git status`
- `git diff`
- `git diff --check`
- `git log`
- `git show`
- `git branch --list`
- `git worktree list`
- `git rev-parse`
- `git ls-files`
- `git remote -v`
- `git diff --stat`

Other Git commands are allowed only if their read-only behavior is certain and they cannot change state.

## 2. Explicitly Forbidden Git Operations

Never execute:
- `git add`, `git commit`, `git push`
- `git pull`, `git fetch`
- `git checkout`, `git switch`
- `git reset`, `git restore`, `git clean`
- `git stash`
- `git merge`, `git rebase`, `git cherry-pick`, `git revert`
- Branch, tag, remote, or Git configuration changes
- Worktree creation, removal, pruning, locking, or other mutations
- `git apply`, index updates, repository maintenance that writes state, or any equivalent write operation
- Any Git command with flags that make an otherwise read-only command modify state

Do not attempt to achieve a prohibited operation through another command, script, shell, Git alias, or tool.

## 3. Human-Controlled Git Changes

Only the human user may perform Git operations that write, delete, or modify Git state, including staging, committing, pushing, branch management, and worktree management.

If a task appears to require a prohibited Git operation:
1. Stop before executing it.
2. Explain what operation is needed and why.
3. Show the relevant read-only evidence or proposed commands.
4. Wait for the human user to perform the operation.

Never commit, push, reset, clean, switch branches, or remove a worktree on the user's behalf.

## 4. Worktree Safety

Treat every Git worktree as potentially containing valuable uncommitted work.

Never delete, reset, clean, overwrite, or otherwise alter another worktree. Do not assume a worktree is disposable merely because its branch is old, behind another branch, or has no unique commits.

## 5. Reporting

At the end of each task, report:
- The Git commands used.
- Whether any Git state-changing operation was attempted.
- The final `git status` summary.
- Any operation that requires the human user's action.

When uncertain whether a Git command is read-only, do not execute it. Explain the uncertainty and ask the user.