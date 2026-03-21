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

| Category | FRs | Architectural Implication |
|---|---|---|
| Installation Discovery & Analysis | FR1–FR8 | Multi-strategy detection, TYPO3 v11–v14 variant handling, read-only filesystem access |
| Version Availability Checking | FR9–FR15 | 5 external API sources (TER, Packagist, GitHub, GitLab, Bitbucket); GitLab/Bitbucket not yet implemented |
| Extension Type Analysis Strategy | FR16 | Per-type routing: core excluded, public/proprietary get code analysis |
| Code Analysis | FR17–FR21 | External process execution (Rector, Fractor binaries), temp config files, timeout + crash handling |
| Risk Scoring & Assessment | FR22–FR26 | 0–100 score per extension, categorical risk levels, aggregate overview, machine-readable output |
| Reporting — Technical | FR27–FR31 | HTML/MD/JSON multi-format, re-generation from cache, per-extension detail pages |
| Reporting — Customer-Facing | FR32–FR35 | Reduced-detail report, branding customization, non-technical language — both unimplemented |
| Configuration & Setup | FR36–FR40 | Zero-config default → CLI flags → YAML file → env vars (progressive depth) |
| Caching & Performance | FR41–FR44 | File-based cache with TTL, streaming output to prevent memory exhaustion (FR43 unimplemented) |
| Tool Management | FR45–FR47 | Analyzer listing, extension listing, external tool presence detection |

**Non-Functional Requirements:**

| Category | Key NFRs | Architectural Impact |
|---|---|---|
| Performance | NFR1–NFR4 | 5 min max for 40 extensions; graceful API timeout; constant memory via streaming; visible progress |
| Reliability | NFR5–NFR8 | Partial results on API failure; continue on binary crash; deterministic output; correct cache invalidation |
| Security | NFR9–NFR13 | Credentials via env/`.env.local`/CI secrets only; read-only filesystem access; Composer auth.json for private Packagist |
| Integration | NFR14–NFR18 | Configurable HTTP timeouts; consistent User-Agent; stable JSON schema; conventional exit codes; non-interactive mode |
| Maintainability | NFR19–NFR22 | PHPStan Level 8; 80%+ coverage (100% for risk/availability logic); plugin architecture via DI tags; one-day onboarding |

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

| Issue | Status | Risk |
|---|---|---|
| ReportService (558 LOC, too many responsibilities) | Resolved — split into ReportContextBuilder, TemplateRenderer, ReportFileManager | Low |
| Path resolution logic duplicated across analyzers | Partially resolved — PathResolutionService exists | Medium |
| Memory exhaustion during rendering | Spec written (StreamingAnalyzerOutput, StreamingTemplateRendering), not implemented | High |
| Oversized classes (YamlConfigurationParser 525, RectorRuleRegistry 498, PhpConfigurationParser 492, LinesOfCodeAnalyzer 466 LOC) | Open | Medium |
| TYPO3 v11 core extension detection bug | Open — confirmed in real project | High (trust issue) |

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

**Decision:** `composer.json` in the analyzed installation is the source of truth for extension origins. No URL-sniffing heuristics.

**Detection flow:**
1. Parse `repositories` in the analyzed installation's root `composer.json` → discover all declared package sources ("known sources")
2. Known public registries (Packagist, TER, github.com, gitlab.com, bitbucket.org) → handled by existing clients
3. Declared private sources (private GitLab instance, private Bitbucket, private Packagist) → require explicit configuration in `.typo3-analyzer.yaml`: URL, source type, auth method (API token, git-over-SSH, git-over-HTTPS). Auth configuration, not discovery — the URL already comes from `composer.json`.
4. Any git URL not matched by a configured source → plain git over HTTPS, no credentials, public access only
5. Unmatched source with no fallback result → explicit warning in report: "Repository `{url}` was not analyzed — configure a matching provider in `.typo3-analyzer.yaml`"

**Legacy installations (v11 non-Composer):** Extensions in `typo3conf/ext/` have no composer provenance. Version availability falls back to TER + key-based lookup only.

