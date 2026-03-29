---
stepsCompleted:
  - 'step-01-init'
  - 'step-02-context'
  - 'step-03-starter'
  - 'step-04-decisions'
  - 'step-05-patterns'
  - 'step-06-structure'
  - 'step-07-validation'
  - 'step-08-complete'
lastStep: 8
status: 'complete'
completedAt: '2026-03-21'
inputDocuments:
  - '_bmad-output/project-context.md'
  - 'docs/index.md'
  - 'docs/architecture.md'
  - 'docs/project-overview.md'
  - 'docs/source-tree-analysis.md'
  - 'docs/component-inventory.md'
  - 'docs/development-guide.md'
  - 'documentation/review/2025-08-19_architecture-review.md'
  - 'documentation/implementation/development/MVP.md'
  - 'documentation/implementation/feature/planned/StreamingAnalyzerOutput.md'
  - 'documentation/implementation/feature/planned/StreamingTemplateRendering.md'
  - '_bmad-output/planning-artifacts/prd.md'
workflowType: 'architecture'
project_name: 'typo3-upgrade-analyser'
user_name: 'Dirk'
date: '2026-03-20'
---

# Architecture Decision Document

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

## Project Context Analysis

### Requirements Overview

**Functional Requirements (47 total across 8 categories):**

| Category                          | FRs       | Architectural Implication                                                                                |
|-----------------------------------|-----------|----------------------------------------------------------------------------------------------------------|
| Installation Discovery & Analysis | FR1–FR8   | Multi-strategy detection, TYPO3 v11–v14 variant handling, read-only filesystem access                    |
| Version Availability Checking     | FR9–FR15  | 5 external API sources (TER, Packagist, GitHub, GitLab, Bitbucket); GitLab/Bitbucket not yet implemented |
| Extension Type Analysis Strategy  | FR16      | Per-type routing: core excluded, public/proprietary get code analysis                                    |
| Code Analysis                     | FR17–FR21 | External process execution (Rector, Fractor binaries), temp config files, timeout + crash handling       |
| Risk Scoring & Assessment         | FR22–FR26 | 0–100 score per extension, categorical risk levels, aggregate overview, machine-readable output          |
| Reporting — Technical             | FR27–FR31 | HTML/MD/JSON multi-format, re-generation from cache, per-extension detail pages                          |
| Reporting — Customer-Facing       | FR32–FR35 | Reduced-detail report, branding customization, non-technical language — both unimplemented               |
| Configuration & Setup             | FR36–FR40 | Zero-config default → CLI flags → YAML file → env vars (progressive depth)                               |
| Caching & Performance             | FR41–FR44 | File-based cache with TTL, streaming output to prevent memory exhaustion (FR43 unimplemented)            |
| Tool Management                   | FR45–FR47 | Analyzer listing, extension listing, external tool presence detection                                    |

**Non-Functional Requirements:**

| Category        | Key NFRs    | Architectural Impact                                                                                                    |
|-----------------|-------------|-------------------------------------------------------------------------------------------------------------------------|
| Performance     | NFR1–NFR4   | 5 min max for 40 extensions; graceful API timeout; constant memory via streaming; visible progress                      |
| Reliability     | NFR5–NFR8   | Partial results on API failure; continue on binary crash; deterministic output; correct cache invalidation              |
| Security        | NFR9–NFR13  | Credentials via env/`.env.local`/CI secrets only; read-only filesystem access; Composer auth.json for private Packagist |
| Integration     | NFR14–NFR18 | Configurable HTTP timeouts; consistent User-Agent; stable JSON schema; conventional exit codes; non-interactive mode    |
| Maintainability | NFR19–NFR22 | PHPStan Level 8; 80%+ coverage (100% for risk/availability logic); plugin architecture via DI tags; one-day onboarding  |

**Scale & Complexity:**

- Primary domain: CLI developer tool / offline analysis pipeline
- Complexity level: medium — well-scoped, no real-time or collaborative features, no auth/multi-tenancy in MVP/Phase 2
- Brownfield: Phase 1 complete (~100 source files, ~140 test files, 4 analyzers operational)
- Estimated architectural components requiring decisions: ~12 distinct subsystems

### Technical Constraints & Dependencies

- **PHP 8.3+, Symfony 7.0** — brownfield stack, not negotiable
- **Domain layer must remain framework-free** — no Symfony/Twig/Guzzle imports in `src/Domain/`
- **External binaries:** Rector (`ssch/typo3-rector`) and Fractor (`a9f/typo3-fractor`) invoked via Symfony Process; must handle crash, timeout, malformed output
- **Read-only access** to analyzed TYPO3 installation — the tool never modifies it
- **TYPO3 version matrix (v11–v14):** Each major version has different installation layouts (Composer vs. legacy), extension discovery paths, and config formats. Known bug: v11 core extension detection is incorrect (e.g., real project `verkehrswendeplattform` exhibits this).
- **Test fixture coverage:** v11 legacy, v12 Composer variants covered; v13/v14 fixtures TBD
- **Distribution target:** Composer install (development), PHAR (developer self-service), Docker (CI/CD) — architecture must not assume a fixed filesystem layout for the tool itself
- **PHPStan Level 8 hard gate** — every new type must be explicit, arrays must have documented shapes

### Cross-Cutting Concerns Identified

1. **Memory management / streaming output** — critical, unimplemented. Root cause documented: large Fractor/Rector findings (e.g., 650 KB for news extension) cause segfaults during rendering. Affects finding objects, AnalysisResult, and TemplateRenderer.
2. **External API resilience** — TER, Packagist, GitHub, GitLab, Bitbucket can each fail independently. Partial results must propagate correctly through the pipeline.
3. **Credential management** — API tokens, GitLab instance URLs, private Packagist auth — loaded from env vars / `.env.local` / CI secrets, never stored in code or config files committed to VCS.
4. **Multi-layer caching** — AbstractCachedAnalyzer (analyzer results), CacheService (general file cache), MultiLayerPathResolutionCache (memory + file). Cache invalidation correctness is a reliability requirement (NFR7).
5. **TYPO3 version matrix handling** — v11–v14 variant logic for discovery, config parsing, and core extension exclusion. Currently has known v11 bug.
6. **CI/CD non-interactive execution mode** — distinct from interactive CLI use: no TTY, no color, JSON to stdout, meaningful exit codes, artifact-friendly file output.
7. **Analyzer plugin system** — auto-discovery via DI `analyzer` tag, enforced extension via `AbstractCachedAnalyzer`. All new analyzers must extend it, never implement `AnalyzerInterface` directly.
8. **Domain layer purity** — enforced rule: zero framework imports in `src/Domain/`. Infrastructure implements domain interfaces; dependency direction is always inward.

