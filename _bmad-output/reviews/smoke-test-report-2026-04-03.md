# Smoke Test Report — Story 2-5 / 2-5a
**Date**: 2026-04-03
**Branch**: `feature/2-5-integrate-resolvers-and-update-data-model`
**Tested binary**: `./bin/typo3-analyzer analyze`

---

## 1. Config Validation

Four config files were validated before analysis. Findings below are grouped by severity.

### `tmp/typo3-analyzer-ihkof11.yaml` — ihkof bundle, TYPO3 11 → 12.4

| Finding | Severity |
|---------|----------|
| `installationPath` is a relative path (`../ihkof-bundle/`). Resolved relative to cwd, fragile if the tool is invoked from a different directory. | medium |
| `output_directory: var/reports/` (root) — no sub-directory. Report files will land in the shared root and mix with reports from other analyses. | low |
| Source name `github` is used. Pre-1.0 this alias can simply be dropped from the codebase — no migration path needed. | low |
| Only `html` output format configured — no `json` or `markdown`. JSON is required for machine-readable post-processing. | info |

### `tmp/typo3-analyzer-verkehrswende-plattform.yaml` — TYPO3 11 → 13.4

| Finding | Severity |
|---------|----------|
| Source name `github` — same as above, drop the alias. | low |
| `reporting.formats` contains `md`. `markdown` is the canonical key; `md` should be silently aliased (like `github`). | info |

### `tmp/typo3-analyzer-zug12.yaml` — TYPO3 12 → 13.4

No issues found. Output directory `var/reports/zug12-2026-04-03/` is explicit.

### `tmp/typo3-analyzer-zug13.yaml` — TYPO3 13 → 14.4

| Finding | Severity |
|---------|----------|
| `resultCache.enabled: false` — intentional for this smoke run, but disabling the cache means every run will hit all external APIs unconditionally. Not a config error, but worth noting in production configs. | info |

---

## 2. Run Setup Note

All four analyses were started sequentially (one after another), not in parallel. Running them in parallel would cause cache conflicts because all analyses share the same `var/cache/` directory, leading to cross-contamination of cached VCS results between installations.

---

## 3. Per-Installation Results

### 3.1 zug12 — TYPO3 12.4.34 → 13.4 (31 extensions)

**All 31 extensions analyzed. Exit 0.**

**Risk distribution**

| Level | Count |
|-------|-------|
| low | 1 (`zug_sitepackage` — no composer name, sitepackage) |
| medium | 30 |
| high | 0 |
| critical | 0 |

**Availability breakdown**

| Source | Available |
|--------|-----------|
| TER | 0 |
| Packagist | 22 |
| VCS (resolved) | 30 |
| No availability | 1 (`zug_sitepackage`) |

**`--working-dir` fallback successes** (VCS available but not on Packagist):

| Extension | Composer name | Resolved version |
|-----------|--------------|-----------------|
| `file_metadata_overlay_aspect` | `fr/file-metadata-overlay-aspect` | `dev-feature/v13` |
| `iki_event_approval` | `fr/iki-event-approval` | `4.0.1` |
| `zug_project` | `fr/zug-project` | `4.0.5` |
| `record_content` | `cpsit/record-content` | `3.0.2` |
| `cpsit_proposal` | `cpsit/cpsit-proposal` | `2.0.0` |
| `event_submission` | `cpsit/event-submission-zug` | `4.0.1` |
| `formkit` | `cpsit/formkit` | `1.0.2` |
| `zug_caretaker` | `cpsit/zug-caretaker` | `3.0.0` |

The `--working-dir` fallback correctly resolved all eight private/custom packages by reading the local `composer.lock`. This is the primary improvement from Story 2-5a for this installation.

**Linear scan cap hit** (50-version limit reached, scan aborted early):
- `ichhabrecht/filefill`
- `in2code/powermail`

These packages have many historical versions. The cap prevents runaway API calls at the cost of potentially missing older compatible versions. No incorrect result was produced — both packages were ultimately resolved as available via Packagist.

---

### 3.2 zug13 — TYPO3 13.4.27 → 14.4 (30 extensions)

**All 30 extensions analyzed. Exit 0.**
(Cache disabled for this run.)

**Risk distribution**

| Level | Count |
|-------|-------|
| low | 1 (`zug_sitepackage`) |
| medium | 21 |
| high | 0 |
| critical | 8 |

**Availability breakdown**

| Source | Available |
|--------|-----------|
| TER | 0 |
| Packagist | 21 |
| Git (resolved) | 11 |
| Git unavailable | 9 |
| Git unknown | 5 |
| No availability | 9 |

