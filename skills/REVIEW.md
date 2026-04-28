# Skills Review — site-audit & fix-bugherd

Review date: 2026-04-28

---

## 1. Phase Sequences — Complete & Logical?

**site-audit:** Solid flow. One gap: Phase 2 (Crawl) has no dependency check on Phase 1 output. If audit.py finds zero QM files in Phase 1, Phase 2 should still run (it generates them). Re-run of audit.py after Phase 2 isn't conditional — runs even if crawl produced no new data. Minor waste, not a bug.

**fix-bugherd:** Good. Phase 1 plain text mode has a user confirmation gate, and Phase 0 also has a gate (branch strategy). Two consecutive STOPs before real work. Fine for correctness but slows simple fixes. If `branch=new` is explicit, Phase 0 auto-proceeds — already handled.

**Missing step in both:** Neither skill checks for uncommitted local changes before creating a branch. `git checkout -b` with dirty working tree can cause problems. Add `git stash` or warn.

---

## 2. Entry/Exit Criteria

Mostly implicit, not explicit. Each phase describes what to do but doesn't formally state "entry: X must be true, exit: Y must be true." Works for a skill doc (Claude reads sequentially), but a formal table per phase would make review easier. Not blocking.

**Specific gap:** Phase 4 (Fix Loop) exit criteria says "zero REDs or cap reached" but doesn't define what happens to URLs that went from GREEN to RED due to a fix. Revert rule covers it, but exit criterion should account for net-new REDs.

---

## 3. User Gates — Correctly Placed?

### site-audit

- Phase 3 (Analysis): YES — user sees RED/AMBER/GREEN before fixes start. Correct.
- Phase 6 (Push/PR): NO gate. Skill auto-pushes and creates PR. **Should confirm before push.** User might want to review locally first.

### fix-bugherd

- Phase 0 (Branch): gate when `branch=ask`. Correct.
- Phase 1 (Plain text): confirmation gate. Correct.
- Phase 2 (Research): explicit STOP gate. Correct.
- Phase 6 (Commit/Push): NO gate — auto-pushes. **Same concern as site-audit.** Add confirmation before push.

---

## 4. Fix Table Flow-Through

Traced Bug/File/Fix/How to test through all outputs:

| Location | site-audit | fix-bugherd |
|----------|------------|-------------|
| Commit body | Phase 4 step 3 | Phase 6 |
| PR description | Phase 6 `gh pr create` | Phase 6 `gh pr create` |
| Summary output | Phase 6 summary | Phase 8 summary |
| BugHerd comment | N/A | **Missing.** Phase 7 comment uses bullet points, not the table. Inconsistent. |

**Fix:** Add table to Phase 7's BugHerd comment body, or at minimum reference the commit.

---

## 5. Hardcoded Values

**audit.py lines 20-28:** Thresholds are hardcoded in Python, not read from CONFIG.md. CONFIG.md says "Tune these to your site's performance profile" but audit.py ignores CONFIG.md entirely. Has its own `THRESHOLDS` dict.

**This is the biggest portability gap.** Options:

1. audit.py reads CONFIG.md (parse markdown table — fragile)
2. audit.py accepts `--thresholds` CLI arg as JSON
3. audit.py reads a `thresholds.json` file that CONFIG.md references
4. Convert CONFIG.md thresholds section to `.env` or JSON file

Option 3 cleanest.

**fix-bugherd CONFIG.md line 26:** `capral` project name hardcoded in table. Template should use placeholder like `my-project`.

**crawl.spec.ts:** Clean — all configurable via env vars.

---

## 6. Dual-Mode Detection Coverage

SKILL.md lines 30-38 cover:

- `BH-\d+` — yes
- `bh-\d+` (lowercase) — yes
- `bugherd.com` URL — yes
- Everything else → plain text — yes

**Edge cases not covered:**

- **Bare numeric ID:** "fix task 123" — would trigger plain text (no `BH-` prefix). Probably correct, but document it.
- **Multiple bugs:** `/fix-bugherd BH-123 BH-456` — undefined behavior. Should reject or queue.
- **URL with query params:** `https://www.bugherd.com/projects/1/tasks/456?tab=details` — regex needs to handle trailing params. Not explicitly covered.

---

## 7. Token Budget Strategy

Sound design. Python aggregator runs externally → produces ~5K JSON → Claude reads summary only. Playwright runs externally too. Claude never reads raw QM JSON files or raw logs.

