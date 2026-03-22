---
stepsCompleted:
  - 'step-01-validate-prerequisites'
  - 'step-02-design-epics'
  - 'step-03-create-stories'
  - 'step-04-final-validation'
inputDocuments:
  - '_bmad-output/planning-artifacts/prd.md'
  - '_bmad-output/planning-artifacts/architecture.md'
  - 'documentation/implementation/development/MVP.md'
  - 'documentation/implementation/development/feature/GitRepositoryVersionSupport.md'
  - 'documentation/implementation/development/feature/InstallationDiscoverySystem.md'
  - 'documentation/implementation/development/feature/ConfigurationParsingFramework.md'
  - 'documentation/implementation/development/feature/RectorFindingsTracking.md'
  - 'documentation/implementation/development/feature/RefactorReportingService.md'
  - 'documentation/implementation/development/feature/Typo3RectorAnalyser.md'
  - 'documentation/implementation/development/feature/phpstanAnalyser.md'
  - 'documentation/implementation/development/feature/PathResolutionService.md'
  - 'documentation/implementation/development/feature/ClearCacheCommand.md'
  - 'documentation/implementation/feature/planned/StreamingAnalyzerOutput.md'
  - 'documentation/implementation/feature/planned/StreamingTemplateRendering.md'
---

# typo3-upgrade-analyser - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for typo3-upgrade-analyser, decomposing the requirements from the PRD, Architecture document, and feature specifications into implementable stories.

## Requirements Inventory

### Functional Requirements

FR1: Developer can analyze a TYPO3 installation by providing its filesystem path
FR2: System can auto-discover TYPO3 installation type (Composer-based, legacy) without user configuration
FR3: System can detect the current TYPO3 version from the installation using multiple strategies
FR4: System can discover and catalog all extensions in an installation, classifying them as core, public, or proprietary
FR5: System can determine the default target version based on the current TYPO3 release cycle
FR6: Developer can override the target version via CLI flag or configuration
FR7: System can handle TYPO3 versions 11 through 14, including version-specific discovery mechanisms
FR8: System can identify core extensions and exclude them from availability checks and code analysis
FR9: System can check extension availability on TER for the target TYPO3 version
FR10: System can check extension availability on Packagist for the target version
FR11: System can check extension availability on GitHub repositories (tags, branches, version constraints)
FR12: System can check extension availability on GitLab repositories, including private instances with authentication
FR13: System can check extension availability on Bitbucket repositories (tags, branches, version constraints)
FR14: System can aggregate availability data across all sources into a unified availability status per extension
FR15: System can detect abandoned or unmaintained extensions based on repository and registry metadata
FR16: System can apply analysis strategy per extension type: core extensions are excluded from all checks; public and proprietary extensions receive code analysis; availability checks query sources appropriate to the extension's distribution channels
FR17: System can run Rector analysis against public and proprietary extensions to detect breaking changes and deprecations
FR18: System can run Fractor analysis against public and proprietary extensions to detect TypoScript migration needs
FR19: System can measure code complexity metrics for public and proprietary extensions
FR20: System can classify Rector findings by severity and change type (breaking, deprecation, migration)
FR21: System can execute external analysis tools as isolated processes with timeout handling
FR22: System can calculate a risk score (0-100) per extension based on multi-source analysis data
FR23: System can categorize extensions into risk levels (low, medium, high, critical)
FR24: System can generate per-extension recommendations based on analysis findings
FR25: System can provide an aggregate risk overview across all extensions in an installation
FR26: System can output structured risk and analysis metadata suitable for consumption by downstream automation (CI jobs, issue generation tools)
FR27: Developer can generate reports in HTML format with per-extension detail pages
FR28: Developer can generate reports in Markdown format
FR29: Developer can generate reports in JSON format for machine consumption
FR30: Developer can re-generate reports from cached analysis data without re-running analysis
FR31: Reports can include installation overview, risk distribution, version availability matrix, code analysis summaries, and recommendations
FR32: Developer can generate a customer-friendly report variant with reduced technical detail
FR33: Developer can customize report templates with agency branding (logo, colors, contact information)
FR34: Developer can include project metadata (project name, customer name, date, author) in reports
FR35: Customer-friendly report can present risk overview and key findings in non-technical language
FR36: System can run without any configuration file using sensible defaults
FR37: Developer can generate a configuration file via an init command
FR38: Developer can configure analyzer selection, output formats, and target version via YAML configuration
FR39: Developer can override configuration settings via CLI flags
FR40: Developer can override configuration settings via environment variables for CI/CD use
FR41: System can cache analysis results to avoid redundant API calls and tool executions
FR42: Developer can clear the analysis cache
FR43: System can stream analyzer output to prevent memory exhaustion on large installations
FR44: System can report analysis progress during execution
FR45: Developer can list all available analyzers with their status and requirements
FR46: Developer can list all discovered extensions for an installation
FR47: System can detect whether required external tools (Rector, Fractor, Git) are available and report missing dependencies

### NonFunctional Requirements

NFR1: Full analysis of an installation with 40 extensions completes in under 5 minutes (excluding network latency for API calls on slow connections)
NFR2: Individual API calls time out gracefully — a single unresponsive source must not block the entire analysis
NFR3: Memory usage stays within reasonable bounds for installations of any size (streaming output addresses this for MVP)
NFR4: Analysis progress is visible to the user during execution — no silent waiting periods longer than 10 seconds
NFR5: If an external API (TER, Packagist, GitHub, GitLab) is unavailable, the analysis completes with partial results and clear indication of what data is missing
NFR6: If an external tool (Rector, Fractor) crashes or times out for one extension, the analysis continues for remaining extensions
NFR7: Cached results are invalidated correctly — stale cache must never produce silently incorrect reports
NFR8: Analysis results are deterministic — same installation, same target version, same external data produces identical output
NFR9: API tokens and credentials are never stored in code, configuration files committed to version control, or analysis output
NFR10: Credentials are loaded from environment variables, `.env.local` files (local development), or injected secrets (CI/CD)
NFR11: Private GitLab instances are accessed via existing git credentials or API tokens — the tool does not manage SSH keys
NFR12: Private Packagist instances are accessed via Composer `auth.json` mechanisms — the tool respects existing Composer authentication
NFR13: Filesystem access to the target installation is read-only — the tool never modifies the analyzed system
NFR14: External API clients use configurable timeouts and respect rate limits
NFR15: All HTTP clients use a consistent User-Agent header for traceability
NFR16: JSON output conforms to a stable, documented schema suitable for downstream tool consumption
NFR17: Exit codes follow conventional semantics (0 = success, non-zero = categorized failure) for CI/CD integration
NFR18: The tool operates correctly when run non-interactively (no TTY, no stdin) for pipeline environments
NFR19: PHPStan Level 8 compliance with zero errors across all source code
NFR20: Minimum 80% line coverage, 100% coverage for risk scoring and availability checking logic
NFR21: New analyzers can be added without modifying existing code (plugin architecture via DI tags)
NFR22: Project documentation sufficient for a new developer to set up, understand, and contribute within one day