### Pre-Existing Architectural Debt (from Aug 2025 review)

| Issue                                                                                                                            | Status                                                                              | Risk               |
|----------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------|--------------------|
| ReportService (558 LOC, too many responsibilities)                                                                               | Resolved — split into ReportContextBuilder, TemplateRenderer, ReportFileManager     | Low                |
| Path resolution logic duplicated across analyzers                                                                                | Partially resolved — PathResolutionService exists                                   | Medium             |
| Memory exhaustion during rendering                                                                                               | Spec written (StreamingAnalyzerOutput, StreamingTemplateRendering), not implemented | High               |
| Oversized classes (YamlConfigurationParser 525, RectorRuleRegistry 498, PhpConfigurationParser 492, LinesOfCodeAnalyzer 466 LOC) | Open                                                                                | Medium             |
| TYPO3 v11 core extension detection bug                                                                                           | Open — confirmed in real project                                                    | High (trust issue) |

## Starter Template Evaluation

### Primary Technology Domain

PHP CLI developer tool — brownfield. Stack is fully established and not subject to change. No starter template applies. This section documents the locked technical foundation.

### Established Technical Foundation

**Language & Runtime:**
- PHP 8.3+ — `declare(strict_types=1)` on every file, GPL-2.0-or-later license header
- PSR-4 autoloading, namespace root `CPSIT\UpgradeAnalyzer\`

**Framework & DI:**
- Symfony 7.0 (Console, DependencyInjection, Config, HttpClient, Process, Yaml, Filesystem, Finder)
- Auto-wiring enabled; YAML service config in `config/services.yaml`
- Analyzers: `analyzer` tag; parsers: `configuration_parser` tag — injected via `!tagged_iterator`

**Template Engine:** Twig 3.8 — report rendering only, templates in `resources/templates/{format}/`

**HTTP Clients:** Guzzle 7.8 (external APIs via `HttpClientService` wrapper); Symfony HttpClient 7.0

**Code Analysis Integrations:** nikic/php-parser 5.0 (AST), ssch/typo3-rector 3.6, a9f/typo3-fractor 0.5.6

**Logging:** Monolog 3.5 (structured)

**Testing:**
- PHPUnit 12.3 — attribute-based config only (`#[CoversClass]`, `#[Test]`, `#[DataProvider]`)
- Three suites: Unit / Integration / Functional
- Fixtures: physical files only in `tests/Fixtures/` or `tests/Integration/Fixtures/`
- Mocks: always mock the interface, not the concrete class

**Static Analysis:** PHPStan 2.0 Level 8 — zero `mixed`, all array shapes documented

**Code Style:** PHP-CS-Fixer 3.45 — PSR-12 + Symfony, risky rules enabled, strict comparison enforced

**Architectural Pattern:** Clean Architecture (Application → Domain ← Infrastructure ← Shared). Domain has zero framework dependencies. Infrastructure implements domain interfaces.

**Initialization Command:** N/A — brownfield. Setup via `composer install`.

## Core Architectural Decisions

### Decision Priority Analysis

**Critical Decisions (Block Implementation):**
- Streaming output architecture — memory exhaustion blocks reliability for real-world installations
- TYPO3 version matrix strategy — v11 bug is a trust issue; v13/v14 support requires this resolved
- Git source detection — incomplete version availability produces wrong risk scores

**Important Decisions (Shape Architecture):**
- Customer-facing report delivery mechanism
- CI/CD non-interactive mode detection and exit code behavior

**Deferred Decisions (Post-MVP):**
- PHAR/Docker distribution — Composer install sufficient for internal adoption; constraint documented

---

### Data Architecture & Caching

Caching strategy is established: `AbstractCachedAnalyzer` for analyzer results, `CacheService` for general file cache, `MultiLayerPathResolutionCache` for path resolution. No changes to this layer. Cache invalidation correctness (NFR7) is a test requirement, not a new mechanism.

---

### Streaming Output Architecture

**Decision:** File-based streaming as specified in `StreamingAnalyzerOutput.md`, with the following architectural constraints enforced:

- `FileReference` is an Infrastructure type. It never appears in `src/Domain/`.
- Domain `AnalysisResult` and finding classes hold `string|null` for large content fields (diffs, code_before, code_after, error output). The Infrastructure reporting layer wraps them in `FileReference` during rendering.
- `StreamingOutputManager` performs a **pre-flight writability check** on the output directory at command startup — before analysis begins — and fails fast with a clear actionable error.
- If streaming is disabled or the output directory is unavailable, the pipeline continues with truncated/null content fields and a visible warning. Never a crash.
- Streaming is a transparent Infrastructure concern. Domain and Application layers are unaware files exist.

**Rationale:** Eliminates the root cause of segfaults (650KB+ Fractor output for `news` extension). File-based approach is simpler than in-memory chunking and avoids Twig holding entire result sets in memory.

---

### Git Source Detection & Version Availability

**Decision:** `composer.json`/`composer.lock` in the analyzed installation is the source of truth for extension origins. Version resolution uses a two-tier strategy: Composer CLI as primary resolver, generic git CLI as fallback. No per-provider API clients. No URL-sniffing heuristics.

**Tool preconditions (checked at `AnalyzeCommand` startup, before any analysis):**

- `composer` — **hard precondition.** Checked at startup by `ToolAvailabilityChecker` (see below). If unavailable: fail fast with error message `Required tool 'composer' not found on PATH. Install Composer (https://getcomposer.org/download/) and ensure it is available in your shell environment.` No analysis runs. No graceful degradation path.
- `git` — **hard precondition.** Same check. If unavailable: fail fast with error message `Required tool 'git' not found on PATH. Install Git (https://git-scm.com/downloads) and ensure it is available in your shell environment.` Rationale: the tool targets developers and CI pipelines where both tools are standard infrastructure.
- `vendor/` directory present in the analyzed installation — **soft precondition.** If present: enables optional pre-filter optimization (see step 2 below). If absent: emit INFO, skip optimization, continue with full per-package resolution. Analysis is not blocked. **Stale `vendor/` caveat:** If `composer.lock` has been updated but `composer install` has not been run, the pre-filter may report packages as up-to-date that have been added or changed since the last install. No reliable detection mechanism exists — treat a present `vendor/` as "potentially stale" and document this as a known limitation of the pre-filter.

`composer` is required because Tier 1 and the pre-filter depend on it; Tier 2 does not use it but is never intended to operate as a standalone resolution path.

**Resolution chain (explicit fallback order):**

