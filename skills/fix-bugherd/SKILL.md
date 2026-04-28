---
name: fix-bugherd
description: "Fix bugs end-to-end — with or without a BugHerd task. Use when user says 'fix bugherd task X', 'fix BH-123', passes a BugHerd URL, or describes a bug in plain text like 'fix the broken nav on mobile', 'the contact form emails are blank', 'there's a bug where...'. Dual-mode: BugHerd-connected pipeline OR plain-text bug fix workflow. Always includes research, fix, review, commit."
---

# Fix BugHerd (dual-mode: ticket or plain text)

Fix bugs end-to-end. Two modes:

1. **BugHerd mode** — full pipeline with API: read task, fix, review, commit, update BugHerd status, post comment
2. **Plain text mode** — same fix quality without BugHerd: parse user's bug description, fix, review, commit

## Prerequisites

**Read `CONFIG.md` at the start of every run.** Contains BugHerd API key, project IDs, column/status mappings, and team member IDs. If `CONFIG.md` doesn't exist, warn the user — BugHerd handoff will be unavailable but plain text mode still works.

## When to Use

**BugHerd mode:**
- User passes a BugHerd task ID or URL (e.g., `BH-123`, `https://www.bugherd.com/projects/123/tasks/456`)
- User says "fix bugherd task X", "fix BH-XXX"

**Plain text mode:**
- User describes a bug without a ticket: "fix the broken nav on mobile"
- User says "there's a bug where...", "the contact form is broken", "X doesn't work when Y"
- User pastes an error message or describes unexpected behavior

## Mode Detection

**Before Phase 0, detect the mode:**

1. Does the first argument match a BugHerd task ID pattern (`BH-\d+`, `bh-\d+`, or a `bugherd.com` URL)?
   - **YES** → BugHerd mode. Extract task/project IDs.
   - **NO** → Plain text mode. The entire user message after the command is the bug description.

2. Does the user's message describe a bug even without a formal invocation?
   - Keywords: "fix", "broken", "bug", "error", "doesn't work", "not working", "fails", "issue"
   - If YES → Plain text mode. Treat the description as the bug to fix.

## Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| (first arg) | required | BugHerd task ID/URL, OR plain text bug description |
| `branch` | `ask` | Branch strategy: `ask`, `main`, `new`, `worktree` |
| `skip-review` | `false` | Skip review phase |
| `auto-commit` | `true` | Auto-commit after fix verified |

## Invocation

```
# BugHerd mode
/fix-bugherd BH-123
/fix-bugherd BH-123 branch=main
/fix-bugherd https://www.bugherd.com/projects/1/tasks/456

# Plain text mode — just describe the bug
/fix-bugherd the mobile nav is broken on iOS Safari, hamburger menu doesn't open
/fix-bugherd contact form emails are blank since the last update
/fix-bugherd fix the 500 error on /applications/project-case-studies/

# Plain text with options
/fix-bugherd search results show wrong products branch=new skip-review=true
```

## Architecture

```
ORCHESTRATOR (main session)
|
+-- MODE DETECTION (before Phase 0)
|   BugHerd task ID/URL? → BUGHERD MODE
|   Plain text description? → PLAIN TEXT MODE
|
+-- Phase 0: BRANCH STRATEGY
|   If branch=ask (default): prompt user with 3 options
|     1. Main branch — commit directly
|     2. Separate branch — create fix/{YYYY-MM-DD}-{slug} branch
|     3. Worktree — isolated worktree
|   Branch slug: BH-{id} (BugHerd mode) or slug from description (plain text)
|
+-- Phase 1: READ BUG DETAILS
|   [BUGHERD MODE] API v2: GET task details, comments, screenshots
|   [PLAIN TEXT MODE] Parse user's message: extract what's broken, where, when
|   Both: confirm repro steps and expected behavior with user
|
+-- Phase 2: RESEARCH & UNDERSTAND → USER GATE
|   Search codebase for affected files
|   Present: root cause + ASCII bug flow diagram + affected files
|   STOP until user confirms
|
+-- Phase 3: ANALYZE & PLAN
|   Simple (1-2 files, <100 lines) → proceed directly
|   Complex (3+ files, DB, cross-cutting) → invoke develop-team
|
+-- Phase 4: IMPLEMENT
|   Apply fix, verify build/lint
|
+-- Phase 5: REVIEW (call review-fix skill)
|   8 parallel reviewers, auto-fix loop
|   Gate: critical/high resolved
|   Skip if skip-review=true
|
+-- Phase 6: COMMIT & PUSH
|   Conventional commit: fix: <description>
|   Push to branch
|
+-- Phase 7: HANDOFF (BugHerd mode only)
|   Update task column (todo → doing → feedback)
|   Post comment: what was fixed, commit hash, review summary
|   SKIP in plain text mode
|
+-- Phase 8: SUMMARY
|   Report: what was fixed, commit, mode used
```