**Critical extensions (no availability for TYPO3 14.4)**:

Two distinct causes:

*SSH pre-check false negatives* — packages that ARE installed locally but returned `vcs_available: unknown` due to the SSH pre-check firing (see §4.1):

| Extension | Composer name | Note |
|-----------|--------------|------|
| `record_content` | `cpsit/record-content` | resolved via `--working-dir` in zug12 |
| `iki_event_approval` | `fr/iki-event-approval` | resolved via `--working-dir` in zug12 |
| `zug_caretaker` | `cpsit/zug-caretaker` | resolved via `--working-dir` in zug12 |
| `zug_project` | `fr/zug-project` | resolved via `--working-dir` in zug12 |

*No TYPO3 14 release yet* — packages on GitHub with no compatible version for the target:

| Extension | Composer name | VCS status |
|-----------|--------------|------------|
| `cpsit_proposal` | `cpsit/cpsit-proposal` | unavailable |
| `event_submission` | `cpsit/event-submission-zug` | unavailable |
| `formkit` | `cpsit/formkit` | unavailable |
| `vimp` | `cpsit/vimp` | unavailable |

*No discoverable source*:

| Extension | Composer name | Note |
|-----------|--------------|------|
| `zug_sitepackage` | `fr/zug-sitepackage` | no repository URL in lock |

**Linear scan cap hit**:
- `nng/nnhelpers`
- `in2code/powermail`
- `yoast-seo-for-typo3/yoast_seo`

---

### 3.3 verkehrswende-plattform — TYPO3 11.5.49 → 13.4 (20 extensions)

**All 20 extensions analyzed. Exit 0.**
(`github` source alias accepted; `md` treated as `markdown` format.)

**Risk distribution**

| Level | Count |
|-------|-------|
| medium | 6 |
| critical | 14 |

**Availability breakdown**

| Source | Available |
|--------|-----------|
| TER | 0 |
| Packagist | 6 |
| VCS (resolved) | 6 |
| No availability | 14 |

The 14 critical extensions are all project-specific custom extensions (`autoquartett`, `co2calculator`, `energiekostentool`, `gridelements`, `haltungskostencheck`, `klassenbesten`, and nine more). None appear on Packagist or in any discoverable VCS. They have no repository URL in the lock file. VCS status: `unknown`. These require manual assessment.

Note: `gridelements` is a known community extension available on Packagist (`gridelements/gridelements`). The critical rating here is unexpected and suggests the composer name is not being detected correctly for this package — either the lock file uses a path installation or the key mapping is missing.

---

### 3.4 ihkof11 — TYPO3 11.5.50 → 12.4 (35 extensions)

**All 35 extensions analyzed. Exit 0.**
(HTML-only output; no JSON report available for detailed analysis.)

Relative path `../ihkof-bundle/` was resolved correctly from the tool's working directory at invocation time. Reports written to `var/reports/` root (no subdirectory).

No further per-extension data is available without a JSON report.

---

## 4. New Feature Observations

### 4.1 SSH Pre-Check Behavior

The SSH pre-check fires when a package's VCS source URL uses the `git+ssh://` or `git@...` scheme. It runs `ssh -T -o ConnectTimeout=5 <host>` and classifies exit 255 as `SSH_UNREACHABLE`, any other code as reachable.

**Observed in zug13**: four packages (`record_content`, `iki_event_approval`, `zug_caretaker`, `zug_project`) show `vcs_available: unknown`. The SSH pre-check returned `SSH_UNREACHABLE` for `gitlab.321.works` and the resolver returned immediately without attempting `--working-dir`.

**Implausibility**: The same gitlab instance and the same local environment (including ssh agent) was used for both runs. The SSH pre-check producing `SSH_UNREACHABLE` for zug13 but not zug12 is not plausible from a connectivity standpoint. The most likely explanation is that the zug12 results came from cache (cache was enabled for that run) and the SSH pre-check never ran for those packages in zug12 — it only ran fresh in zug13 where cache was disabled.

This shifts the focus: the SSH pre-check may be producing a false `SSH_UNREACHABLE` result even when the host is reachable. Possible causes:
- The subprocess spawned by PHP does not inherit `SSH_AUTH_SOCK`, causing the ssh command to behave differently than expected (though authentication failure should yield exit 1, not 255).
- Host name extraction from the lock file's source URL produces a malformed host string that ssh rejects immediately with exit 255.
- The ConnectTimeout=5 is too short for the target host's response time on this network path.

**Correctness concern** (independent of the false-negative question): `--working-dir` reads local lock/vendor data only — no outbound connection is required. The SSH pre-check should not gate the `--working-dir` code path regardless of SSH reachability. Locally-installed packages should always be resolvable via `--working-dir`.

