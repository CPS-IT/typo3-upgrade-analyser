# Sprint Change Proposal — 2026-04-03

## 1. Issue Summary

**Trigger:** Code review of Story 2-5 (VCS Resolution Integration) revealed that VCS detection only works for packages also published on Packagist. Non-Packagist VCS-only packages (8 of 30 extensions in the test project `zug12`) always return `vcs_available: null` — classified as "not available from any source" with risk score 9.0.

**Discovery context:** Systematic investigation traced the failure through three root causes forming a chain:

1. **RC-1:** `ComposerVersionResolver::resolve()` accepts a `$vcsUrl` parameter but never uses it. It runs `composer show --all` (queries Packagist only). Non-Packagist packages return NOT_FOUND.
2. **RC-2:** `ExtensionDiscoveryService::createExtensionFromComposerData()` never reads `source.url` from `composer.lock` package data. `Extension.repositoryUrl` is always `null` in production.
3. **RC-3:** `VcsSource` has no fallback strategy when the resolver returns NOT_FOUND. It returns null metrics and moves on.

**Evidence:** CLI tests confirmed the hypothesis. `composer show --working-dir=<project> <package>` succeeds for all 8 affected packages. The data exists in `composer.lock` but is never bridged to the Extension entity or used by the resolver.

**SSH dimension:** 4 of the 8 affected packages use SSH source URLs (`git@gitlab.321.works:...`) with no `dist` field. The `--working-dir` fallback depends on SSH authentication being available on the machine running the analyzer. This is the most prevalent use case for custom packages in private repositories and must be explicitly addressed.

**Impact:** VCS detection adds zero value beyond what Packagist already provides. Epic 2's core value proposition — "Complete Extension Source Coverage for All VCS Providers" — is not delivered.

## 2. Impact Analysis

### Epic Impact

| Epic | Impact | Details |
|------|--------|---------|
| Epic 2 | Direct | Story 2-5 cannot merge as-is. VCS detection for non-Packagist packages does not work. New story 2-5a required before merge. |
| Epic 2 Story 2-6 | None | Cleanup story unaffected. |
| Epic 2 Story 2-7 | None | Direct/indirect distinction unaffected. |
| Epics 3-6 | None | No dependency on VCS detection internals. |

### Artifact Conflicts

| Artifact | Impact | Details |
|----------|--------|---------|
| PRD | None | Fix aligns with existing requirements. |
| Architecture | Minor | Document the `--working-dir` fallback strategy for non-Packagist packages. |
| Epics doc | Minor | Add Story 2-5a definition. |
| Sprint status | Minor | Add Story 2-5a entry. |

### Deferred Items (pre-existing, don't affect VCS detection)

These items were surfaced during the review but do not affect the reliability of VCS detection. They should be planned for resolution within Epic 2 (Stories 2-6 or 2-7) or deferred to later epics as noted:

| ID | Issue | Suggested Placement |
|----|-------|-------------------|
| RC-4 | Main report JSON loses per-extension analysis data (`AnalysisResult` not `JsonSerializable`) | Pre-Epic-3 reporting hardening |
| F9 | Failure results never cached — repeated subprocess calls for same failing URL | Epic 2 Story 2-6 or dedicated story |
| F11 | `warnedUrls` singleton state not reset between runs | Epic 5 (batch mode) |
| F12 | `configuredSources` type not validated (string vs array) | Configuration validation story |
| F13 | Cache null vs miss indistinguishable on corrupt JSON | Pre-Epic-3 CacheService hardening |
| F14 | `shouldTryFallback()` dead code in `VcsResolutionResult` | Epic 2 Story 2-6 (cleanup) |
| F15 | Inconsistent null-safety for TER/Packagist vs VCS in `ReportContextBuilder` | Pre-Epic-3 reporting hardening |
| F16 | `$groupedResults['discovery']` accessed without key check | Pre-Epic-3 reporting hardening |