1. `ComposerSourceParser` extracts VCS URLs from `composer.lock` `packages[].source.url` (primary) and `composer.json` `repositories[].url` (fallback for packages missing from lock)

2. **Optional pre-filter** (only when `vendor/` is present):
   - Run `composer show -liD -d /path --format=json` once — returns all direct dependencies with `latest` version and `latest-status` in ~14s for a typical installation
   - Skip per-package Tier 1 calls for packages where `latest-status == "up-to-date"` — they cannot have a TYPO3-compatible newer version
   - Coverage: direct dependencies only; transitive VCS-sourced extensions not covered by this filter
   - **Known limitation:** Transitive `typo3-cms-extension` packages sourced from private VCS are not discovered by the pre-filter or Tier 1. These are the highest-risk category (private, potentially unmaintained) but require `composer.lock` `packages[]` traversal beyond direct deps — not implemented in this tier. Frequency in real TYPO3 projects is not assessed

3. **Tier 1 — Composer CLI resolution** (`PackagistVersionResolver`):
   - Command: `composer show --all --format=json vendor/package` (no `--working-dir`) — Packagist-indexed packages only (~315ms/pkg; ~400ms with Xdebug active)
   - **Two-call strategy per package:** first call (no version) returns full version list + `requires` for the latest version. If latest is not compatible with the target TYPO3 version, use binary search on the version list with versioned calls (`composer show --all --format=json vendor/package X.Y.Z`) to find the newest compatible version (~315ms per additional call). Binary search reduces worst-case from O(N) to O(log N) — relevant for extensions like `ext-solr` (139 versions)
   - `--working-dir` is NOT used — confirmed to add 11–13s overhead per call due to full repository initialisation on every subprocess. VCS-only packages (not on Packagist) go directly to Tier 2
   - Batch commands (`-o`, `-liD`) are NOT used for version resolution: they lack the `requires` block needed for TYPO3 compatibility matching
   - **When this tier is used:** package name resolves on Packagist
   - **When this tier fails:** package not on Packagist, or network/auth failure → falls through to Tier 2

4. **Tier 2 — Generic git CLI resolution** (`GenericGitResolver`):
   - **Trigger:** Packagist lookup returned no result (VCS-only package) or Tier 1 network/auth failure. NOT triggered by Composer being unavailable — that is a startup error
   - Command: `git ls-remote -t --refs <url>` (the `--refs` flag suppresses peeled `^{}` entries; do NOT use `--tags` alone)
   - Parses tag names into Composer version strings; dominant TYPO3 extension pattern is `X.Y.Z` (no `v` prefix)
   - For `dev-*` constraints: `git ls-remote --refs <url>` (without `-t`) returns both tags and branches in one call
   - Authentication: SSH agent for `git@host:` URLs; git credential helpers for HTTPS. No tool-specific tokens. Confirmed working for private SSH GitLab repos (~630ms/URL)
   - **Limitation:** tag names only — no `requires` block. Fetch `composer.json` from the most recent stable tag ref to determine TYPO3 compatibility (one additional HTTP call per package). **Unverified assumption:** The HTTP cost of this fetch was not benchmarked. For 40 private extensions on a slow internal GitLab instance, 40 raw-file HTTP fetches may significantly exceed the ls-remote timings measured in the spike
   - Performance: ~315–560ms/URL across GitHub, GitLab.com, and private self-hosted GitLab (unaffected by Xdebug)
   - **When this tier fails:** network error, auth failure → emit Console WARNING, record `null`

5. **Failure handling:** If Tier 2 fails for a given repository, emit a Console WARNING with the URL and error. Record availability as `null` (not `false`). Analysis continues for remaining extensions.

6. **Future optimization — parallel execution:** Both tiers are currently designed as serial workloads (40 × ~315ms ≈ 12.6s for Tier 1; 40 × ~560ms ≈ 22.5s for Tier 2). These are within NFR budget for serial execution. Parallel subprocess execution (e.g. `symfony/process` with concurrent pools) is a viable optimization if serial timings become a bottleneck in larger installations. Not planned for initial implementation.

**What each tier provides:**

| Capability                       | Tier 1 (Composer, Packagist)          | Tier 2 (git CLI)                             |
|----------------------------------|---------------------------------------|----------------------------------------------|
| List available versions          | Yes (rich)                            | Yes (tags only)                              |
| Version constraint matching      | Yes (native)                          | Manual parsing                               |
| Dependency metadata (require)    | Yes — latest and per specific version | No (requires separate `composer.json` fetch) |
| Branch-based versions (dev-*)    | Yes                                   | Via `ls-remote --refs`                       |
| Works without vendor/            | Yes                                   | Yes                                          |
| Covers VCS-only private packages | No                                    | Yes                                          |
| Authentication                   | None (Packagist is public)            | SSH agent / git credential helpers           |
| Per-package timing               | ~315ms (warm)                         | ~560ms                                       |

**Legacy installations (v11 non-Composer):** Extensions in `typo3conf/ext/` have no composer provenance. Version availability falls back to TER + key-based lookup only. Tier 2 (git CLI) may be used if a repository URL can be derived from extension metadata.

**Rationale:** Targeting developers and CI pipelines makes `composer` and `git` safe hard requirements — simplifying error handling and eliminating graceful-degradation complexity. The two-tier split is not a Composer-availability fallback but a Packagist-coverage split: Tier 1 handles all Packagist-indexed extensions efficiently; Tier 2 covers VCS-only private packages that Packagist cannot resolve. See [Sprint Change Proposal 2026-03-26](sprint-change-proposal-2026-03-26.md) and [Story 2.0 spike](../../documentation/implementation/development/feature/VcsResolutionSpike.md) for empirical basis.

---

### TYPO3 Version Matrix Strategy

**Decision:** `VersionProfileRegistry` with explicit per-version profiles. Version-specific logic is not scattered across discovery services.

**Profile content per major version (v11–v14):**
- Default installation paths (extensions, vendor dir, web root, typo3conf dir)
- Composer-derived overrides (highest priority) — read from analyzed installation's `composer.json`:
  - `config.vendor-dir` → actual vendor directory
  - `extra.typo3/cms.web-dir` → actual web root
  - `extra.typo3/cms.cms-package-dir` → TYPO3 CMS package location
- Core extension/package list (excluded from availability checks and code analysis)
- Discovery mechanism: PackageStates.php (v11 legacy), `vendor/composer/installed.json` (v12+ Composer), or both

**Version-specific notes:**
- v11: legacy and Composer both supported; PackageStates v5 format; known core extension bug to be fixed against this profile
- v12: both modes supported (Composer strongly recommended, legacy still possible)
- v13: both modes supported; TYPO3 official docs state classic mode is fully supported with no deprecation plan — Composer strongly recommended but legacy remains valid; classic mode fixtures not yet implemented (out of scope for current sprint)
- v14: both modes supported; same policy as v13 per official TYPO3 documentation

