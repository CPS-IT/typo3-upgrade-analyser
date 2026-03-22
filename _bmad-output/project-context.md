---
project_name: 'typo3-upgrade-analyser'
user_name: 'Dirk'
date: '2026-03-20'
sections_completed:
  ['technology_stack', 'language_rules', 'framework_rules', 'testing_rules', 'quality_rules', 'workflow_rules', 'anti_patterns']
status: 'complete'
rule_count: 48
optimized_for_llm: true
---

# Project Context for AI Agents

_This file contains critical rules and patterns that AI agents must follow when implementing code in this project. Focus on unobvious details that agents might otherwise miss._

---

## Technology Stack & Versions

- **PHP** ^8.3 — `declare(strict_types=1)` on all files
- **Symfony** 7.0 — Console, DependencyInjection, Config, HttpClient, Process, Yaml, Filesystem, Finder
- **Twig** 3.8 — report templating
- **nikic/php-parser** 5.0 — AST analysis for PHP configuration files
- **ssch/typo3-rector** 3.6 — TYPO3 code migration analysis
- **a9f/typo3-fractor** 0.5.6 — TypoScript modernization
- **guzzlehttp/guzzle** 7.8 — HTTP client
- **monolog/monolog** 3.5 — structured logging
- **PHPUnit** 12.3 — attribute-based config (`#[CoversClass]`), not docblock annotations
- **PHPStan** 2.0 Level 8 — zero tolerance for `mixed`, all types explicit
- **PHP-CS-Fixer** 3.45 — PSR-12 + Symfony rules, risky rules enabled

## Critical Implementation Rules

### Language-Specific Rules

- Every PHP file: `declare(strict_types=1)` + GPL-2.0-or-later license header
- PSR-4 autoloading: namespace root `CPSIT\UpgradeAnalyzer\`
- All dependencies via `private readonly` constructor promotion — no separate property declarations
- Constructor parameter order: Logger/infrastructure first, domain services second, iterables last (`= []` default)
- Value Objects: all properties `private readonly`, no setters — mutate via `with*()` returning `new self(...)`
- Entities needing immutability: `final class` keyword
- Return types always declared — nullable via `?Type`, array details in docblock `@param array<string, ConfigurationData>`
- Backed string enums: `enum Name: string` with business logic methods using `match()` expressions
- Factory methods on VOs: `fromString()`, `createEmpty()`, `from()` — serialization via `toArray()`, `jsonSerialize()`
- Error handling: catch `\Throwable`, re-throw fatal errors, log and continue on recoverable ones
- Infrastructure exceptions carry context via dedicated exception classes

### Framework-Specific Rules

- Symfony DI Container with YAML config (`config/services.yaml`), auto-wiring for `CPSIT\UpgradeAnalyzer\`
- Analyzers: `analyzer` tag, parsers: `configuration_parser` tag — injected via `!tagged_iterator`
- Commands must be `public: true` services
- **Domain layer has zero framework dependencies** — never import Symfony/Twig/Guzzle in `src/Domain/`
- Infrastructure implements Domain interfaces — dependency always points inward
- Application orchestrates, does not contain business logic; Shared for cross-cutting only
- All analyzers extend `AbstractCachedAnalyzer` — override `doAnalyze()`, never `analyze()`
- Declare external tool requirements in `getRequiredTools()` / `hasRequiredTools()`
- Analyzers auto-discovered via DI tags — no manual registration
- Rector/Fractor: external processes via Symfony Process, config in temp files, cleanup after execution
- All API calls through `HttpClientService` wrapper with centralized timeout and User-Agent
- Templates in `resources/templates/{format}/` (html, md), partials in `partials/` subdirectories

### Testing Rules

- Mirror source structure: `tests/Unit/Infrastructure/Analyzer/` tests `src/Infrastructure/Analyzer/`
- Three suites: Unit, Integration, Functional
- PHPUnit 12.3 attributes only — `#[CoversClass]`, `#[Test]`, `#[DataProvider]` — never docblock annotations
- No `test` prefix on methods — use `#[Test]` attribute, method name describes expected behaviour
- Example: `#[Test] public function analysisReturnsRiskScoreForMissingExtension(): void`
- Prefer data providers over individual test methods: `#[DataProvider('descriptiveName')]` with `public static function descriptiveNameProvider(): iterable`
- Assertions: `self::assertEquals()` — always `self::`, never `$this->`
- Mocks: `$this->createMock(InterfaceClass::class)` — mock the interface, not concrete class
- **Fixtures: physical files only** — do not generate dynamically, use `tests/Fixtures/` or `tests/Integration/Fixtures/`
- Re-use complex fixtures across tests (e.g. mocked TYPO3 installations for different version scenarios)
- Coverage: exceptions excluded, minimum 80% line, 100% for critical business logic
- API integration tests gated by environment variables