## 3. Recommended Approach

**Selected path:** Direct Adjustment — add Story 2-5a before merging Story 2-5.

### Rationale

- The fix is contained: 3 root causes, 3-4 files to modify, well-understood data flow.
- `source.url` data is already present in `composer.lock` for all affected packages.
- `--working-dir` fallback was explored in Spike 2-0 and confirmed working (11-13s overhead, acceptable for non-Packagist packages only).
- Story 2-5's existing work (rename, metric migration, templates) is preserved entirely.
- No rollback needed. No MVP scope reduction.

### Effort and Risk

- **Effort:** Medium (estimated 3-4 files changed, new/updated tests)
- **Risk:** Low (additive fix, existing Packagist-based resolution unaffected)
- **Timeline impact:** 1 story before Story 2-5 can merge

## 4. Detailed Change Proposals

### Story 2-5a: Fix VCS Detection for Non-Packagist Packages

**As a** developer analyzing a TYPO3 installation,
**I want** VCS detection to work for packages sourced from private/non-Packagist repositories,
**so that** extensions installed via VCS entries in `composer.json` are correctly identified as available.

#### AC-1: Bridge `source.url` to Extension entity (fixes RC-2)

**File:** `src/Infrastructure/Discovery/ExtensionDiscoveryService.php`
**Method:** `createExtensionFromComposerData()`

OLD (conceptual — no `source.url` reading):
```
// Creates Extension from installed.json data
// Reads dist.type, dist.url → ExtensionDistribution
// Never reads source.url
```

NEW:
```
// After creating Extension entity:
// Read $packageData['source']['url'] if present
// Call $extension->setRepositoryUrl($sourceUrl)
```

**Rationale:** `source.url` is present in `composer.lock` for all 8 affected packages. The Extension entity already has the `repositoryUrl` property and setter — it's just never called in production.

#### AC-2: Use `$vcsUrl` / `--working-dir` fallback in resolver (fixes RC-1)

**File:** `src/Infrastructure/ExternalTool/ComposerVersionResolver.php`
**Method:** `resolve()`

OLD:
```
// Runs: composer show --all --format=json $packageName
// $vcsUrl parameter is accepted but only passed through to result
// Non-Packagist packages always return NOT_FOUND
```

NEW:
```
// Primary: composer show --all --format=json $packageName (fast, Packagist)
// If NOT_FOUND and installation path available in context:
//   Fallback: composer show --working-dir=$installationPath --format=json $packageName
//   (slower, 11-13s, but resolves project-local VCS repositories)
```

**Rationale:** `--working-dir` was already explored in Spike 2-0 and confirmed working. The overhead only applies to the ~8 non-Packagist packages, not all extensions.

**Open design question:** How to pass `installationPath` to the resolver. Options:
- (a) Add it to `VcsResolverInterface::resolve()` signature
- (b) Add it to `AnalysisContext` and pass context to VcsSource which extracts it
- (c) Inject it as a constructor parameter on `ComposerVersionResolver`

Option (b) is likely cleanest — `AnalysisContext` already carries installation metadata.

#### AC-3: SSH authentication handling (graceful degradation)

The `--working-dir` fallback relies on Composer's own authentication chain, which includes SSH keys for VCS repositories. For SSH-based repos (`git@host:...`), this only works if the machine running the analyzer has SSH access configured for the target host.

**Behavior:**
- When `--working-dir` fallback fails due to authentication (SSH key missing, host unreachable), the resolver returns NOT_FOUND with a descriptive error
- `VcsSource` emits a WARNING: `VCS source "{url}" for package "{package}" could not be resolved. SSH authentication may not be configured for this host. See user guide for setup instructions.`
- The extension is reported as `vcs_available: null` (unknown) — not `false` (explicitly unavailable)
- The analyzer continues with remaining extensions (no hard failure)