### Additional Requirements

Technical requirements from Architecture that impact implementation:

- AR1: `VersionProfileRegistry` — explicit per-version profiles (v11–v14) centralizing discovery paths, core extension lists, and composer.json override keys; required before v11 bug fix and v13/v14 support claims
- AR2: `StreamingOutputManager` — pre-flight writability check at command startup; file-based storage for large content fields (diff, code_before, code_after, error output); deterministic file naming via sha256 hash (never random or time-based); null return on write failure (not exception); Infrastructure-only concern — Domain holds `string|null`, never `FileReference`
- AR3: `ComposerSourceParser` — parses `composer.json` `repositories` section as sole source of truth for extension origins; no URL-sniffing heuristics; unmatched sources produce visible Console warning, not silent skip
- AR4: `GitProviderFactory` extension — provider resolution order: known public hosts → configured private providers → HTTPS fallback; GitLabProvider and BitbucketProvider for FR12/FR13; private instances configured in `.typo3-analyzer.yaml` (URL, auth method)
- AR5: `CachingAnalyzerDecorator` — new analyzers implement `AnalyzerInterface` directly (never extend `AbstractCachedAnalyzer`); `autoconfigure: false` mandatory to prevent double-tagging; `AnalysisResultSerializer` handles result serialization/deserialization
- AR6: `ReportGenerateCommand` — separate `report generate` subcommand; reads only from cache (no `AnalyzerInterface` injection); also requires `StreamingOutputManager.validateOutputDirectory()` pre-flight
- AR7: Customer Twig templates — `resources/templates/customer/` using Twig `{% extends %}` inheritance over technical base templates; `--format=customer --branding=agency.yaml` flags
- AR8: Exit codes — TTY-independent (0 = success, 1 = high-risk findings, 2 = tool error); `--no-interaction` and `TYPO3_ANALYZER_NO_INTERACTION=1` affect prompts only, not exit codes; dedicated unit tests without TTY simulation
- AR9: No `__DIR__`-relative paths to tool's own resources outside `ProjectRootResolver`/`BinaryPathResolver`; CI enforcement check required before PHAR distribution
- AR10: Integration test fixture required for every claimed supported TYPO3 major version before that version is declared supported; minimum coverage: standard Composer install, custom `web-dir`, custom `vendor-dir`

Implementation status notes from feature specs (for sequencing):
- FR1–FR8 Installation Discovery: COMPLETE (InstallationDiscoverySystem fully implemented)
- FR9–FR11 TER/Packagist/GitHub: COMPLETE
- FR12–FR13 GitLab/Bitbucket: NOT DONE
- FR17–FR21 Code Analysis (Rector/Fractor/LOC): COMPLETE
- FR22–FR26 Risk Scoring: COMPLETE
- FR27–FR31 Technical Reporting: COMPLETE
- FR32–FR35 Customer Reports: NOT DONE
- FR41 Caching: COMPLETE
- FR42 ClearCacheCommand: NOT IMPLEMENTED (moved to Epic 5 Story 5.4)
- FR43 Streaming Output: NOT DONE (spec written)
- PHPStan Analyzer (Growth phase): NOT STARTED
- PathResolutionService: COMPLETE
- RefactorReportingService: COMPLETE (ReportContextBuilder, TemplateRenderer, ReportFileManager split done)
- TYPO3 v11 core extension bug: OPEN

### UX Design Requirements

N/A — CLI tool with no graphical user interface. No UX design document exists.

### FR Coverage Map

FR1: Foundation (Complete) — Analyze installation by filesystem path
FR2: Foundation (Complete) — Auto-discover TYPO3 installation type
FR3: Foundation (Complete) — Multi-strategy TYPO3 version detection
FR4: Foundation (Complete) — Discover and catalog all extensions
FR5: Foundation (Complete) — Default target version from release cycle
FR6: Foundation (Complete) — Override target version via CLI flag/config
FR7: Epic 1 — Handle TYPO3 v11–v14 with version-specific discovery (v11 bug fix)
FR8: Epic 1 — Identify and exclude core extensions (v11 core extension bug fix)
FR9: Foundation (Complete) — Check extension availability on TER
FR10: Foundation (Complete) — Check extension availability on Packagist
FR11: Foundation (Complete) — Check extension availability on GitHub
FR12: Epic 2 — Check extension availability on GitLab (public + private instances)
FR13: Epic 2 — Check extension availability on Bitbucket
FR14: Epic 2 — Aggregate availability data across all sources (completing coverage)
FR15: Foundation (Complete) — Detect abandoned/unmaintained extensions
FR16: Foundation (Complete) — Per-extension-type analysis strategy
FR17: Foundation (Complete) — Run Rector analysis against extensions
FR18: Foundation (Complete) — Run Fractor analysis against extensions
FR19: Foundation (Complete) — Measure code complexity metrics
FR20: Foundation (Complete) — Classify Rector findings by severity/change type
FR21: Foundation (Complete) — Execute external tools as isolated processes with timeout
FR22: Foundation (Complete) — Calculate risk score (0–100) per extension
FR23: Foundation (Complete) — Categorize extensions into risk levels
FR24: Foundation (Complete) — Generate per-extension recommendations
FR25: Foundation (Complete) — Aggregate risk overview across all extensions
FR26: Epic 5 — Structured risk/analysis metadata for downstream automation (stable JSON schema)
FR27: Foundation (Complete) — Generate HTML reports with per-extension detail pages
FR28: Foundation (Complete) — Generate Markdown reports
FR29: Foundation (Complete) — Generate JSON reports for machine consumption
FR30: Epic 4 — Re-generate reports from cached data (ReportGenerateCommand)
FR31: Foundation (Complete) — Full report content (overview, risk distribution, matrix, summaries)
FR32: Epic 4 — Customer-friendly report variant with reduced technical detail
FR33: Epic 4 — Customizable report templates with agency branding
FR34: Epic 4 — Project metadata in reports (name, customer, date, author)
FR35: Epic 4 — Customer report in non-technical language
FR36: Foundation (Complete) — Run without configuration file using defaults
FR37: Foundation (Complete) — Generate configuration file via init command
FR38: Foundation (Complete) — Configure via YAML configuration file
FR39: Foundation (Complete) — Override configuration via CLI flags (--target-version, --format, --output etc. already implemented)
FR40: Epic 5 — Override configuration via environment variables for CI/CD
FR41: Foundation (Complete) — Cache analysis results
FR42: Epic 5 (Story 5.4) — Clear analysis cache (ClearCacheCommand not yet implemented)
FR43: Epic 3 — Stream analyzer output to prevent memory exhaustion
FR44: Foundation (Complete) — Report analysis progress during execution
FR45: Foundation (Complete) — List available analyzers with status
FR46: Foundation (Complete) — List discovered extensions
FR47: Foundation (Complete) — Detect required external tool availability