**Test gate:** A test fixture for every supported major version is required before that version is claimed as supported. Fixture coverage must include at least: standard Composer install, custom `web-dir`, custom `vendor-dir`.

**Rationale:** Makes the version matrix visible and auditable in one place. Adding v14 support = adding one profile entry, not hunting through the codebase. Composer.json override keys collapse multiple fixture variants into parametric test cases.

---

### Customer-Facing Report Delivery

**Decision:** Separate `report generate` subcommand. `analyze` produces analysis data and technical formats (HTML/MD/JSON). Customer report is a presentation concern, generated from cached analysis results.

```bash
# Analysis workflow (CI/CD, technical)
./bin/typo3-analyzer analyze /path/to/typo3 --format=json

# Offer preparation (human, customer-facing)
./bin/typo3-analyzer report generate --format=customer --branding=agency.yaml
```

**Template approach:** Twig inheritance. Customer templates extend base technical templates and override content sections. Customer templates live in `resources/templates/customer/` and use `{% extends %}` to reuse structural partials.

**Rationale:** Decouples analysis (repeatable, CI-safe) from presentation (one-off, offer-specific). Prevents accidental customer report generation in CI pipelines.

---

### CI/CD Non-Interactive Mode

**Decision:** Output decoration (color, progress bars) uses Symfony Console's built-in TTY detection — no custom implementation. Exit code behavior is **TTY-independent and always consistent**.

- `--no-interaction` flag (Symfony Console built-in) or `TYPO3_ANALYZER_NO_INTERACTION=1` env var affects prompts only — not exit codes, not output format selection
- Exit codes: `0` = success, `1` = analysis completed with high-risk findings, `2` = tool error
- Exit code logic has dedicated unit tests that run without TTY simulation
- JSON output to stdout: explicit `--format=json` flag, not auto-detected from TTY state

**Rationale:** TTY detection is unreliable across pipes, Makefiles, Docker. Coupling exit code behavior to TTY state creates an untestable surface. Symfony Console already handles the decoration concern correctly.

---

### PHAR/Docker Distribution Constraint

**Deferred to Phase 2. Architectural constraint documented now:**

No `__DIR__`-relative paths to the tool's own binaries or resources outside of `ProjectRootResolver` and `BinaryPathResolver`. All path resolution for the tool's own resources goes through these classes.

**Enforcement:** CI check (grep or PHPStan custom rule) that flags `__DIR__` usage outside the two allowed resolver classes. Added before PHAR distribution is shipped.

---

### Decision Impact Analysis

**Implementation Sequence:**
1. TYPO3 v11 core extension bug fix (uses new VersionProfileRegistry — implement profile first)
2. VersionProfileRegistry + version profiles for v11–v13
3. StreamingOutputManager pre-flight check + FileReference in Infrastructure
4. VCS resolution research spike (Story 2.0) — validate Composer CLI and git CLI approaches
5. ComposerSourceParser + PackagistVersionResolver + GenericGitResolver (Stories 2.1–2.3)
6. Integration: wire new resolvers into VersionAvailabilityAnalyzer, update metrics/templates (Story 2.5)
7. Remove old GitProvider subsystem (Story 2.6)
8. `report generate` subcommand + customer template set
9. Exit code unit tests + `--no-interaction` env var support

**Cross-Component Dependencies:**
- VersionProfileRegistry feeds into InstallationDiscoveryService, ExtensionDiscoveryService, and core extension exclusion logic
- StreamingOutputManager is a dependency of TemplateRenderer and both Rector/Fractor analyzers
- VCS resolution research spike (Story 2.0) is a serial prerequisite for all Epic 2 implementation stories
- PackagistVersionResolver and GenericGitResolver replace GitRepositoryAnalyzer in VersionAvailabilityAnalyzer
- Metric rename (`git_*` → `vcs_*`) affects ReportContextBuilder and all 10 report templates
- `report generate` subcommand requires caching to be reliable (NFR7) — reports generated from stale cache must be flagged

## Implementation Patterns & Consistency Rules

### Critical Conflict Points Identified

9 areas where AI agents could make incompatible choices without explicit patterns.

---

### Naming Patterns

**PHP Classes & Files:**
- Suffixes: `Command`, `Service`, `Interface`, `Exception`, `Test`, `Registry`, `Profile`, `Strategy`, `Executor`, `Parser`, `Builder`, `Renderer`, `Manager`
- Prefixes: `Abstract{Name}` for abstract classes only
- One class per file; filename matches class name exactly
- Enums: `{Name}` (no suffix) — e.g., `ExtensionType`, `RectorChangeType`, `ResolutionStatusEnum`

**Methods:**
- Accessors: `is{Property}()`, `has{Property}()`, `get{Property}()`
- Mutation (Value Objects): `with{Property}()` returning `new self(...)`
- Factory: `fromString()`, `createEmpty()`, `from()`, `fromComposerData()`
- Serialization: `toArray()`, `jsonSerialize()`
- Analysis entry point on new analyzers: implement `analyze()` from `AnalyzerInterface` directly

**Services & DI:**
- Interface naming: `{Name}Interface` in same namespace as implementation
- Service IDs in `services.yaml`: FQCN-based (auto-wiring default)
- Tag names: `analyzer`, `configuration_parser`, `version_strategy`, `detection_strategy`

---

### Structure Patterns

**Layer placement rules (non-negotiable):**

| Component                                  | Layer                                      | Never in                  |
|--------------------------------------------|--------------------------------------------|---------------------------|
| `FileReference`                            | `Infrastructure/Streaming/`                | `Domain/`                 |
| `VersionProfile`, `VersionProfileRegistry` | `Infrastructure/Discovery/`                | `Domain/`                 |
| `ComposerSourceParser`                     | `Infrastructure/Discovery/`                | `Domain/`, `Application/` |
| `GitProviderFactory` extensions            | `Infrastructure/ExternalTool/GitProvider/` | anywhere else             |
| `ReportGenerateCommand`                    | `Application/Command/`                     | —                         |
| `StreamingOutputManager`                   | `Infrastructure/Streaming/`                | —                         |
| `CachingAnalyzerDecorator`                 | `Infrastructure/Analyzer/`                 | —                         |
| `AnalysisResultSerializer`                 | `Infrastructure/Analyzer/`                 | —                         |
| `FileSystemUtility`                        | `Infrastructure/`                          | `Domain/`                 |

