# Sprint Change Proposal — 2026-06-17

**Project:** typo3-upgrade-analyser
**Date:** 2026-06-17
**Prepared by:** Scrum Master (automated via correct-course workflow)
**Change scope:** Minor — direct implementation by development team

---

## Section 1: Issue Summary

**Problem statement:** Two high-severity bugs were found in the Fractor analyzer suite that are direct code-level duplicates of bugs already fixed in the Rector suite. Both were uncovered by the systematic defect investigation documented in `_bmad-output/planning-artifacts/research/technical-fractor-defect-investigation-2026-06-17.md`.

**Context:** Pre-epic-3 already contains three Rector-specific bugfix stories (`pre-epic-3-fix-rector-rule-sets: done`, `pre-epic-3-fix-rector-tca-skip: done`, `pre-epic-3-fix-rector-path-resolution: ready-for-dev`). The investigation confirmed that two of those three defect patterns are reproduced verbatim in the Fractor codebase; the third (TCA/Overrides skip) was never introduced into Fractor and does not need a fix.

**Evidence:**

| Defect | File | Severity | Status |
|--------|------|----------|--------|
| Range-filter vs. cumulative sets | `FractorRuleRegistry.php:107–119` | High — incomplete analysis results | CONFIRMED |
| `public/`-only fallback path | `FractorAnalyzer.php:327` | High — wrong paths on non-standard web roots | CONFIRMED |
| Missing `is_dir()` guard | `FractorAnalyzer.php` — `doAnalyze()` | High — silent 0-findings or crash on bad path | CONFIRMED |
| Hardcoded TCA/Overrides skip | `FractorConfigGenerator.php` | Low | NOT REPRODUCED |

A fourth issue (dead `use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList` in generated config files) is low-severity but trivial to remove alongside the rule-set fix.

---

## Section 2: Impact Analysis

**Epic Impact:**
- Pre-epic-3 is the only affected grouping. Two new stories are added; existing stories are unchanged.
- No other epics are impacted.

**Story Impact:**
- Two new stories are introduced: `pre-epic-3-fix-fractor-rule-sets` and `pre-epic-3-fix-fractor-path-resolution`.
- `pre-epic-3-fix-rector-path-resolution` (ready-for-dev) is the natural predecessor for the Fractor path-resolution story; both can proceed in parallel if capacity allows.

**Artifact Conflicts:**
- **PRD:** No conflict. FR18 already covers Fractor analysis correctness. These are defect fixes, not scope additions.
- **Architecture:** No changes required.
- **UX:** N/A — CLI tool with no graphical interface.
- **Other:** CI and test pipelines require no structural changes; test additions are self-contained within the new story files.

**Technical Impact:**
- `FractorRuleRegistry`: swap range-filter for a `Typo3LevelSetList` lookup; update one stale test assertion.
- `FractorAnalyzer`: two-line change to `getFallbackExtensionPath()` + one `is_dir` guard in `doAnalyze()`; add one test case.
- `FractorConfigGenerator`: remove one dead `use` import from the generated config template. No logic change.

---

## Section 3: Recommended Approach

**Selected path:** Option 1 — Direct Adjustment.

**Rationale:**
- Both defects reproduce pre-fix Rector code exactly, so the fix pattern is already proven in the codebase.
- The changes are isolated to three classes, none of which touch domain logic or shared infrastructure.
- Effort is low (each story mirrors a completed Rector story).
- Risk is low; tests already exist and need only one or two assertion updates.
- No rollback, no MVP scope reduction, and no replan are warranted.

**Effort estimate:** Low (each story ≤ half a day).
**Risk level:** Low.
**Timeline impact:** Minimal — both stories fit within the current pre-epic-3 window before Epic 3 starts.

---

## Section 4: Detailed Change Proposals

### Story: pre-epic-3-fix-fractor-rule-sets

**NEW STORY** — no prior version exists.