### Phase Execution Order — STRICT SEQUENTIAL

**Execute Mode Detection -> 0 -> 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8 in order.** Phase 0 always runs. Phase 5 skips if `skip-review=true`. Phase 7 runs only in BugHerd mode.

---

## Workflow

### Phase 0: Branch Strategy

**This phase ALWAYS runs first.**

**Read `CONFIG.md`** if it exists. In plain text mode, CONFIG.md is optional.

If `branch` parameter was explicitly set (`main`, `new`, or `worktree`), use that. Otherwise (default `ask`), prompt:

```
Before I start, how would you like to handle the branch?

1. Main branch — commit directly (fast, for simple/urgent fixes)
2. Separate branch — create fix/{slug} branch
3. Worktree — isolated worktree (for parallel fixes)
```

**STOP — wait for response.**

Branch slug:
- BugHerd mode: `fix/{YYYY-MM-DD}-BH-{id}` (e.g., `fix/2026-04-28-BH-123`)
- Plain text mode: `fix/{YYYY-MM-DD}-{slug}` (e.g., `fix/2026-04-28-mobile-nav-broken`)

| Choice | Action |
|--------|--------|
| **Main** | Stay on current branch |
| **Separate branch** | `git checkout -b fix/{YYYY-MM-DD}-{slug}` |
| **Worktree** | Call `EnterWorktree` with name `fix-{YYYY-MM-DD}-{slug}` |

**Before branching, check for uncommitted changes:**
```bash
git status --porcelain
```
If dirty: warn user, offer to `git stash` before creating branch. Do NOT create branch with uncommitted changes.

### Phase 1: Read Bug Details

#### BugHerd Mode

Parse task ID from argument:
- `BH-123` → extract numeric ID
- `https://www.bugherd.com/projects/{pid}/tasks/{tid}` → extract project ID and task ID

**Fetch Task Details:**

```bash
curl -s -u "API_KEY:x" \
  "https://www.bugherd.com/api_v2/projects/{project_id}/tasks/{task_id}.json"
```

Extract:
- `description`: what's broken, repro steps, expected behavior
- `status`: current column/status
- `priority`: urgency
- `requester`: who reported it
- `screenshot_urls`: visual evidence

**Fetch Comments:**

```bash
curl -s -u "API_KEY:x" \
  "https://www.bugherd.com/api_v2/projects/{project_id}/tasks/{task_id}/comments.json"
```

**Fetch Columns** (for status mapping later):

```bash
curl -s -u "API_KEY:x" \
  "https://www.bugherd.com/api_v2/projects/{project_id}/columns.json"
```

Map column names to IDs for Phase 7 transitions.

If BugHerd API fails: fall back to plain text mode for this bug. Warn user.

#### Plain Text Mode

Parse the user's message to extract:

1. **What's broken**: the symptom or error
2. **Where**: page, component, browser, device
3. **When**: after what action, since when, specific conditions
4. **Expected behavior**: what should happen instead
5. **Error messages**: any PHP/JS errors mentioned

If the description is vague, ask the user clarifying questions before proceeding:

```
I need a few details to investigate:

1. What page/URL is this happening on?
2. What steps reproduce the bug?
3. What should happen instead?
4. Any error messages visible?
```

**Confirm with user** before moving to Phase 2:

```
Here's what I understand:

- Bug: {summary}
- Where: {location}
- Repro steps: {steps}
- Expected: {expected behavior}

Look right? I'll start researching.
```

Do NOT proceed to Phase 2 until the user confirms.

### Phase 2: Research & Understand

**MANDATORY: Before writing any code, research the affected area.**

**Research budget** — keep focused:

| Task specificity | Strategy | Budget |
|-----------------|----------|--------|
| Names specific component/file | Direct search: Glob -> Read -> Grep | ~5 tool calls |
| Names a feature area | Explore agent, focused prompt | ~15 tool calls |
| Generic description | Explore agent, broad prompt | ~25 tool calls |

**Present findings to user:**

1. **Root cause** (1-2 sentences)

2. **Bug flow diagram** (MANDATORY):

   ```
   User action -> component -> function() -> BUG: <description>
   ```

3. **Affected files** (table or list)

4. **Proposed approach**

**STOP — ask user: "Does this look right?"** Do NOT proceed until confirmed.

### Phase 3: Analyze & Plan

Classify complexity:

| Threshold | Simple | Complex |
|-----------|--------|---------|
| Files changed | 1-2 files | 3+ files |
| Lines changed | <100 lines | 100+ lines |
| Scope | Single concern | Cross-cutting |
| DB changes | No | Yes |

- **Simple fix**: Plan directly, proceed to Phase 4
- **Complex fix**: Invoke `develop-team` skill for collaborative planning

### Phase 4: Implement

1. Apply code change using Edit tool
2. Verify: `php -l <file-path>` for PHP files
3. If lint fails: fix, re-verify (max 3 attempts)
4. Run relevant tests if available

### Phase 5: Review

**Skip if `skip-review=true`.**

Invoke the `review-fix` skill:
- 8 parallel reviewers examine the diff
- Auto-fix quick items
- Gate: critical/high findings resolved (max 2 cycles)

### Phase 6: Commit & Push

**STOP — user gate.** Present the fix summary. Ask: "Push branch and create PR?" Do NOT proceed until user confirms.

Commit body must include the fix table. Then create a PR.

**BugHerd mode:**
```bash
git add <files-changed>
git commit -m "$(cat <<'EOF'
fix: <description> [BH-{id}]

| Bug | File(s) | Fix | How to test |
|-----|---------|-----|-------------|
| {what was broken} | {file:line} | {what changed} | {steps to verify} |

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
git push origin <branch>
```

**Plain text mode:**
```bash
git add <files-changed>
git commit -m "$(cat <<'EOF'
fix: <description>

| Bug | File(s) | Fix | How to test |
|-----|---------|-----|-------------|
| {what was broken} | {file:line} | {what changed} | {steps to verify} |

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
git push origin <branch>
```

**Create PR** (both modes):
```bash
gh pr create \
  --title "fix: <description>" \
  --body "$(cat <<'EOF'
| Bug | File(s) | Fix | How to test |
|-----|---------|-----|-------------|
| {what was broken} | {file:line} | {what changed} | {steps to verify} |

## How to review
- [ ] Fix addresses the root cause
- [ ] No regressions
- [ ] Lint/build passes
EOF
)"
```

### Phase 7: Handoff

**BugHerd mode only. Skip entirely in plain text mode.**

If `CONFIG.md` has no API key configured, skip with warning.

#### 1. Transition Task to 'Feedback'

Find the `feedback` column ID from Phase 1's column list:

```bash
curl -s -u "API_KEY:x" \
  -X PUT \
  -H "Content-Type: application/json" \
  -d "{\"task\": {\"column_id\": <feedback_column_id>}}" \
  "https://www.bugherd.com/api_v2/projects/{project_id}/tasks/{task_id}.json"
```

If no `feedback` column, use `doing` during fix then `done` on complete.

#### 2. Post Summary Comment