**Early SSH connectivity check (optional optimization):**
- Before running `--working-dir` for the first SSH-based package on a given host, attempt `ssh -T -o ConnectTimeout=5 <host>` to probe connectivity
- Cache the result per host for the duration of the analysis run
- If the host is unreachable, skip `--working-dir` for all packages on that host (avoids repeated 11-13s timeouts)
- Emit a single WARNING per unreachable host instead of per-package warnings

**Documentation requirement:**
- User guide must document SSH authentication prerequisites for private VCS detection
- Include setup instructions for: local development (SSH agent), CI environments (deploy keys / SSH key injection), and common troubleshooting (host key verification, key permissions)

#### AC-4: RC-3 resolved implicitly

Once RC-1, RC-2, and AC-3 are in place, the NOT_FOUND fallback becomes the `--working-dir` retry with graceful SSH degradation. No additional VcsSource changes needed. If the `--working-dir` fallback also returns NOT_FOUND (or SSH auth fails), the `null` metric behavior is correct — the package cannot be resolved in this environment.

#### AC-5: Test coverage

- Unit test: `ComposerVersionResolver` with `--working-dir` fallback path
- Unit test: `ExtensionDiscoveryService` populates `repositoryUrl` from `source.url`
- Integration test: End-to-end VCS detection for a non-Packagist package fixture
- All existing tests continue to pass (no regression)
- PHPStan Level 8: 0 errors; `composer lint:php`: 0 issues

#### AC-6: Performance guard

- `--working-dir` fallback is only attempted when primary resolution returns NOT_FOUND
- Total overhead per non-Packagist package: ~11-13s (documented in Spike 2-0)
- For the test project (8 non-Packagist packages): ~90-100s additional analysis time
- Acceptable for correctness; optimization can be explored later (batch resolution, parallel execution)

### Deferred items placement

| ID | Placement | Notes |
|----|-----------|-------|
| F14 | Story 2-6 | Remove `shouldTryFallback()` dead code alongside GenericGitResolver deletion |
| F9 | Story 2-6 or new 2-6a | Add short-circuit for repeated same-URL failures within single run |
| RC-4 | Pre-Epic-3 | Add `JsonSerializable` to `AnalysisResult` |
| F15, F16 | Pre-Epic-3 | Reporting null-safety hardening |
| F11 | Epic 5 | Singleton state reset for batch mode |
| F12 | Epic 5 | Configuration type validation |
| F13 | Pre-Epic-3 | CacheService corrupt JSON handling |

## 5. Implementation Handoff

### Change Scope: Minor

Direct implementation by development team. No backlog reorganization, no architectural replan.

### Handoff

| Role | Responsibility |
|------|---------------|
| Dev (bmad-dev) | Implement Story 2-5a (3 ACs + tests) |
| SM (bmad-sm) | Update epics.md with Story 2-5a definition; update sprint-status.yaml |
| Dev (bmad-dev) | After 2-5a done: merge 2-5a into feature branch, verify 2-5 still passes, proceed with 2-5 merge to develop |

### Success Criteria

1. Running analyzer against `zug12` project with SSH access configured: all 8 previously-failing extensions show `vcs_available: true` or `vcs_available: false` (not `null`)
2. Running analyzer without SSH access for a host: affected extensions show `vcs_available: null` with a clear warning naming the host and pointing to documentation
3. HTTPS-based VCS packages (GitHub with dist URLs) resolve without SSH dependency
4. All existing tests pass (no regression)
5. PHPStan Level 8: 0 errors; `composer lint:php`: 0 issues

### Sequence

```
Current state:  2-5 (review, VCS detection broken for non-Packagist)
                 ↓
Story 2-5a:     Fix RC-1 + RC-2 (on same feature branch)
                 ↓
Re-verify 2-5:  Run full test suite + manual verification against zug12
                 ↓
Merge 2-5:      PR to develop
                 ↓
Story 2-6:      Legacy Git Provider Cleanup (includes F14, optionally F9)
```