**Rationale:** Composer-based installations (mandatory from v13, strongly recommended from v12) already declare all sources. The installation itself provides the information; no heuristics needed. Silent wrong results are prevented by explicit warnings.

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
- v13+: Composer-only; legacy discovery path not implemented

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
4. Git source detection from `composer.json` + `GitProviderFactory` extension
5. GitLab/Bitbucket configured provider support
6. `report generate` subcommand + customer template set
7. Exit code unit tests + `--no-interaction` env var support

**Cross-Component Dependencies:**
- VersionProfileRegistry feeds into InstallationDiscoveryService, ExtensionDiscoveryService, and core extension exclusion logic
- StreamingOutputManager is a dependency of TemplateRenderer and both Rector/Fractor analyzers
- Git source detection from `composer.json` is a prerequisite for any private source authentication configuration
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

| Component | Layer | Never in |
|---|---|---|
| `FileReference` | `Infrastructure/Streaming/` | `Domain/` |
| `VersionProfile`, `VersionProfileRegistry` | `Infrastructure/Discovery/` | `Domain/` |
| `ComposerSourceParser` | `Infrastructure/Discovery/` | `Domain/`, `Application/` |
| `GitProviderFactory` extensions | `Infrastructure/ExternalTool/GitProvider/` | anywhere else |
| `ReportGenerateCommand` | `Application/Command/` | — |
| `StreamingOutputManager` | `Infrastructure/Streaming/` | — |
| `CachingAnalyzerDecorator` | `Infrastructure/Analyzer/` | — |
| `AnalysisResultSerializer` | `Infrastructure/Analyzer/` | — |
| `FileSystemUtility` | `Infrastructure/` | `Domain/` |

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

### Git Source Detection Pattern

**Where parsing happens:** `ComposerSourceParser` in `Infrastructure/Discovery/`. Returns `DeclaredRepository[]`.

**`DeclaredRepository` value object:**
- `url: string` — repository URL from composer.json
- `type: string` — `vcs`, `composer`, `path`, etc.
- `packages: array<string>` — declared package names (if known)

**Provider resolution order in `GitProviderFactory`:**
1. Known public hosts (exact domain: `github.com`, `gitlab.com`, `bitbucket.org`)
2. Configured private providers in `.typo3-analyzer.yaml`
3. Fallback: HTTPS git, no credentials

**Warning for unmatched sources — Console output, not logger:**
```
[WARNING] Repository "https://git.example.com/ext/foo" has no configured provider.
          Analysis skipped. Add a provider in .typo3-analyzer.yaml to enable analysis.
```

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

| Error Type | Pattern |
|---|---|
| External API unavailable | Log warning, return partial result with `null` metrics |
| Binary crash / timeout | Log warning, set `riskScore = null`, add recommendation |
| Invalid analyzer config | Throw `AnalyzerException` — stops this extension's analysis |
| TER API fatal / malformed | Re-throw — do not swallow |
| File not found in discovery | Log warning, skip extension, continue |

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
- `ComposerSourceParser`: 100% for all repository type variants
- JSON output schema: at least one functional test asserting exact structure

---

### Enforcement Guidelines

**Composer quality scripts:**

| Action | Command |
|---|---|
| Check all | `composer lint` |
| Check PHP style | `composer lint:php` |
| Check Rector | `composer lint:rector` |
| Static analysis | `composer sca:php` |
| Fix all | `composer fix` |
| Fix PHP style | `composer fix:php` |
| Fix Rector | `composer fix:rector` |
| Unit tests | `composer test` or `composer test:unit` |

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
│   │   └── ComposerSourceParser.php            # NEW — parses composer.json repositories section
│   │
│   ├── ExternalTool/
│   │   ├── GitProvider/
│   │   │   ├── GitLabProvider.php              # NEW — private GitLab instance client
│   │   │   └── BitbucketProvider.php           # NEW — Bitbucket client
│   │   └── DeclaredRepository.php             # NEW — VO from ComposerSourceParser output
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
│       │   └── GitProvider/
│       │       ├── GitLabProviderTest.php
│       │       └── BitbucketProviderTest.php
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