## Epic List

### Epic 1: Trustworthy Analysis Across TYPO3 Versions
Developer can run analysis on any TYPO3 v11–v14 installation and receive correct results, because the tool uses centralized version-specific discovery profiles and correctly identifies and excludes core extensions. Fixes the open v11 core extension detection bug. Gates v13 and v14 support on integration test fixtures per AR10.
Implements: `VersionProfile`, `VersionProfileRegistry`, `VersionProfileRegistryFactory` (AR1), v11 bug fix, v13 and v14 integration test fixtures (AR10).
**FRs covered:** FR7, FR8

### Epic 2: Complete Extension Source Coverage — GitLab & Bitbucket
Developer gets version availability data for extensions hosted on GitLab (public and private instances) or Bitbucket, completing the full source picture alongside existing TER/Packagist/GitHub. Unmatched sources produce a visible warning rather than silent omission.
Implements: `ComposerSourceParser` (AR3), `GitLabProvider`, `BitbucketProvider` (AR4), private-instance configuration in `.typo3-analyzer.yaml`.
**FRs covered:** FR12, FR13, FR14 (completing aggregation)

### Epic 3: Memory-Safe Analysis for Large Installations
Developer can analyze installations with 40+ extensions including large Rector/Fractor finding sets without memory exhaustion, segmentation faults, or crashes. Large content fields are written to files during rendering; Domain objects hold `string|null` only.
Implements: `StreamingOutputManager`, `ContentStreamWriter`, `FileReference` (AR2). Pre-flight writability check in `AnalyzeCommand`. Domain layer unchanged.
**FRs covered:** FR43

### Epic 4: Customer Offer Report Generation
Developer or PM can generate an agency-branded customer report from cached analysis data — reduced technical detail, non-technical language, project metadata — ready for customer offers without manual reformatting.
**Prerequisite: Epic 3 must be complete** — Story 4.1 requires `StreamingOutputManager` from Epic 3.
Implements: `ReportGenerateCommand` (AR6), customer Twig template set with `{% extends %}` inheritance (AR7), branding YAML configuration (`--branding=agency.yaml`).
**FRs covered:** FR30, FR32, FR33, FR34, FR35

### Epic 5: Hardened CI/CD Pipeline Integration
A CI pipeline can run analysis fully unattended with TTY-independent exit codes (0/1/2), non-interactive mode (`TYPO3_ANALYZER_NO_INTERACTION=1`), environment-variable overrides, a stable JSON schema, and a working cache:clear command — all without ambiguity from terminal-detection logic.
Implements: TTY-independent exit code logic with dedicated unit tests (AR8), env var override support (FR40), stable JSON output contract (NFR16), `CacheCommand` (FR42), documentation consolidation.
**FRs covered:** FR26, FR40, FR42

### Epic 6: PHPStan Static Code Quality Analysis *(Phase 2 Growth — out of MVP scope)*
Developer gets an additional code quality dimension — PHPStan static analysis findings (runtime errors, type violations, code quality issues) — included in risk scoring and reports for private and public extensions. Introduces the `CachingAnalyzerDecorator` pattern for all new analyzers going forward.
Implements: `PhpstanAnalyzer` via `AnalyzerInterface` directly + `CachingAnalyzerDecorator` (AR5), PHPStan config generation, result parsing, report integration.
**FRs covered:** Supplements FR17–FR21 with PHPStan analysis dimension; establishes AR5 decorator pattern

---

## Epic 1: Trustworthy Analysis Across TYPO3 Versions

Developer can run analysis on any TYPO3 v11–v14 installation and receive correct results, because the tool uses centralized version-specific discovery profiles and correctly identifies and excludes core extensions. Fixes the open v11 core extension detection bug. Gates v13/v14 support on integration test fixtures.

### Story 1.1: Version Profile Registry

As a developer,
I want the tool to use a centralized registry of per-version installation profiles (v11–v14),
So that version-specific paths, core extension lists, and discovery modes are defined in one auditable place and used consistently across all discovery services.

**Acceptance Criteria:**