**Concerns:**

- Phase 4 fix loop: "Research → Read cited file ±25 lines." 20 RED URLs each needing research = 20+ file reads with Explore agents. In practice many share root causes, so self-limits. But no explicit token budget for Phase 4.
- **audit.py output size:** 200 URLs × ~100 bytes each = ~20K in `url_scores`. With `indent=2`, could be 30-40K tokens. Not the claimed "~5K tokens for 200 URLs." Consider capping `url_scores` output to RED+AMBER only, or compact JSON for GREEN entries.

---

## 8. Error Handling

### site-audit

Good coverage (7 scenarios). Missing:

- audit.py produces empty/invalid JSON — Phase 3 should handle
- GitHub CLI (`gh`) not installed — PR creation fails silently
- Branch already exists — `git checkout -b` fails

### fix-bugherd

Good coverage (9 scenarios). Missing:

- `gh` not installed — same as above
- File locked/read-only — Edit tool fails
- review-fix skill not available — Phase 5 fails

### audit.py

Handles file I/O errors gracefully (try/except). `parse_qm_json` returns None on bad data. Good. But no validation of QM JSON schema — if a QM file has `url` but no `db` key, `score_url` uses `.get()` with defaults. Safe but could produce misleading zeros.

---

## 9. Branch Naming Conventions

**site-audit:** `audit/{YYYY-MM-DD}-{summary}` — consistent in SKILL.md, README, and architecture diagram.

**fix-bugherd:** Inconsistency found.

| Location | Convention | Correct? |
|----------|-----------|----------|
| SKILL.md line 144 | `fix/{YYYY-MM-DD}-BH-{id}` | Yes |
| CONFIG.md line 64 | `fix/BH-{id}` | **No — missing date stamp** |
| README.md line 156 | `fix/{YYYY-MM-DD}-{slug}` | Yes |

**Fix CONFIG.md line 64** to include date: `fix/{YYYY-MM-DD}-BH-{id}`.

---

## 10. WordPress Coupling vs Portability

| Component | WP-coupled | Portable |
|-----------|-----------|----------|
| SKILL.md (both) | WP-CLI refs, `wp-content/` paths, QM plugin | Phase structure, fix table, review pipeline, commit convention |
| audit.py | `wp-content/debug.log`, `wp-content/qm-output/`, QM JSON schema | Log parsing, dedup, scoring algorithm |
| crawl.spec.ts | `wp-sitemap.xml` assumption | URL crawling, JS error capture, cookie injection |
| CONFIG.md (site-audit) | QM-specific paths, WP caching plugin refs | Threshold concept, env override pattern |
| CONFIG.md (fix-bugherd) | None | Fully portable (BugHerd is platform-agnostic) |

**To make non-WP:** Replace 3 things:

1. QM JSON → any profiler output (Blackfire, XHProf, custom)
2. `wp-sitemap.xml` → generic sitemap or URL list
3. `wp-content/debug.log` → generic error log path

fix-bugherd is ~95% portable already. site-audit is ~60% portable — profiler integration is WordPress-specific.

---

## Action Items

| Priority | Issue | File | Fix |
|----------|-------|------|-----|
| **HIGH** | Thresholds hardcoded in Python, not read from CONFIG | `audit.py:20-28` | Add `--thresholds` JSON arg or read `thresholds.json` |
| **HIGH** | No user gate before `git push` | Both SKILLs, Phase 6 | Add STOP before push/PR |
| **MED** | BugHerd comment missing fix table | fix-bugherd SKILL.md Phase 7 | Add table to comment body |
| **MED** | CONFIG.md branch naming missing date | fix-bugherd CONFIG.md:64 | `fix/{YYYY-MM-DD}-BH-{id}` |
| **MED** | No dirty-tree check before branching | Both SKILLs, Phase 0 | Add `git status --porcelain` check |
| **MED** | `capral` hardcoded in fix-bugherd CONFIG | fix-bugherd CONFIG.md:26 | Use placeholder |
| **LOW** | audit-summary.json may exceed 5K tokens | audit.py | Compact GREEN entries or cap output |
| **LOW** | `gh` CLI not in error handling tables | Both SKILLs | Add row for missing `gh` |
| **LOW** | No QM JSON schema validation | audit.py | Add minimal key checks |