**Test location:** Mirror source exactly. `src/Infrastructure/Discovery/VersionProfileRegistry.php` → `tests/Unit/Infrastructure/Discovery/VersionProfileRegistryTest.php`.

**Fixtures:** Physical files only. New TYPO3 version fixtures go in `tests/Integration/Fixtures/TYPO3Installations/v{N}{Mode}/`. No dynamically generated fixture content.

---

### Analyzer Implementation Pattern

**Target pattern for all new analyzers: implement `AnalyzerInterface` directly.**

`AbstractCachedAnalyzer` is a legacy base class. The 4 existing analyzers still extend it and are refactoring candidates — tracked as technical debt, not urgent. **New analyzers must not extend `AbstractCachedAnalyzer`.**

**Target architecture:**

```
AnalyzerInterface
    ← ConcreteAnalyzer       (pure implementation, no inheritance)
    ← CachingAnalyzerDecorator  (wraps any AnalyzerInterface, adds caching)
```

**`CachingAnalyzerDecorator`** responsibilities:
- Cache key generation
- Cache lookup and storage
- TTL handling
- `AnalysisResult` serialization/deserialization — delegated to `AnalysisResultSerializer`

**DI wiring — new analyzer with caching:**
```yaml
# autoconfigure: false is mandatory — without it Symfony auto-tags the concrete
# class with the analyzer tag via AnalyzerInterface, causing double execution.
CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\MyNewAnalyzer:
    autoconfigure: false

caching.my_new_analyzer:
    class: CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\CachingAnalyzerDecorator
    arguments:
        $inner: '@CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\MyNewAnalyzer'
    tags: [{ name: analyzer }]
```

**DI wiring — new analyzer without caching:**
```yaml
# autoconfigure: false still required to prevent auto-tagging; tag added explicitly.
CPSIT\UpgradeAnalyzer\Infrastructure\Analyzer\MyFastAnalyzer:
    autoconfigure: false
    tags: [{ name: analyzer }]
```

**`getDirectoryModificationTime()`** moves to `Infrastructure/FileSystemUtility`. Used by `LinesOfCodeAnalyzer` and any analyzer needing file mtime checks.

**`getRequiredTools()` / `hasRequiredTools()`** remain on `AnalyzerInterface` — concrete analyzers implement them directly.

**Startup precondition checks (global, not per-analyzer):** `composer` and `git` availability is checked once by `AnalyzeCommand` at startup via `ToolAvailabilityChecker::assertAvailable('composer', 'git')`. The checker runs `<tool> --version`, throwing a `\RuntimeException` with the error message template defined in the preconditions section above if the binary is not found. This is not routed through `getRequiredTools()` (which is a per-analyzer concern) — it is a command-level pre-flight gate. Resolver classes (`PackagistVersionResolver`, `GenericGitResolver`) have no tool-checking responsibility. `vendor/` presence is checked separately and emits INFO only.

**`ToolAvailabilityChecker` service** (`Infrastructure/ExternalTool/`):
```php
final class ToolAvailabilityChecker
{
    /** @throws \RuntimeException if any tool is not found on PATH */
    public function assertAvailable(string ...$tools): void;

    public function isAvailable(string $tool): bool;
}
```
Error message pattern: `Required tool '<name>' not found on PATH. Install <Name> (<url>) and ensure it is available in your shell environment.` Tool-to-URL mapping is an internal constant of the checker.

**Technical debt migration:**
- `AbstractCachedAnalyzer` is not deleted until all 4 existing analyzers are migrated
- Migration order: `LinesOfCodeAnalyzer` first, then external-tool analyzers
- Each migration is a separate `[TASK]` with its own test run
- `project-context.md` rule updated after first migration is complete
- During transition: existing analyzers continue using `doAnalyze()`; new analyzers use decorator pattern

---

### VersionProfileRegistry Pattern

**Profile definition:**
```php
final class VersionProfile
{
    /** @param array<string> $coreExtensionKeys */
    public function __construct(
        public readonly int $majorVersion,
        public readonly string $defaultVendorDir,
        public readonly string $defaultWebDir,
        public readonly string $defaultCmsPackageDir,
        public readonly array $coreExtensionKeys,
        public readonly bool $supportsLegacyMode,
        public readonly bool $supportsComposerMode,
    ) {}
}
```

**Query pattern:**
```php
$profile = $registry->getProfile($installation->getVersion()->getMajor());
$vendorDir = $composerData['config']['vendor-dir'] ?? $profile->defaultVendorDir;
```

**Rule:** Composer.json keys always override profile defaults. Profile provides fallback only.

**Registration:** `VersionProfileRegistryFactory` lives in `Infrastructure/Discovery/` and is registered as a factory service in `services.yaml`:
```yaml
CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionProfileRegistry:
    factory: ['@CPSIT\UpgradeAnalyzer\Infrastructure\Discovery\VersionProfileRegistryFactory', 'create']
```
Never registered in `Shared/ContainerFactory` — that class must not import `Infrastructure/Discovery/` types (layer violation).

---

### StreamingOutputManager Pattern

**Pre-flight check — mandatory in `AnalyzeCommand::execute()` before analysis begins:**
```php
$this->streamingOutputManager->validateOutputDirectory(); // throws StreamingException on failure
```

**Domain finding classes — large content fields:**
```php
// CORRECT — Domain holds string|null
public readonly ?string $diff;

// WRONG — never in Domain
public readonly ?FileReference $diffFile;
```

**Infrastructure reporting layer wraps during rendering:**
```php
// In ReportContextBuilder (Infrastructure only)
$diffFile = $diff !== null
    ? $this->streamingOutputManager->streamDiffContent(...)
    : null;
```

**File naming — deterministic (NFR8):** File names must be derived from content identity, not random values. Pattern: `{analyzerName}/{extensionKey}/{findingId}.{ext}` where `findingId = sha256(file . ':' . line . ':' . ruleId)`. Never use `uniqid()`, `random_bytes()`, or `time()` in file naming. Same inputs → same paths → deterministic output across runs.

**Fallback contract:** `StreamingOutputManager` returns `null` on write failure (not throws). Callers handle `null` gracefully. Warning is logged and surfaced to Console output.

---

### VCS Source Detection Pattern

**Where parsing happens:** `ComposerSourceParser` in `Infrastructure/Discovery/`. Returns `DeclaredRepository[]`.

**`DeclaredRepository` value object (simplified — no provider-type field):**
- `url: string` — VCS URL from `composer.lock` `source.url` (primary) or `composer.json` `repositories[].url` (fallback)
- `packages: array<string>` — package names associated with this URL