**Given** the codebase has no `VersionProfileRegistry`
**When** I implement `VersionProfile`, `VersionProfileRegistry`, and `VersionProfileRegistryFactory` in `Infrastructure/Discovery/`
**Then** the registry contains explicit profiles for TYPO3 v11, v12, v13, and v14, each defining default vendor dir, web dir, cms package dir, core extension key list, and supported discovery modes (legacy/Composer)
**And** `VersionProfileRegistry::getProfile(int $majorVersion)` returns the correct profile for a given major version integer
**And** `VersionProfileRegistryFactory` is registered as a factory service in `services.yaml` using factory syntax (never in `Shared/ContainerFactory`)
**And** composer.json keys (`config.vendor-dir`, `extra.typo3/cms.web-dir`) always override profile defaults when present
**And** `VersionProfileRegistry` and `VersionProfile` have 100% unit test coverage
**And** `VersionProfile` is a `readonly class` with no framework imports
**And** PHPStan Level 8 reports zero errors on all new classes

---

### Story 1.2: TYPO3 v11 Core Extension Accurate Exclusion

As a developer,
I want the tool to correctly identify and exclude core extensions in TYPO3 v11 installations,
So that risk scores for v11 projects are accurate and the tool can be trusted as the basis for upgrade estimates.

**Acceptance Criteria:**

**Given** a TYPO3 v11 Composer installation with known core extensions (e.g., `cms-core`, `cms-backend`)
**When** I run `analyze` against that installation
**Then** all extensions present in the v11 profile's `coreExtensionKeys` list are classified as `core` type
**And** core extensions are excluded from version availability checks and code analysis
**And** the existing v11 legacy and v11 Composer test fixtures pass without regression
**And** the previously failing real-project scenario (e.g., `verkehrswendeplattform`-style v11 installation) now produces correct core extension exclusion
**And** `InstallationDiscoveryService` and `ExtensionDiscoveryService` both query `VersionProfileRegistry` for core extension lists instead of any hardcoded logic
**And** all existing tests continue to pass
**And** PHPStan Level 8 reports zero errors

---

### Story 1.3: TYPO3 v13 Composer Installation Support

As a developer,
I want the tool to correctly discover extensions in a TYPO3 v13 Composer installation,
So that I can analyze projects targeting the current LTS release and the tool formally supports v13.

**Acceptance Criteria:**

**Given** a new integration test fixture at `tests/Integration/Fixtures/TYPO3Installations/v13Composer/` representing a standard TYPO3 v13 Composer layout
**When** the fixture-based integration tests run
**Then** `InstallationDiscoveryService` correctly detects the installation as TYPO3 v13 Composer mode
**And** `ExtensionDiscoveryService` correctly discovers extensions from `vendor/composer/installed.json`
**And** core extensions defined in the v13 profile are excluded from results
**And** a second fixture `v13ComposerCustomWebDir/` with a non-standard `web-dir` also passes, with the custom path read from `composer.json` and overriding the profile default
**And** both fixtures are physical file trees (no dynamically generated content)
**And** the v13 profile in `VersionProfileRegistry` is marked `supportsComposerMode: true` and `supportsLegacyMode: false`
**And** PHPStan Level 8 reports zero errors

---

### Story 1.4: Fix `InstallationDiscoveryResult::fromArray` Null-Installation TypeError on Cache Replay

As a developer,
I want `InstallationDiscoveryResult::fromArray` to handle a missing or null `installation` key defensively,
so that replaying a cached failure result (or any corrupted cache entry) never throws a `TypeError`.

**Acceptance Criteria:**

**Given** a serialized `InstallationDiscoveryResult` in which `successful` is `true` but `installation` is `null` or absent (e.g., cache written by an older build or a corrupt entry)
**When** `InstallationDiscoveryResult::fromArray` deserializes that entry
**Then** it must not call `Installation::fromArray(null)` and must not throw a `TypeError`
**And** it returns a failed result with an error message indicating cache deserialization failure
**And** the fix is covered by a new unit test in `InstallationDiscoveryResultTest` that exercises the corrupted-cache scenario
**And** all existing tests continue to pass and PHPStan Level 8 reports zero errors

---

### Story 1.5: TYPO3 v14 Composer Installation Support

As a developer,
I want the tool to correctly discover extensions in a TYPO3 v14 Composer installation,
So that I can analyze projects targeting the next major release and the tool formally supports v14.

**Acceptance Criteria:**

**Given** a new integration test fixture at `tests/Integration/Fixtures/TYPO3Installations/v14Composer/` representing a standard TYPO3 v14 Composer layout
**When** the fixture-based integration tests run
**Then** `InstallationDiscoveryService` correctly detects the installation as TYPO3 v14 Composer mode
**And** `ExtensionDiscoveryService` correctly discovers extensions from `vendor/composer/installed.json`
**And** core extensions defined in the v14 profile are excluded from results
**And** a second fixture `v14ComposerCustomWebDir/` with a non-standard `web-dir` also passes, with the custom path read from `composer.json` and overriding the profile default
**And** both fixtures are physical file trees (no dynamically generated content)
**And** the v14 profile in `VersionProfileRegistry` is marked `supportsComposerMode: true` and `supportsLegacyMode: false`
**And** `VersionProfileRegistry::getProfile(14)` returns the v14 profile without error
**And** PHPStan Level 8 reports zero errors

---

### Story 1.6: Fix `ExtensionDiscoveryService` Cache Key Double-Computation

As a developer,
I want the cache key in `ExtensionDiscoveryService::discoverExtensions` to be computed exactly once and reused,
so that a cache miss on read can never be caused by a diverged key on write.

**Acceptance Criteria:**

**Given** `ExtensionDiscoveryService::discoverExtensions` is called with cache enabled
**When** the service computes the cache key for the cache-read check
**Then** the exact same key variable is reused for the cache-write — no second call to `$this->cacheService->generateKey` is made
**And** the refactoring is covered by a unit test that verifies the cache key is passed identically to both `get` and `set`
**And** no behaviour change is introduced: cache hit, miss, and disabled-cache paths all continue to work
**And** PHPStan Level 8 reports zero errors and `composer cs:check` reports no violations

---

### Story 1.7: Add Negative-Case Tests for `InstallationDiscoveryService`

As a developer,
I want `InstallationDiscoveryService::discoverInstallation` to have explicit negative-case test coverage,
so that error paths are verified and future regressions are detected immediately.

**Acceptance Criteria:**