### Code Quality & Style Rules

- PHPStan Level 8: zero `mixed`, array shapes documented `@param array<string, ConfigurationData>`
- PHP-CS-Fixer: strict comparison (`===`), native function invocation, trailing commas, ordered imports, short `[]`
- Import order: project namespace first, Symfony/external second, PSR third
- Minimal docblocks — only for complex parameter types, not for self-explanatory methods
- Do not add docstrings, comments, or type annotations to code you did not change
- One class per file, filename matches class name
- Suffixes: `Command`, `Service`, `Interface`, `Exception`, `Test`
- Prefixes: `Abstract{Name}` for abstract classes
- Methods: camelCase — `is{Property}()`, `has{Property}()`, `with{Property}()`, `fromString()`, `createEmpty()`

### Development Workflow Rules

- **Git-flow model:**
  - `main` — production, `develop` — integration
  - `feature/{name}` — from develop, merges into develop
  - `bugfix/{name}` — from develop, merges into develop
  - `hotfix/{name}` — from main, merges into main and develop
  - `release/{semanticVersion}` — from develop, merges into main and develop
- Commit tags: `[FEATURE]`, `[TASK]`, `[BUGFIX]`, `[DOC]`, `[CI]`, `[WIP]`, `[SECURITY]`, `[DRAFT]`, `[DDEV]`
- No commits without explicit user request and review
- Feature process: plan in `documentation/`, branch from develop, TDD, all tests + PHPStan pass, PR into develop
- Bugfix process: branch from develop, regression test first, fix, all tests + PHPStan pass, PR into develop
- Documentation: consolidate into one folder (consolidation pending)
- CI: 5 GitHub Actions workflows (ci, tests, code-quality, api-integration, rector-test)

### Critical Don't-Miss Rules

- **Never** import framework classes in `src/Domain/` — domain stays pure
- **Never** implement `AnalyzerInterface` directly — extend `AbstractCachedAnalyzer`
- **Never** override `analyze()` on analyzers — implement `doAnalyze()` (parent handles caching)
- **Never** add setters to Value Objects — `with*()` returning new instances only
- **Never** use docblock annotations for PHPUnit — attributes only
- TER API can return fatal errors — must re-throw, not swallow
- Path resolution has 6 priority-ordered strategies — do not bypass the registry
- Rector/Fractor as external processes — always handle process failure and timeout
- No API tokens in code — use `.env.local`
- HTTP clients must use configured timeouts — never unbounded
- PHP config parsing via AST (nikic/php-parser) — never `eval()` or `include()`
- Large analyzer output causes memory exhaustion during rendering — known issue, streaming planned
- Cache results via `AbstractCachedAnalyzer` — never skip caching for API-dependent analyzers
- Prefer iterating `iterable` directly — `iterator_to_array()` only when needed

---

## Usage Guidelines

**For AI Agents:**
- Read this file before implementing any code
- Follow ALL rules exactly as documented
- When in doubt, prefer the more restrictive option
- Update this file if new patterns emerge

**For Humans:**
- Keep this file lean and focused on agent needs
- Update when technology stack changes
- Review quarterly for outdated rules
- Remove rules that become obvious over time

Last Updated: 2026-03-20