**Resolution chain:**
1. Pass `DeclaredRepository` to `PackagistVersionResolver` (Tier 1 — Composer CLI)
2. On failure, pass to `GenericGitResolver` (Tier 2 — `git ls-remote`)
3. On failure of both tiers, emit Console WARNING and record `null`

Both `PackagistVersionResolver` and `GenericGitResolver` implement `VcsResolverInterface`. The orchestrator (`VersionAvailabilityAnalyzer`) depends on `VcsResolverInterface`, not concrete classes. DI wiring uses named service arguments (not auto-wiring, as two implementations exist for the same interface).

**Warning for unresolvable sources — Console output, not logger:**
```
[WARNING] Could not resolve versions from "https://git.example.com/ext/foo".
          Ensure Composer authentication (auth.json) or git credentials (SSH agent) are configured.
```

**Metric naming:** VCS availability metrics use `vcs_` prefix (not `git_`): `vcs_available`, `vcs_repository_url`, `vcs_latest_version`. The `git_repository_health` metric is removed (no generic equivalent).

---

### JSON Output Schema Pattern

Rules for any agent adding metrics or output fields (NFR16 — stable contract):

- Top-level: `{installation: {...}, extensions: [...], summary: {...}}`
- Per extension: `key`, `version`, `type`, `riskScore`, `riskLevel`, `analyzers: {}`
- Per-analyzer results namespaced: `analyzers.{analyzerName}` — e.g., `analyzers.version-availability`
- New metric fields go inside the analyzer namespace — never at extension top level
- Field names: `camelCase`
- Null values included explicitly (`"field": null`) — never omitted
- Arrays are always arrays, even for single items

---

### Error Handling Pattern in Analyzers

| Error Type                  | Pattern                                                     |
|-----------------------------|-------------------------------------------------------------|
| External API unavailable    | Log warning, return partial result with `null` metrics      |
| Binary crash / timeout      | Log warning, set `riskScore = null`, add recommendation     |
| Invalid analyzer config     | Throw `AnalyzerException` — stops this extension's analysis |
| TER API fatal / malformed   | Re-throw — do not swallow                                   |
| File not found in discovery | Log warning, skip extension, continue                       |

**Never:** silent `catch (\Throwable $e) {}`. Every catch must re-throw, log, or produce a visible warning.

---

### PHPUnit Patterns

**Data providers required when 3+ similar cases exist:**
```php
#[DataProvider('riskLevelProvider')]
public function returnsCorrectRiskLevel(int $score, RiskLevel $expected): void { ... }

public static function riskLevelProvider(): iterable
{
    yield 'low risk' => [20, RiskLevel::LOW];
    yield 'medium risk' => [55, RiskLevel::MEDIUM];
    yield 'high risk' => [80, RiskLevel::HIGH];
}
```

**Coverage requirements for new subsystems:**
- `VersionProfileRegistry`: 100% (wrong profile = wrong analysis)
- `StreamingOutputManager`: 100% for pre-flight check and fallback path
- `ComposerSourceParser`: 100% for all source URL extraction variants
- `ToolAvailabilityChecker`: 100% — tool found, tool not found (exception with correct message), multiple tools
- `PackagistVersionResolver`: successful resolution, failure fallthrough to Tier 2
- `GenericGitResolver`: successful tag listing, tag-to-version parsing, network failure, SSH URL handling
- JSON output schema: at least one functional test asserting exact structure including `vcs_*` metric names

---

### Enforcement Guidelines

**Composer quality scripts:**

| Action          | Command                                 |
|-----------------|-----------------------------------------|
| Check all       | `composer lint`                         |
| Check PHP style | `composer lint:php`                     |
| Check Rector    | `composer lint:rector`                  |
| Static analysis | `composer sca:php`                      |
| Fix all         | `composer fix`                          |
| Fix PHP style   | `composer fix:php`                      |
| Fix Rector      | `composer fix:rector`                   |
| Unit tests      | `composer test` or `composer test:unit` |

**All AI agents MUST:**
- Read `project-context.md` before implementing any code
- Never import `FileReference` or `Infrastructure\Streaming\` types in `src/Domain/`
- Never extend `AbstractCachedAnalyzer` for new analyzers — use decorator pattern
- Never override `analyze()` on existing analyzers that still extend `AbstractCachedAnalyzer` — implement `doAnalyze()` only (transition period)
- Run `composer lint` and `composer sca:php` before considering any implementation complete
- Run `composer fix:php` and `composer fix:rector` to apply automatic fixes before committing
- Add a test fixture for any new TYPO3 version before claiming that version is supported

**Pattern violations:** Flag in PR description. Do not silently fix another agent's pattern violations without noting the change.

**Pattern updates:** Changes to this document require explicit user approval.

## Project Structure & Boundaries

### New Components — Directory Placement

All new components introduced by decisions in this document, placed within the existing Clean Architecture structure:

```
src/
├── Application/
│   └── Command/
│       └── ReportGenerateCommand.php          # NEW — report generate subcommand
│
├── Infrastructure/
│   ├── Analyzer/
│   │   ├── CachingAnalyzerDecorator.php        # NEW — replaces AbstractCachedAnalyzer for new code
│   │   └── AnalysisResultSerializer.php        # NEW — extracted from AbstractCachedAnalyzer
│   │
│   ├── Discovery/
│   │   ├── VersionProfile.php                  # NEW — per-version installation profile (readonly class)
│   │   ├── VersionProfileRegistry.php          # NEW — registry, queried by major version int
│   │   ├── VersionProfileRegistryFactory.php   # NEW — factory service, registered via services.yaml factory syntax
│   │   └── ComposerSourceParser.php            # NEW — extracts VCS URLs from composer.lock/composer.json
│   │
│   ├── ExternalTool/
│   │   ├── ToolAvailabilityChecker.php        # NEW — startup precondition check for required binaries
│   │   ├── VcsResolverInterface.php           # NEW — shared contract for Tier 1 and Tier 2 resolvers
│   │   ├── PackagistVersionResolver.php       # NEW — Tier 1: resolves versions via Composer CLI (Packagist)
│   │   ├── GenericGitResolver.php             # NEW — Tier 2: resolves versions via git ls-remote
│   │   ├── VcsResolutionException.php         # NEW — renamed from GitProviderException
│   │   └── DeclaredRepository.php             # NEW — simplified VO (url + packages only)
│   │
│   ├── Streaming/                              # NEW directory
│   │   ├── StreamingOutputManager.php          # NEW — pre-flight check + file writing orchestration
│   │   ├── ContentStreamWriter.php             # NEW — writes content to individual files
│   │   └── FileReference.php                  # NEW — lightweight file path VO (Infrastructure only)
│   │
│   └── FileSystemUtility.php                  # NEW — getDirectoryModificationTime() + shared fs helpers
│
resources/
└── templates/
    └── customer/                              # NEW directory
        ├── main-report.html.twig              # Extends html/main-report.html.twig
        ├── extension-detail.html.twig         # Extends html/extension-detail.html.twig
        └── partials/
            ├── executive-summary.html.twig    # Customer-specific section
            └── branding.html.twig             # Agency branding block