**Given** a filesystem path that is a valid directory but contains no TYPO3 indicators (no `composer.json`, no `PackageStates.php`, no `typo3/` structure)
**When** `discoverInstallation` is called on that path
**Then** the returned result has `isSuccessful() === false`
**And** `getErrorMessage()` is non-empty
**And** no exception is thrown
**And** all new tests are added to `InstallationDiscoveryServiceTest` without modifying existing tests
**And** PHPStan Level 8 reports zero errors

---

## Epic 2: Complete Extension Source Coverage — GitLab & Bitbucket

Developer gets version availability data for extensions hosted on GitLab (public and private instances) or Bitbucket, completing the full source picture alongside existing TER/Packagist/GitHub. Unmatched sources produce a visible Console warning rather than silent omission.

### Story 2.1: Composer Source Parser

As a developer,
I want the tool to parse the analyzed installation's `composer.json` repositories section into typed value objects,
So that downstream provider resolution has a reliable, heuristic-free source of truth for where each extension originates.

**Acceptance Criteria:**

**Given** a TYPO3 installation with a `composer.json` containing a `repositories` section with `vcs`, `composer`, and `path` entries
**When** `ComposerSourceParser::parse(string $composerJsonPath)` is called
**Then** it returns a `DeclaredRepository[]` array where each entry carries `url`, `type`, and optionally `packages`
**And** known public registries (github.com, gitlab.com, bitbucket.org, packagist.org) are identified by their domain without heuristic URL inspection
**And** private repository entries (any other URL) are returned as-is for downstream provider matching
**And** if the `repositories` key is absent or empty, an empty array is returned without error
**And** malformed JSON in `composer.json` is handled gracefully with a logged warning and empty result — never a crash
**And** `ComposerSourceParser` lives in `Infrastructure/Discovery/` and has no domain-layer imports
**And** `DeclaredRepository` is a readonly value object in `Infrastructure/ExternalTool/`
**And** 100% unit test coverage with fixture-based test cases for each repository type variant
**And** PHPStan Level 8 reports zero errors

---

### Story 2.2: GitLab Provider (Public and Private Instances)

As a developer,
I want the tool to check version availability for extensions hosted on GitLab — both gitlab.com and configured private instances —
So that GitLab-hosted extensions are included in risk scoring rather than silently omitted.

**Acceptance Criteria:**

**Given** an extension whose `composer.json` repository URL is on `gitlab.com`
**When** the `VersionAvailabilityAnalyzer` runs
**Then** `GitLabProvider` queries the GitLab API for tags and checks composer.json constraints for TYPO3 version compatibility
**And** authentication uses `GITLAB_TOKEN` environment variable when set; unauthenticated for public repos when not set
**Given** a private GitLab instance URL is declared in the analyzed installation's `composer.json` repositories section and configured in `.typo3-analyzer.yaml` with a matching URL and API token
**When** the analyzer runs
**Then** `GitLabProvider` uses the configured instance URL and token for that private instance
**And** if the GitLab API is unavailable or returns an error, the analyzer logs a warning and returns a partial result with `null` for GitLab availability — analysis continues for all other extensions
**And** API calls use configurable timeouts (NFR2, NFR14) and the project User-Agent header (NFR15)
**And** `GitLabProvider` implements `GitProviderInterface` and is registered in `GitProviderFactory` at resolution priority 1 (known public hosts) and 2 (configured private providers)
**And** `GitLabProvider` lives in `Infrastructure/ExternalTool/GitProvider/`
**And** unit tests cover: successful tag match, no compatible tag, API failure, unauthenticated public access, private instance with token
**And** PHPStan Level 8 reports zero errors

---

### Story 2.3: Bitbucket Provider

As a developer,
I want the tool to check version availability for extensions hosted on Bitbucket,
So that Bitbucket-hosted extensions are included in risk scoring rather than silently omitted.

**Acceptance Criteria:**

**Given** an extension whose `composer.json` repository URL is on `bitbucket.org`
**When** the `VersionAvailabilityAnalyzer` runs
**Then** `BitbucketProvider` queries the Bitbucket API for tags and checks composer.json constraints for TYPO3 version compatibility
**And** authentication uses `BITBUCKET_TOKEN` environment variable when set; unauthenticated for public repos when not set
**And** if the Bitbucket API is unavailable or returns an error, the analyzer logs a warning and returns a partial result — analysis continues for all other extensions
**And** API calls use configurable timeouts and the project User-Agent header
**And** `BitbucketProvider` implements `GitProviderInterface` and is registered in `GitProviderFactory`
**And** `BitbucketProvider` lives in `Infrastructure/ExternalTool/GitProvider/`
**And** unit tests cover: successful tag match, no compatible tag, API failure, unauthenticated access
**And** PHPStan Level 8 reports zero errors

---

### Story 2.4: Unmatched Repository Source Warning

As a developer,
I want the tool to warn me visibly when a repository URL from the installation's `composer.json` cannot be matched by any configured provider,
So that I know the analysis is incomplete for that source and can act on it rather than receiving silently wrong results.

**Acceptance Criteria:**

**Given** an extension whose source URL in `composer.json` does not match github.com, gitlab.com, bitbucket.org, or any configured private provider
**When** the `VersionAvailabilityAnalyzer` runs
**Then** a Console-level WARNING is written in the format: `[WARNING] Repository "{url}" has no configured provider. Analysis skipped. Add a provider in .typo3-analyzer.yaml to enable analysis.`
**And** the extension's availability metric for that source is recorded as `null` (not `false`) to distinguish "unknown" from "not available"
**And** the analysis continues for all other extensions and sources without interruption
**And** the warning appears in Console output regardless of verbosity level
**And** in non-interactive / CI mode (NFR18) the warning is written to stderr
**And** unit tests cover: matched source (no warning), unmatched source (warning emitted), multiple unmatched sources (one warning per source)
**And** PHPStan Level 8 reports zero errors

---

## Epic 3: Memory-Safe Analysis for Large Installations

Developer can analyze installations with 40+ extensions including large Rector/Fractor finding sets without memory exhaustion, segmentation faults, or crashes. Large content fields are written to files during rendering; Domain objects hold `string|null` only.

### Story 3.1: Streaming Infrastructure

