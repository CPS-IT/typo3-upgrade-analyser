# Sprint Change Proposal — 2026-06-16

**Proposed by:** Dirk Wenzel
**Date:** 2026-06-16
**Scope:** Minor — three focused bugfix stories added to pre-epic-3
**Status:** Approved

---

## Section 1: Issue Summary

Three bugs in the Rector analysis pipeline were discovered during post-implementation testing against a real TYPO3 12.4.46 installation (`~/projekt/dena/ventures/gefo/tests/upgrade-analysis-v13`). All three cause silent 0-findings output on codebases that, when analyzed manually with the correct Rector configuration, show substantial upgrade work needed. Combined, they directly undermine the tool's primary value proposition for upgrade planning.

- **Issue #290:** Wrong Rector rule set selection for version upgrades
- **Issue #291:** Hardcoded skip pattern suppresses all TCA migration findings
- **Issue #292:** `public/`-only path detection causes 0 files processed on non-standard web root installations

---

## Section 2: Impact Analysis

### Epic Impact
- **pre-epic-3** (`review`): Existing hardening story (`pre-epic-3-reporting-hardening`) unaffected in scope and status. Three new bugfix stories are appended.
- **Epic 3**: Benefits from these fixes being in place first — incorrect Rector output (0 findings) would propagate silently into streaming reports if unfixed.
- All other epics: unaffected.

### Story Impact
No existing stories modified. Three new stories added to pre-epic-3.

### Artifact Conflicts
- **PRD FR17** ("System can run Rector analysis against public and proprietary extensions to detect breaking changes and deprecations"): violated by all three bugs. No PRD text change needed — the requirement already mandates correct behavior; the bugs are implementation defects.
- **Architecture, UX**: no changes required.

### Technical Impact
All three fixes are confined to `Infrastructure/Analyzer/Rector/`. No domain layer changes, no new dependencies, no migration concerns.

---

## Section 3: Recommended Approach

**Option 1 — Direct Adjustment** (selected): Add three bugfix stories to pre-epic-3. Each fix targets 1–3 files with a known root cause and a clear, low-risk implementation path. Stories are independent and can be developed in any order.

Effort: Low per story.
Risk: Low — no architecture changes, no new abstractions.
Timeline impact: Minor addition to pre-epic-3.

---

## Section 4: Detailed Change Proposals

### New Story: `pre-epic-3-fix-rector-rule-sets` (Issue #290)

**Root cause:** `RectorRuleRegistry::getSetsForVersionUpgrade()` uses `setVersion > fromVersion && setVersion <= toVersion` to select Rector rule sets. For a v12→v13 upgrade this selects only `Typo3SetList::TYPO3_13` — rules introduced specifically for v13 — missing all accumulated deprecations from v10, v11, and v12 that also need clearing before v13 compatibility is achieved.

**Fix:** Replace the range-filter logic with level-set selection based on the target major version: use `Typo3LevelSetList::UP_TO_TYPO3_{targetMajorVersion}`. For a v12→v13 upgrade this produces `UP_TO_TYPO3_13`, which accumulates `TYPO3_10 + TYPO3_11 + TYPO3_12 + TYPO3_13` — all deprecations that must be cleared for v13 compatibility.

**Files affected:** `src/Infrastructure/Analyzer/Rector/RectorRuleRegistry.php`, related tests.

---

### New Story: `pre-epic-3-fix-rector-tca-skip` (Issue #291)

**Root cause:** `RectorConfigGenerator::getSkipPatterns()` unconditionally includes `'*/Configuration/TCA/Overrides/*'` in every generated Rector config. `Configuration/TCA/Overrides/` files are first-class PHP code containing deprecated TCA options (`renderType => inputDateTime`, `renderType => inputLink`, indexed item arrays). Skipping them suppresses the findings from `MigrateInputDateTimeRector`, `MigrateRenderTypeInputLinkToTypeLinkRector`, `MigrateItemsIndexedKeysToAssociativeRector`, and `MigrateRequiredFlagRector`.

**Fix:** Remove the hardcoded skip pattern. If opt-in skipping is desirable for future use cases, expose it via `typo3-analyzer.yaml` configuration — never force it.

**Files affected:** `src/Infrastructure/Analyzer/Rector/RectorConfigGenerator.php`, related tests.

---

### New Story: `pre-epic-3-fix-rector-path-resolution` (Issue #292)

**Root cause:** `Typo3RectorAnalyzer::determineInstallationType()` checks for presence of a `public/` directory to distinguish standard from custom composer layouts. Installations using `app/web/`, `web/`, `htdocs/`, or any non-`public/` web root fall into the custom branch, which then constructs extension paths under `{installationPath}/public/typo3conf/ext/{key}` — a path that does not exist. Rector exits with code 0 and 0 files processed; no error or warning is emitted.

**Fix options (to be decided during story creation):**
- **Preferred:** Use the web root already resolved by `InstallationDiscoveryService`/`VersionProfileRegistry` rather than re-detecting from the filesystem. The installation discovery pipeline already handles web root detection correctly.
- **Minimum viable:** Validate the resolved extension path exists before invoking Rector; emit a `WARNING` per extension when the path is absent rather than silently producing 0 findings.

**Files affected:** `src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php`, `src/Infrastructure/Analyzer/Rector/PathResolutionService.php` (or equivalent), related tests.

---

### sprint-status.yaml update

```yaml
OLD:
  pre-epic-3-reporting-hardening: review

NEW:
  pre-epic-3-reporting-hardening: review
  pre-epic-3-fix-rector-rule-sets: backlog      # Bug #290: wrong set selection in getSetsForVersionUpgrade
  pre-epic-3-fix-rector-tca-skip: backlog       # Bug #291: hardcoded TCA/Overrides skip in RectorConfigGenerator
  pre-epic-3-fix-rector-path-resolution: backlog # Bug #292: public/-only path detection in Typo3RectorAnalyzer
```

---

## Section 5: Implementation Handoff

**Scope classification:** Minor — direct implementation by development team.

**Sequence:** Independent; any order viable. #290 and #291 are pure logic changes (simpler). #292 requires understanding how `PathResolutionService` or `InstallationDiscoveryService` exposes the resolved web root to the Rector analyzer.

**Success criteria per story:**
- #290: Analyzer run on a TYPO3 12.4 installation produces Rector findings consistent with a manual `UP_TO_TYPO3_13` run. Confirmed via test against real installation fixture or integration test.
- #291: Rector config generated by the tool does not contain `TCA/Overrides` in skip patterns. TCA migration rules report findings for `Configuration/TCA/Overrides/` files.
- #292: Analyzer run on a composer installation with non-`public/` web root either resolves the correct extension paths OR emits an explicit warning per extension when the resolved path does not exist.

**Next step:** Run `bmad-create-story` for each of the three new stories.