tests/
├── Unit/
│   └── Infrastructure/
│       ├── Analyzer/
│       │   ├── CachingAnalyzerDecoratorTest.php
│       │   └── AnalysisResultSerializerTest.php
│       ├── Discovery/
│       │   ├── VersionProfileRegistryTest.php
│       │   └── ComposerSourceParserTest.php
│       ├── ExternalTool/
│       │   ├── ToolAvailabilityCheckerTest.php
│       │   ├── PackagistVersionResolverTest.php
│       │   └── GenericGitResolverTest.php
│       └── Streaming/
│           ├── StreamingOutputManagerTest.php
│           ├── ContentStreamWriterTest.php
│           └── FileReferenceTest.php
│
└── Integration/
    └── Fixtures/
        └── TYPO3Installations/
            ├── v13Composer/                   # NEW — TYPO3 v13 standard Composer layout
            ├── v13ComposerCustomWebDir/        # NEW — v13 with custom web-dir
            └── v14Composer/                   # NEW — TYPO3 v14 (when available)
```

---

### Architectural Boundaries

**Layer boundary enforcement:**

| Boundary                                              | Rule                                      | Enforcement                                                       |
|-------------------------------------------------------|-------------------------------------------|-------------------------------------------------------------------|
| Domain ← Infrastructure                               | Domain never imports Infrastructure types | PHPStan namespace rules                                           |
| `FileReference` stays in `Infrastructure/Streaming/`  | Never referenced in `src/Domain/`         | PHPStan + code review                                             |
| `VersionProfile` stays in `Infrastructure/Discovery/` | Domain uses version integers only         | PHPStan namespace rules                                           |
| `ComposerSourceParser` reads analyzed installation    | Never reads the tool's own composer.json  | Class name + test fixture isolation                               |
| `ReportGenerateCommand` reads only from cache         | Never triggers fresh analysis             | `CacheService` dependency only — no `AnalyzerInterface` injection |

**Service communication boundaries:**

```
AnalyzeCommand
    ├── StreamingOutputManager.validateOutputDirectory()   [pre-flight, must be first]
    ├── InstallationDiscoveryService                       [uses VersionProfileRegistry]
    ├── ExtensionDiscoveryService                          [uses VersionProfileRegistry + ComposerSourceParser]
    ├── CachingAnalyzerDecorator → ConcreteAnalyzer        [new analyzers]
    │   or AbstractCachedAnalyzer subclass                 [existing analyzers, transitional]
    └── ReportService → TemplateRenderer                   [uses StreamingOutputManager during rendering]

ReportGenerateCommand
    ├── StreamingOutputManager.validateOutputDirectory()   [pre-flight, must also run here]
    ├── CacheService [read-only — no fresh analysis triggered]
    └── ReportService → TemplateRenderer                   [uses StreamingOutputManager during rendering]
```

**External integration boundaries:**

| External System           | Client                     | Auth Mechanism                     | Failure Behavior                                  |
|---------------------------|----------------------------|------------------------------------|---------------------------------------------------|
| TER API                   | `TerApiClient`             | None (public)                      | Log warning, null result                          |
| Packagist                 | `PackagistClient`          | `auth.json` (Composer)             | Log warning, null result                          |
| VCS repositories (Tier 1) | `PackagistVersionResolver` | None (Packagist is public)         | Network/auth failure → fall through to Tier 2     |
| VCS repositories (Tier 2) | `GenericGitResolver`       | SSH agent / git credential helpers | Log warning, null result                          |
| Rector binary             | `RectorExecutor`           | None                               | Log warning, null riskScore, recommendation added |
| Fractor binary            | `FractorExecutor`          | None                               | Log warning, null riskScore, recommendation added |

---

### Requirements to Structure Mapping

| FR Category                              | Primary Location                                                                                                                                  |
|------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| FR1–FR8 Installation Discovery           | `Infrastructure/Discovery/` — `InstallationDiscoveryService`, `VersionProfileRegistry`, `ComposerSourceParser`                                    |
| FR9–FR11, FR14–FR15 Version Availability | `Infrastructure/ExternalTool/` — `TerApiClient`, `PackagistClient`, `PackagistVersionResolver`, `GenericGitResolver` (FR12/FR13 merged into FR11) |
| FR16 Extension Type Strategy             | `Infrastructure/Discovery/ExtensionDiscoveryService` + `VersionProfile.coreExtensionKeys`                                                         |
| FR17–FR21 Code Analysis                  | `Infrastructure/Analyzer/` — existing analyzers + `CachingAnalyzerDecorator`                                                                      |
| FR22–FR26 Risk Scoring                   | `Domain/Entity/AnalysisResult` + `Infrastructure/Analyzer/`                                                                                       |
| FR27–FR31 Technical Reporting            | `Infrastructure/Reporting/` + `Infrastructure/Streaming/`                                                                                         |
| FR32–FR35 Customer Reporting             | `Application/Command/ReportGenerateCommand` + `resources/templates/customer/`                                                                     |
| FR36–FR40 Configuration                  | `Infrastructure/Configuration/ConfigurationService` + `.typo3-analyzer.yaml` schema                                                               |
| FR41–FR44 Caching & Streaming            | `Infrastructure/Cache/CacheService` + `Infrastructure/Streaming/StreamingOutputManager`                                                           |
| FR45–FR47 Tool Management                | `Application/Command/ListAnalyzersCommand`, `ListExtensionsCommand`                                                                               |

---

### Data Flow — Updated

```
AnalyzeCommand::execute()
    │
    ├── StreamingOutputManager::validateOutputDirectory()   [FIRST — fails fast]
    │
    ├── VersionProfileRegistry::getProfile(majorVersion)
    │       └── VersionProfile (paths, core extensions, discovery mode)
    │
    ├── ComposerSourceParser::parse(composerJsonPath)
    │       └── DeclaredRepository[] (known git sources)
    │
    ├── InstallationDiscoveryService (uses VersionProfile)
    │       └── Installation entity
    │
    ├── ExtensionDiscoveryService (uses VersionProfile + DeclaredRepository[])
    │       └── Extension[] entities (with resolved repository URLs)
    │
    ├── For each Extension × registered Analyzer:
    │       ├── CachingAnalyzerDecorator::analyze()     [new analyzers]
    │       │       └── ConcreteAnalyzer::analyze()
    │       └── AbstractCachedAnalyzer subclass         [existing, transitional]
    │               └── AnalysisResult (metrics, riskScore, string|null large fields)
    │
    └── ReportService
            ├── ReportContextBuilder (assembles context)
            ├── StreamingOutputManager (writes large fields to files → FileReference)
            ├── TemplateRenderer (renders Twig — receives FileReference, not raw content)
            └── ReportFileManager (writes report files)