As a developer,
I want a streaming output manager that writes large analyzer content fields to individual files instead of holding them in memory,
So that the analysis pipeline can handle installations of any size without memory exhaustion.

**Acceptance Criteria:**

**Given** `StreamingOutputManager`, `ContentStreamWriter`, and `FileReference` do not yet exist
**When** I implement them in `Infrastructure/Streaming/`
**Then** `StreamingOutputManager::streamDiffContent(string $extensionKey, string $analyzer, string $findingId, string $content): ?FileReference` writes content to a file and returns a `FileReference`, or returns `null` on write failure (never throws)
**And** file naming is deterministic: `{analyzerName}/{extensionKey}/{findingId}.{ext}` where `findingId = sha256(file . ':' . line . ':' . ruleId)` — `uniqid()`, `random_bytes()`, and `time()` are forbidden in file naming
**And** `FileReference` is a readonly value object carrying `filePath`, `relativePath`, `size`, and `mimeType` — it never appears in `src/Domain/`
**And** on write failure, `StreamingOutputManager` logs a warning and returns `null`; the caller handles `null` gracefully
**And** `StreamingOutputManager` has 100% unit test coverage for the pre-flight check and fallback path
**And** `ContentStreamWriter` has 100% unit test coverage
**And** `FileReference` has unit tests for existence check and content retrieval
**And** PHPStan Level 8 reports zero errors on all new classes

---

### Story 3.2: Pre-flight Output Directory Check

As a developer,
I want the tool to verify the streaming output directory is writable before analysis begins,
So that I receive a clear, actionable error immediately rather than a crash mid-analysis after minutes of work.

**Acceptance Criteria:**

**Given** `StreamingOutputManager` exists from Story 3.1
**When** `AnalyzeCommand::execute()` is called
**Then** `StreamingOutputManager::validateOutputDirectory()` is the first operation executed, before any discovery or analysis begins
**And** if the output directory does not exist or is not writable, the command exits immediately with exit code `2` and a human-readable error message indicating the path and the problem
**And** `ReportGenerateCommand::execute()` also calls `StreamingOutputManager::validateOutputDirectory()` as its first operation
**And** if validation passes, execution continues normally with no change to existing behavior
**And** the pre-flight check is covered by unit tests
**And** PHPStan Level 8 reports zero errors

---

### Story 3.3: Streaming Integration in Template Rendering

As a developer,
I want large content fields (diffs, code_before, code_after, error output) to be written to files during report generation rather than rendered inline,
So that HTML report pages load quickly and the rendering process does not exhaust memory on large finding sets.

**Acceptance Criteria:**

**Given** `StreamingOutputManager` exists and the output directory has passed the pre-flight check
**When** `ReportContextBuilder` assembles the report context for extensions with Rector or Fractor findings
**Then** large content fields (`diff`, `code_before`, `code_after`, error output) are passed through `StreamingOutputManager` and stored as files; the context receives `FileReference` objects, not raw strings
**And** `TemplateRenderer` renders a file link (relative path) in place of inline code blocks for fields backed by a `FileReference`
**And** Domain finding classes (`RectorFinding`, `FractorFinding`) retain `string|null` fields — `FileReference` is never introduced into `src/Domain/`
**And** if `StreamingOutputManager` returns `null` for a write failure, the template renders a visible "content unavailable" placeholder rather than crashing
**And** file names in the output directory match the deterministic naming pattern from Story 3.1 — running the same analysis twice produces identical file paths
**And** integration tests verify that a report generated from a fixture with large finding content produces file-linked output rather than inline content
**And** all existing report generation tests continue to pass
**And** an integration test runs a full analysis against a fixture with at least 10 extensions and asserts completion within 5 minutes (NFR1) — this test is tagged `@group performance` and excluded from the default CI suite but must be run before any release
**And** PHPStan Level 8 reports zero errors

---

## Epic 4: Customer Offer Report Generation

Developer or PM can generate an agency-branded customer report from cached analysis data — reduced technical detail, non-technical language, project metadata — ready for customer offers without manual reformatting.

### Story 4.1: Report Generate Command

As a developer,
I want a `report generate` subcommand that produces reports from cached analysis data without re-running analysis,
So that I can regenerate or reformat reports (including customer variants) without waiting for a full analysis run.

**Acceptance Criteria:**

**Given** a previous analysis has been run and results are cached
**When** I run `./bin/typo3-analyzer report generate --format=html`
**Then** the command reads analysis data from cache and produces report files without triggering any analyzer or external API call
**And** `ReportGenerateCommand` has no `AnalyzerInterface` dependency — only `CacheService` and `ReportService`
**And** `StreamingOutputManager::validateOutputDirectory()` is called as the first operation in `ReportGenerateCommand::execute()` before any cache reads
**And** if the cache is empty or missing, the command exits with code `2` and a clear message: "No cached analysis found. Run `analyze` first."
**And** if cached data is stale (TTL exceeded per NFR7), the command outputs a visible warning "Cache may be stale — consider re-running analysis" but continues generating the report
**And** the command supports `--format` (html, markdown, json, customer) and `--output` flags consistent with `analyze`
**And** `ReportGenerateCommand` is registered in `AnalyzerApplication` and available as a public service
**And** unit tests cover: successful generation from cache, empty cache error, stale cache warning, missing output directory error
**And** PHPStan Level 8 reports zero errors

---

### Story 4.2: Customer Report Template Set

As a developer,
I want a customer-facing report format that presents findings in non-technical language with reduced detail,
So that PMs can use the output directly in customer offers without manual reformatting.

**Acceptance Criteria:**

**Given** customer Twig templates exist in `resources/templates/customer/`
**When** I run `report generate --format=customer` (or `analyze --format=customer`)
**Then** the generated report uses customer templates that extend the technical base templates via `{% extends %}` and override content blocks to omit raw code, rule names, and complexity scores
**And** the customer report includes: executive summary, risk level per extension (low/medium/high/critical in plain language), key findings in one sentence per extension, and upgrade effort indicator
**And** the customer report omits: Rector rule class names, cyclomatic complexity values, raw diff content, and analyzer-internal identifiers
**And** templates live in `resources/templates/customer/` — `main-report.html.twig`, `extension-detail.html.twig`, and partials in `partials/`
**And** customer templates use `{% extends 'html/...' %}` and only override blocks — no copy-paste of base template markup
**And** a functional test asserts that a customer HTML report generated from a fixture contains no Rector rule class names and does contain a readable risk summary
**And** PHPStan Level 8 reports zero errors on any PHP involved in template rendering