| Boundary | Rule | Enforcement |
|---|---|---|
| Domain ← Infrastructure | Domain never imports Infrastructure types | PHPStan namespace rules |
| `FileReference` stays in `Infrastructure/Streaming/` | Never referenced in `src/Domain/` | PHPStan + code review |
| `VersionProfile` stays in `Infrastructure/Discovery/` | Domain uses version integers only | PHPStan namespace rules |
| `ComposerSourceParser` reads analyzed installation | Never reads the tool's own composer.json | Class name + test fixture isolation |
| `ReportGenerateCommand` reads only from cache | Never triggers fresh analysis | `CacheService` dependency only — no `AnalyzerInterface` injection |

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

| External System | Client | Auth Mechanism | Failure Behavior |
|---|---|---|---|
| TER API | `TerApiClient` | None (public) | Log warning, null result |
| Packagist | `PackagistClient` | `auth.json` (Composer) | Log warning, null result |
| GitHub | `GitHubClient` | `GITHUB_TOKEN` env var | Log warning, null result |
| GitLab (public) | `GitLabProvider` | `GITLAB_TOKEN` env var | Log warning, null result |
| GitLab (private) | `GitLabProvider` | Configured per-instance in `.typo3-analyzer.yaml` | Warning + skip with message |
| Bitbucket | `BitbucketProvider` | `BITBUCKET_TOKEN` env var | Log warning, null result |
| Rector binary | `RectorExecutor` | None | Log warning, null riskScore, recommendation added |
| Fractor binary | `FractorExecutor` | None | Log warning, null riskScore, recommendation added |

---

### Requirements to Structure Mapping

| FR Category | Primary Location |
|---|---|
| FR1–FR8 Installation Discovery | `Infrastructure/Discovery/` — `InstallationDiscoveryService`, `VersionProfileRegistry`, `ComposerSourceParser` |
| FR9–FR15 Version Availability | `Infrastructure/ExternalTool/` — existing clients + `GitLabProvider`, `BitbucketProvider` |
| FR16 Extension Type Strategy | `Infrastructure/Discovery/ExtensionDiscoveryService` + `VersionProfile.coreExtensionKeys` |
| FR17–FR21 Code Analysis | `Infrastructure/Analyzer/` — existing analyzers + `CachingAnalyzerDecorator` |
| FR22–FR26 Risk Scoring | `Domain/Entity/AnalysisResult` + `Infrastructure/Analyzer/` |
| FR27–FR31 Technical Reporting | `Infrastructure/Reporting/` + `Infrastructure/Streaming/` |
| FR32–FR35 Customer Reporting | `Application/Command/ReportGenerateCommand` + `resources/templates/customer/` |
| FR36–FR40 Configuration | `Infrastructure/Configuration/ConfigurationService` + `.typo3-analyzer.yaml` schema |
| FR41–FR44 Caching & Streaming | `Infrastructure/Cache/CacheService` + `Infrastructure/Streaming/StreamingOutputManager` |
| FR45–FR47 Tool Management | `Application/Command/ListAnalyzersCommand`, `ListExtensionsCommand` |

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

**Functional Requirements:** All 47 FRs architecturally supported.

| Previously uncovered | Now covered by |
|---|---|
| FR12 GitLab availability | `GitLabProvider` — public + configured private instances |
| FR13 Bitbucket availability | `BitbucketProvider` |
| FR32–FR35 Customer report | `ReportGenerateCommand` + `resources/templates/customer/` |
| FR43 Streaming output | `StreamingOutputManager` + `FileReference` |

**Non-Functional Requirements:** All 22 NFRs covered.

| NFR | Resolution |
|---|---|
| NFR3 Memory | `StreamingOutputManager` removes large content from in-memory objects |
| NFR7 Cache invalidation | `CachingAnalyzerDecorator` + `AnalysisResultSerializer` |
| NFR8 Determinism | Deterministic file naming via hash — no random IDs or timestamps |
| NFR17 Exit codes | TTY-independent exit code logic, separately tested |
| NFR18 Non-interactive | `--no-interaction` / `TYPO3_ANALYZER_NO_INTERACTION=1` |

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