```bash
curl -s -u "API_KEY:x" \
  -X POST \
  -H "Content-Type: application/json" \
  -d "{\"comment\": {\"text\": \"**Fix applied** — $(cat <<'INNER'
- **Root cause**: <description>
- **Commit**: \`<hash>\` — <subject>

| Bug | File(s) | Fix | How to test |
|-----|---------|-----|-------------|
| {what was broken} | {file:line} | {what changed} | {steps to verify} |

- **Review**: <summary>
INNER
)\"}}" \
  "https://www.bugherd.com/api_v2/projects/{project_id}/tasks/{task_id}/comments.json"
```

#### 3. Assign if Team Member Configured

```bash
curl -s -u "API_KEY:x" \
  -X PUT \
  -H "Content-Type: application/json" \
  -d "{\"task\": {\"assigned_to_id\": <qa_user_id>}}" \
  "https://www.bugherd.com/api_v2/projects/{project_id}/tasks/{task_id}.json"
```

### Phase 8: Summary

**BugHerd mode:**
```
## Fix Complete: BH-{id}

**Task**: {description summary}
**Branch**: `fix/{YYYY-MM-DD}-BH-{id}`
**Status**: Moved to Feedback
**Commit**: `{short-hash}` — {commit subject}

### Bug & fix
| Bug | File(s) | Fix | How to test |
|-----|---------|-----|-------------|
| {what was broken} | {file:line} | {what changed} | {steps to verify} |

### Review
{findings summary or "Skipped"}

### BugHerd
{link to task}

### Phase tracker
| Phase | Status |
|-------|--------|
| 0. Branch Strategy | {main / new branch / worktree} |
| 1. Read Task | Done |
| 2. Research | Done — user acknowledged |
| 3. Plan | {Simple / Complex -> develop-team} |
| 4. Implement | Done |
| 5. Review | {Done / Skipped} |
| 6. Commit & Push | Done — {hash} |
| 7. BugHerd Handoff | {Done / Skipped} |
| 8. Summary | Done |
```

**Plain text mode:**
```
## Fix Complete

**Bug**: {description summary from user}
**Branch**: `fix/{YYYY-MM-DD}-{slug}`
**Commit**: `{short-hash}` — {commit subject}

### Bug & fix
| Bug | File(s) | Fix | How to test |
|-----|---------|-----|-------------|
| {what was broken} | {file:line} | {what changed} | {steps to verify} |

### Review
{findings summary or "Skipped"}

### Phase tracker
| Phase | Status |
|-------|--------|
| 0. Branch Strategy | {main / new branch / worktree} |
| 1. Read Details | Done — user confirmed |
| 2. Research | Done — user acknowledged |
| 3. Plan | {Simple / Complex -> develop-team} |
| 4. Implement | Done |
| 5. Review | {Done / Skipped} |
| 6. Commit & Push | Done — {hash} |
| 7. Handoff | N/A (plain text mode) |
| 8. Summary | Done |
```

---

## Error Handling

| Scenario | Action |
|----------|--------|
| BugHerd API fails (auth) | Warn user, fall back to plain text mode |
| BugHerd API fails (network) | Retry once, then fall back to plain text mode |
| BugHerd task not found | Ask user for correct task ID or describe bug |
| API key not configured | Run in plain text mode, skip Phase 7 |
| Column ID not found | Use first available column, warn user |
| Plain text description too vague | Ask clarifying questions before proceeding |
| Push rejected | Stash -> pull --rebase -> stash pop -> push |
| Commit fails (hook) | Fix issues, create new commit |
| Build/lint fails | Fix, re-verify (max 3 attempts) |
| `gh` CLI not installed | Warn user, skip PR creation, push branch only |
| Branch already exists | Use unique suffix, or prompt for different branch name |

## Tips

1. **Plain text is the default fallback** — no BugHerd task needed. Just describe the bug.
2. **BugHerd screenshots are gold** — task screenshots often show the exact visual bug
3. **Test with a simple task first** — try a text change or CSS fix to verify the pipeline
4. **Configure team members** — update `CONFIG.md` with real BugHerd user IDs for auto-assignment
5. **Parallel fixes with worktrees** — use `branch=worktree` for concurrent fixes
6. **Column names vary between projects** — always fetch columns in Phase 1 to map correct IDs
7. **Vague descriptions get clarified** — plain text mode asks follow-up questions, doesn't guess