**Recommended fix** (deferred): attempt `--working-dir` first for all packages. Apply the SSH pre-check only when `--working-dir` yields no result and an outbound VCS fetch is required.

### 4.2 Source-Installed Packages Not Flagged

Composer distinguishes two installation modes: `dist` (archive/zip, the default) and `source` (VCS clone). Packages installed from SSH-hosted private repositories are typically installed as `source`. The extension discovery currently does not expose this distinction — all packages appear with `distribution.type: zip` or similar regardless of how they were actually installed.

This matters because a source-installed package is always a local VCS clone. `composer show --working-dir` will always succeed for it, and no outbound SSH connection is needed. Marking these packages correctly would allow the resolver to skip the SSH pre-check entirely and go straight to `--working-dir`.

The majority of the private packages in zug12 and zug13 are source-installed but are not flagged as such in the extension data.

### 4.3 `--working-dir` Fallback

Worked correctly in zug12 for eight private packages. The fallback reads `composer show --working-dir <path> <package>` against the local lock/vendor data and returns the installed version as the resolved VCS version. All eight resolved versions are plausible.

`vcs_source_url` is `null` for all `--working-dir`-resolved packages because no outbound VCS URL lookup is performed. This is expected.

### 4.4 `--working-dir` Linear Scan Performance (critical)

The ihkof11 run exposed a severe performance problem. The VCS fallback for packages on `gitlab.321.works` took between 40 seconds and 20 minutes per package:

| Package | Duration | Result |
|---------|----------|--------|
| `cpsit/cps-ihkoffbranchesgrid` | 84 s | NO_MATCH |
| `cpsit/cps-ihkoffsliders` | 164 s | NO_MATCH |
| `cpsit/ihkof-azlist` | 40 s | NO_MATCH |
| `cpsit/ihkof-contacts` | 565 s | NO_MATCH |
| `cpsit/ihkof-contentelements` | 817 s | NO_MATCH (50-version cap) |
| `cpsit/ihkof-events` | 771 s | NO_MATCH (50-version cap) |
| `cpsit/ihkof-reservation` | 1222 s | FAILURE (timeout) |
| `cpsit/ihkof-sitepackage` | 30 s | FAILURE (timeout) |
| `cpsit/solrfal` | 1 s | RESOLVED |

**Total VCS fallback time**: ~3694 seconds (~61 minutes) for 9 packages.

Root cause: the linear scan issues one `composer show --working-dir=<path> <package> <version>` subprocess call per version, starting from the newest and scanning toward the oldest. Despite the `--working-dir` flag, each per-version call is individually slow (appears to involve network activity per call). A single `composer show` call on the CLI takes only seconds; repeated sequentially over 50 versions this compounds into many minutes per package.

**The scan has no lower bound**: it scans all versions down to the oldest release (or the 50-version cap). Scanning versions older than the currently installed version is pointless — if the installed version is not compatible with the target, older releases will not be either.

**Fix direction** (deferred): bound the linear scan to versions ≥ the currently installed version. The installed version is available from the initial `composer show --working-dir --all` response or from the lock file. This would reduce the scan to typically 1–5 versions for packages that are actively maintained and reduce fallback time to seconds.

### 4.5 Linear Scan Cap

The 50-version cap on `linearScan` was hit for several packages. For the ihkof11 packages this cap only partially mitigated the performance problem — 50 slow subprocess calls still took 12–14 minutes. Once the lower-bound fix (§4.4) is applied, the cap becomes less critical for maintained packages but remains a safety net for pathological cases.

---

## 5. Summary of Findings

| Category | Finding |
|----------|---------|
| Config | `github` source name: drop the alias (pre-1.0, no migration needed) |
| Config | `md` format key: silently alias to `markdown` |
| Config | Relative `installationPath` is cwd-dependent |
| Config | Shared `var/reports/` root (ihkof11) mixes report files |
| Feature | `--working-dir` fallback resolves private packages correctly (zug12: 8 packages) |
| Bug (deferred) | SSH pre-check may produce false `SSH_UNREACHABLE`; blocks `--working-dir` for locally-installed packages unnecessarily |
| Gap | Source-installed packages not identified as such — prevents optimised resolution path |
| Bug (deferred) | Linear scan has no lower version bound — scans all historical versions; ihkof11 took 61 min for 9 packages |
| Observation | Linear scan cap (50) hit for 5 packages; no incorrect results observed |
| Observation | `gridelements` rated critical in verkehrswende — possible composer name mapping gap |
| Observation | Parallel analysis runs will contaminate shared `var/cache/` |