```

## Architecture Validation Results

### Coherence Validation

**Decision Compatibility:** All technology choices compatible. PHP 8.3 / Symfony 7.0 / PHPUnit 12.3 / PHPStan 2.0 — no version conflicts. `CachingAnalyzerDecorator` works with Symfony DI — requires `autoconfigure: false` on concrete analyzers (documented in patterns).

**Pattern Consistency:** Naming, structure, DI, error handling, and test patterns internally consistent and compatible with existing codebase conventions.

**Structure Alignment:** All new components placed in correct layers. Layer boundary enforcement documented. Data flow updated to reflect streaming pre-flight and two-command report generation.

**Issues resolved during validation:**
- Pre-flight check scope extended to `ReportGenerateCommand` (not only `AnalyzeCommand`)
- `VersionProfileRegistryFactory` placement clarified — `Infrastructure/Discovery/`, factory syntax in `services.yaml`
- `autoconfigure: false` pattern added to DI wiring for concrete analyzers
- Deterministic file naming rule added to `StreamingOutputManager` pattern

---

### Requirements Coverage

**Functional Requirements:** All 45 FRs architecturally supported (FR12/FR13 merged into FR11).

| Previously uncovered                  | Now covered by                                                                                                                                            |
|---------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|
| FR11 VCS availability (all providers) | `PackagistVersionResolver` (Tier 1) + `GenericGitResolver` (Tier 2) — replaces per-provider clients; covers GitHub, GitLab, Bitbucket, Gitea, self-hosted |
| FR32–FR35 Customer report             | `ReportGenerateCommand` + `resources/templates/customer/`                                                                                                 |
| FR43 Streaming output                 | `StreamingOutputManager` + `FileReference`                                                                                                                |

**Non-Functional Requirements:** All 22 NFRs covered.

| NFR                     | Resolution                                                            |
|-------------------------|-----------------------------------------------------------------------|
| NFR3 Memory             | `StreamingOutputManager` removes large content from in-memory objects |
| NFR7 Cache invalidation | `CachingAnalyzerDecorator` + `AnalysisResultSerializer`               |
| NFR8 Determinism        | Deterministic file naming via hash — no random IDs or timestamps      |
| NFR17 Exit codes        | TTY-independent exit code logic, separately tested                    |
| NFR18 Non-interactive   | `--no-interaction` / `TYPO3_ANALYZER_NO_INTERACTION=1`                |

---

### Implementation Readiness

**Decision Completeness:** All critical decisions documented with rationale and examples.

**Structure Completeness:** All new components placed precisely. Test locations mirrored. Integration fixtures gated per version.

**Pattern Completeness:** 9 conflict points addressed. Analyzer pattern transition documented. DI wiring examples cover both caching and non-caching cases with `autoconfigure: false`.

---

### Architecture Completeness Checklist

**Requirements Analysis**
- [x] 47 FRs and 22 NFRs analyzed for architectural implications
- [x] Brownfield constraints identified — existing stack locked
- [x] Cross-cutting concerns mapped — 8 concerns documented

**Architectural Decisions**
- [x] Streaming output — file-based, domain-pure, pre-flight, deterministic naming
- [x] Version matrix — `VersionProfileRegistry` with composer.json overrides
- [x] Git source detection — composer.json as source of truth, no heuristics
- [x] Customer report — `report generate` subcommand, Twig inheritance
- [x] CI/CD mode — TTY-independent exit codes, Symfony Console built-ins
- [x] PHAR constraint — no `__DIR__` outside resolver classes

**Implementation Patterns**
- [x] Analyzer pattern — decorator target, `autoconfigure: false`, transition documented
- [x] Streaming pattern — deterministic naming, domain holds `string|null`, Infrastructure wraps
- [x] VersionProfileRegistry — profile structure, composer.json override order, factory placement
- [x] Git source detection — `DeclaredRepository`, provider resolution order, warning format
- [x] JSON output schema — stable contract rules
- [x] Error handling — per-type table, no silent catch
- [x] PHPUnit patterns — data providers, coverage requirements per subsystem
- [x] Composer scripts — corrected to actual script names

**Project Structure**
- [x] New component placements with layer annotations
- [x] Architectural boundaries — layer enforcement, service communication, external integrations
- [x] Requirements to structure mapping — all 10 FR categories
- [x] Updated data flow diagram

---

### Architecture Readiness Assessment

**Overall Status:** READY FOR IMPLEMENTATION

**Confidence Level:** High

**Key Strengths:**
- Grounded in existing codebase — no greenfield assumptions
- Pre-mortem surfaced real failure modes before they were baked in
- Version matrix makes TYPO3 version support auditable and testable
- Git source detection uses the installation's own data — no heuristics
- Domain layer purity preserved — streaming is a transparent Infrastructure concern
- Decorator pattern for analyzers eliminates inheritance coupling
- Transition plan for `AbstractCachedAnalyzer` is pragmatic and sequenced

**Areas for Future Enhancement:**
- Configuration parsing framework (deferred — value not yet validated)
- PHPStan custom rule for `__DIR__` usage (add before PHAR distribution)
- Risk weighing and effort estimation commands (Phase 2)
- SaaS multi-tenancy considerations (Phase 3)

### Implementation Handoff

**First Implementation Priority:**
1. `VersionProfile` + `VersionProfileRegistry` + `VersionProfileRegistryFactory`
2. TYPO3 v11 core extension bug fix (using the new profile)
3. Test fixtures for v13 (gate before claiming v13 support)
4. `StreamingOutputManager` + `ContentStreamWriter` + `FileReference`
5. `CachingAnalyzerDecorator` + `AnalysisResultSerializer`

**AI Agent Guidelines:**
- Read `project-context.md` and this document before implementing any code
- New analyzers: `AnalyzerInterface` directly + `autoconfigure: false` + decorator in `services.yaml`
- `FileReference` stays in `Infrastructure/Streaming/` — never in `src/Domain/`
- File names in `ContentStreamWriter`: hash-based, never random
- Pre-flight check runs in both `AnalyzeCommand` and `ReportGenerateCommand`
- `VersionProfileRegistryFactory` in `Infrastructure/Discovery/` — factory syntax in `services.yaml`