---

### Story 4.3: Branding and Project Metadata in Customer Reports

As a developer,
I want to inject agency branding and project metadata into customer reports via a YAML configuration file,
So that the output carries the agency's logo, colors, and contact details without manual editing.

**Acceptance Criteria:**

**Given** a branding YAML file (e.g., `agency.yaml`) defining `logo`, `primary_color`, `agency_name`, `contact_email`, and `contact_website`
**When** I run `report generate --format=customer --branding=agency.yaml`
**Then** the generated customer report includes the agency logo path, applies the primary color to headings, and shows agency name and contact info in the header/footer
**And** project metadata flags `--project-name`, `--customer-name`, `--author` are supported and included in the report header
**And** if `--branding` is omitted, the report renders without branding (no error, no placeholder shown)
**And** if the branding YAML file does not exist or is malformed, the command exits with code `2` and a clear error message indicating the file path
**And** branding values are HTML-escaped before insertion into templates to prevent XSS (NFR security)
**And** unit tests cover: valid branding file, missing branding file, malformed YAML, omitted branding flag
**And** PHPStan Level 8 reports zero errors

---

## Epic 5: Hardened CI/CD Pipeline Integration

A CI pipeline can run analysis fully unattended with TTY-independent exit codes (0/1/2), non-interactive mode, and environment-variable overrides — all without ambiguity from terminal-detection logic.

### Story 5.1: TTY-Independent Exit Codes

As a CI pipeline operator,
I want the analyzer to return consistent, documented exit codes regardless of whether a TTY is attached,
So that I can build reliable pass/fail gates in CI without worrying about terminal-detection side effects.

**Acceptance Criteria:**

**Given** `AnalyzeCommand` completes an analysis run
**When** all extensions have risk scores below the high-risk threshold
**Then** the command exits with code `0`
**When** one or more extensions are categorized as high-risk or critical
**Then** the command exits with code `1`
**When** the tool itself encounters an error (missing tool, unreadable path, streaming pre-flight failure)
**Then** the command exits with code `2`
**And** exit code logic is implemented in a dedicated method or service — not inline in the TTY-detection or output decoration path
**And** Symfony Console's built-in TTY detection controls color and progress bar rendering only — it has no influence on exit code selection
**And** `--no-interaction` flag and `TYPO3_ANALYZER_NO_INTERACTION=1` env var affect prompts only, not exit codes
**And** dedicated unit tests assert the correct exit code for each scenario without any TTY simulation
**And** `ReportGenerateCommand` follows the same exit code contract (0/2 only — no risk-based exit from report generation)
**And** PHPStan Level 8 reports zero errors

---

### Story 5.2: Non-Interactive Mode and Environment Variable Overrides

As a CI pipeline operator,
I want to configure the analyzer entirely via environment variables and have it run without any interactive prompts,
So that I can integrate it into automated pipelines without custom shell scripting to suppress interaction.

**Acceptance Criteria:**

**Given** the environment variable `TYPO3_ANALYZER_NO_INTERACTION=1` is set
**When** the analyzer runs
**Then** all interactive prompts are suppressed — the command behaves as if `--no-interaction` were passed
**And** the env var affects prompts only; exit codes, output format, and report content are unchanged
**Given** the following environment variables are set: `TYPO3_ANALYZER_TARGET_VERSION`, `TYPO3_ANALYZER_FORMAT`, `TYPO3_ANALYZER_OUTPUT_DIR`
**When** the analyzer runs without corresponding CLI flags
**Then** the env var values are used as the effective configuration for those settings
**And** a CLI flag always takes precedence over the corresponding env var (CLI > env var > YAML config > default)
**And** env var names follow the pattern `TYPO3_ANALYZER_{SETTING_NAME}` and are documented in the configuration reference
**And** unit tests cover: env var applied when no flag set, CLI flag overrides env var, no-interaction env var suppresses prompts
**And** PHPStan Level 8 reports zero errors

---

### Story 5.3: Stable JSON Output Schema

As a CI pipeline operator,
I want the JSON output to conform to a documented, stable schema,
So that downstream tools consuming the JSON do not break when the analyzer is updated.

**Acceptance Criteria:**

**Given** the analyzer runs with `--format=json`
**When** the JSON report is generated
**Then** the top-level structure is exactly `{ "installation": {...}, "extensions": [...], "summary": {...} }`
**And** each extension entry contains: `key`, `version`, `type`, `riskScore`, `riskLevel`, `analyzers: {}`
**And** per-analyzer results are namespaced under `analyzers.{analyzerName}` — e.g., `analyzers.version-availability`, `analyzers.typo3_rector`
**And** new metric fields added by any analyzer go inside the analyzer namespace, never at the extension top level
**And** all field names use `camelCase`
**And** `null` values are always included explicitly (`"field": null`) — fields are never omitted when null
**And** arrays are always arrays, even for single items — never collapsed to a scalar
**And** a functional test asserts the exact top-level schema structure against a fixture-based analysis run
**And** the JSON schema is documented in `documentation/` (schema definition or example file)
**And** PHPStan Level 8 reports zero errors

---

### Story 5.4: Cache Clear Command

As a developer,
I want a `cache:clear` command that removes all cached analysis results,
So that I can force a fresh analysis run after changes to the installation or when results appear stale.

**Acceptance Criteria:**

**Given** the `cache:clear` command does not yet exist
**When** I implement `CacheCommand` (or `ClearCacheCommand`) in `src/Application/Command/`
**Then** running `./bin/typo3-analyzer cache:clear` deletes all files in the configured cache directory
**And** the command outputs a confirmation message indicating how many cache entries were removed
**And** if the cache directory does not exist or is already empty, the command exits with code `0` and an appropriate informational message — not an error
**And** `CacheCommand` is registered in `AnalyzerApplication` and available as a public service in `services.yaml`
**And** unit tests cover: non-empty cache cleared successfully, already-empty cache, non-existent cache directory
**And** PHPStan Level 8 reports zero errors