```
Story: pre-epic-3-fix-fractor-rule-sets
Title: Fix FractorRuleRegistry cumulative set selection

As a developer using the Fractor analyzer,
I want rule set selection to use cumulative Typo3LevelSetList constants,
So that a v12→v13 analysis includes all accumulated deprecations from prior versions,
not just v13-specific rules.

Acceptance Criteria:

- Given FractorRuleRegistry::getSetsForVersionUpgrade() currently uses a range-filter that
  selects only version-specific sets for the target range
- When the fix is applied
- Then getSetsForVersionUpgrade(12, 13) returns Typo3LevelSetList::UP_TO_TYPO3_13 instead of
  [Typo3SetList::TYPO3_13]
- And a TYPO3_LEVEL_SETS constant maps major version int → Typo3LevelSetList constant,
  mirroring the pattern in RectorRuleRegistry
- And add `use a9f\Typo3Fractor\Set\Typo3LevelSetList` to FractorRuleRegistry
- And FractorRuleRegistryTest::testGetSetsForVersionUpgradeFrom12To13() is updated to assert
  Typo3LevelSetList::UP_TO_TYPO3_13 rather than Typo3SetList::TYPO3_13
- And FractorConfigGenerator::generateConfigFileContent() no longer emits
  `use a9f\\Typo3Fractor\\Set\\Typo3LevelSetList` in generated files (dead import removed)
- And FractorConfigGeneratorTest is updated to not assert the dead import line if currently asserted
- And all tests pass and PHPStan Level 8 reports zero errors

Rationale: Identical defect pattern to pre-epic-3-fix-rector-rule-sets (commit fc791b1).
           Typo3LevelSetList is already available in vendor; fix pattern is proven.
```

---

### Story: pre-epic-3-fix-fractor-path-resolution

**NEW STORY** — no prior version exists.

```
Story: pre-epic-3-fix-fractor-path-resolution
Title: Fix FractorAnalyzer web-dir-aware fallback path and add is_dir guard

As a developer analyzing a TYPO3 installation with a non-standard web root,
I want FractorAnalyzer to correctly compute the fallback extension path using web-dir,
So that installations using web/, htdocs/, or any custom web root receive correct analysis
instead of a wrong path or silent zero-findings result.

Acceptance Criteria:

- Given FractorAnalyzer::getFallbackExtensionPath() currently hardcodes 'public/typo3conf'
  as the fallback typo3conf directory
- When the fix is applied
- Then getFallbackExtensionPath() derives the typo3conf directory as:
    $webDir = (string) ($customPaths['web-dir'] ?? 'public');
    $typo3confDir = $customPaths['typo3conf-dir'] ?? ($webDir . '/typo3conf');
  mirroring the fix in Typo3RectorAnalyzer (branch bugfix/pre-epic-3-fix-rector-path-resolution)
- And FractorAnalyzer::doAnalyze() includes an early-return guard after path resolution:
    if (!empty($installationPath) && !is_dir($extensionPath)) {
        $this->logger->warning('Extension path does not exist — skipping Fractor analysis', [...]);
        return $result;
    }
- And a new unit test covers the non-public web-dir fallback scenario, e.g.:
    customPaths = ['web-dir' => 'htdocs'] → typo3confDir = 'htdocs/typo3conf'
    customPaths = [] → typo3confDir = 'public/typo3conf' (default unchanged)
- And a new unit test covers the is_dir guard: when the resolved extension path does not exist,
  analysis is skipped and the logger receives a warning
- And all existing tests pass and PHPStan Level 8 reports zero errors

Rationale: Identical defect pattern to pre-epic-3-fix-rector-path-resolution (Bug #292).
           Two confirmed bugs: wrong fallback path + missing existence guard.
```

---

## Section 5: Implementation Handoff

**Scope classification:** Minor — development team can implement directly.

**Handoff recipient:** Developer (dev agent or manual implementation).

**Responsibilities:**
- Implement `pre-epic-3-fix-fractor-rule-sets` first (no dependencies).
- Implement `pre-epic-3-fix-fractor-path-resolution` in parallel or immediately after (no cross-dependency between the two new stories; both are independent of `pre-epic-3-fix-rector-path-resolution`).

**Success criteria:**
- Both stories reach `done` status before Epic 3 begins.
- All tests pass (unit + integration) after each story.
- PHPStan Level 8 zero errors after each story.
- `pre-epic-3` composite status transitions to `done` when all its stories are `done`.

**sprint-status.yaml updates required (approved below):**
```yaml
# Add under pre-epic-3 section:
pre-epic-3-fix-fractor-rule-sets: backlog      # Bug: range-filter in FractorRuleRegistry (analogue of pre-epic-3-fix-rector-rule-sets)
pre-epic-3-fix-fractor-path-resolution: backlog # Bug: public/-only fallback + missing is_dir guard in FractorAnalyzer
```
