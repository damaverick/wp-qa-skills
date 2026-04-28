# BugHerd Fix Pipeline Configuration

## Mode Support

This skill operates in two modes:
- **BugHerd mode** (task ID provided) — requires this CONFIG.md with valid API key
- **Plain text mode** (bug described in words) — CONFIG.md optional, no API calls

If CONFIG.md doesn't exist or API key is missing, plain text mode still works fully — just skip BugHerd handoff.

## BugHerd API

| Setting | Value | Description |
|---------|-------|-------------|
| `api_base` | `https://www.bugherd.com/api_v2` | REST API v2 base |
| `api_key` | **REQUIRED — set before use** | API key from BugHerd settings |
| `auth_header` | `Basic <base64(api_key:x)>` | Basic auth with API key as username |

Get your API key: BugHerd > Settings > API Keys.

## Project IDs

| Project | ID | Description |
|---------|-----|-------------|
| `my-project` | **REQUIRED** | Your project name here |

Find project IDs: `GET /api_v2/projects.json` with your API key.

## Status Mappings

BugHerd uses a column/status model where each project has its own column structure.
Fetch yours: `GET /api_v2/projects/{id}/columns.json`

Standard BugHerd status flow:

| Phase | BugHerd Status | Description |
|-------|---------------|-------------|
| Before fix | `todo` | Task awaiting fix |
| During fix | `doing` | Fix in progress |
| After fix | `feedback` | Fix applied, needs verification |
| Verified | `done` | QA verified |

**IMPORTANT:** BugHerd uses columns, not status transitions. To change status, update the task's `column_id`:
```
PUT /api_v2/projects/{project_id}/tasks/{task_id}.json
Body: {"task": {"column_id": <id>}}
```

## Team Members

| Alias | BugHerd ID | Role |
|-------|-----------|------|
| `dev` | (set your ID) | Developer |
| `qa` | (set QA ID) | QA reviewer |

Find user IDs: `GET /api_v2/projects/{id}/members.json`

## Branch Strategy

| Parameter | Behaviour |
|-----------|----------|
| `ask` (default) | Prompt user to choose main/new/worktree |
| `main` | Commit directly to main |
| `new` | Create `fix/{YYYY-MM-DD}-BH-{id}` branch |
| `worktree` | Isolated worktree |

## Commit Convention

```
fix: <description> [BH-{id}]

Co-Authored-By: Claude <noreply@anthropic.com>
```

## BugHerd Comment Format

After fix, post comment with:
- What was fixed (root cause + solution)
- Commit hash
- Files changed
- QA verification steps
- Review findings summary

## Test BugHerd Task

For testing the pipeline: use a BugHerd task with minimal complexity.
Example: `BH-1` (text change, CSS fix, etc.)