---

### Story 5.5: Documentation Consolidation

As a contributor,
I want project documentation consolidated into a single, navigable structure,
So that a new developer can find setup, usage, and architecture information without searching across multiple disconnected folders.

**Acceptance Criteria:**

**Given** documentation currently exists in both `docs/` and `documentation/` directories with overlapping topics
**When** consolidation is complete
**Then** a single canonical documentation root exists (either `docs/` or `documentation/`) containing all developer-facing documents
**And** `CLAUDE.md` references point to the consolidated paths without broken links
**And** a top-level `README.md` or `docs/index.md` provides a navigable entry point covering: installation, first run, configuration, adding a new analyzer, running tests
**And** the redundant directory is either removed or contains only a redirect note to the new location
**And** all internal cross-document links are verified to resolve correctly

---

## Phase 2 Growth Epics

*The following epics are out of scope for the current MVP sprint. They are included here for planning continuity but must not be included in MVP sprint planning.*

---

## Epic 6: PHPStan Static Code Quality Analysis *(Phase 2 Growth)*

Developer gets an additional code quality dimension — PHPStan static analysis findings (runtime errors, type violations, code quality issues) — included in risk scoring and reports for private and public extensions. Introduces the `CachingAnalyzerDecorator` as the standard pattern for all future analyzers.

### Story 6.1: Caching Analyzer Decorator

As a developer,
I want a `CachingAnalyzerDecorator` that wraps any `AnalyzerInterface` implementation and adds caching transparently,
So that new analyzers can be implemented without inheriting from `AbstractCachedAnalyzer` and caching concerns stay separate from analysis logic.

**Acceptance Criteria:**

**Given** `CachingAnalyzerDecorator` and `AnalysisResultSerializer` do not yet exist
**When** I implement them in `Infrastructure/Analyzer/`
**Then** `CachingAnalyzerDecorator` implements `AnalyzerInterface`, accepts an `$inner: AnalyzerInterface` constructor argument, and delegates `analyze()` to the inner analyzer after a cache miss
**And** on a cache hit, `CachingAnalyzerDecorator` deserializes the cached result via `AnalysisResultSerializer` and returns it without calling the inner analyzer
**And** `AnalysisResultSerializer` handles serialization and deserialization of `AnalysisResult` to/from a cacheable array format
**And** the DI wiring pattern for a new analyzer with caching uses `autoconfigure: false` on the concrete class and tags only the decorator with `{ name: analyzer }`
**And** the DI wiring pattern for a new analyzer without caching uses `autoconfigure: false` on the concrete class with the `analyzer` tag applied directly
**And** `AbstractCachedAnalyzer` is not deleted — existing analyzers continue to function unchanged during the transition period
**And** `CachingAnalyzerDecorator` has 100% unit test coverage including cache hit, cache miss, and serialization round-trip
**And** PHPStan Level 8 reports zero errors on all new classes

---

### Story 6.2: PHPStan Analyzer

As a developer,
I want a PHPStan analyzer that runs static analysis against extension PHP code and produces categorized findings with a risk score,
So that I can identify potential runtime errors and type violations in private extensions before attempting an upgrade.

**Acceptance Criteria:**

**Given** PHPStan is available as a binary (detected via `getRequiredTools()`)
**When** `PhpstanAnalyzer::analyze(Extension $extension, AnalysisContext $context)` is called for an extension with PHP files
**Then** PHPStan is executed as an isolated process with a generated configuration targeting the extension path and the TYPO3 version's include paths
**And** the JSON output from PHPStan is parsed into categorized issues: runtime errors (method/class not found), type violations, code quality issues
**And** a risk score (0–100) is calculated based on issue count and severity — critical issues (runtime errors) weighted highest
**And** recommendations are generated for the top issues by file and category
**And** if PHPStan is not installed, `hasRequiredTools()` returns `false` and the analyzer is skipped with a visible warning — no crash
**And** if PHPStan times out or crashes for one extension, the analyzer returns a partial result with `riskScore: null` and a recommendation — analysis continues for other extensions (NFR6)
**And** `PhpstanAnalyzer` implements `AnalyzerInterface` directly (never extends `AbstractCachedAnalyzer`)
**And** `PhpstanAnalyzer` is wired in `services.yaml` with `autoconfigure: false`; caching is applied via `CachingAnalyzerDecorator` from Story 6.1
**And** unit tests cover: successful analysis with findings, clean code (zero findings), PHPStan not installed, PHPStan crash/timeout
**And** an integration test runs PHPStan against a fixture extension with known issues and asserts expected finding categories
**And** PHPStan Level 8 reports zero errors on all new classes

---

### Story 6.3: PHPStan Findings in Reports

As a developer,
I want PHPStan findings included in HTML, Markdown, and JSON reports,
So that I can review static analysis results alongside Rector findings in a single report.

**Acceptance Criteria:**

**Given** `PhpstanAnalyzer` has run and produced results for one or more extensions
**When** reports are generated in HTML, Markdown, or JSON format
**Then** each extension detail page/section includes a PHPStan summary: total issues, breakdown by severity (critical/high/medium/low), top affected files, and recommendations
**And** the JSON report includes PHPStan data under `analyzers.phpstan` per extension, following the stable schema from Epic 5 Story 5.3 (camelCase, null-inclusive, always arrays)
**And** the HTML extension detail page renders PHPStan issues in a collapsible section consistent with the existing Rector findings section style
**And** if PHPStan was skipped (tool not installed or extension has no PHP files), the report shows "PHPStan analysis not available" rather than an empty section or missing key
**And** customer report templates (Epic 4) omit PHPStan rule identifiers and raw error messages — showing only a plain-language quality indicator
**And** `ReportContextBuilder` is extended to extract PHPStan metrics following the same pattern as the existing `extractRectorAnalysis()` method
**And** all existing report generation tests continue to pass without modification
**And** PHPStan Level 8 reports zero errors
