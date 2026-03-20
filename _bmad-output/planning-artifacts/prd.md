---
stepsCompleted:
  - 'step-01-init'
  - 'step-02-discovery'
  - 'step-02b-vision'
  - 'step-02c-executive-summary'
  - 'step-03-success'
  - 'step-04-journeys'
  - 'step-05-domain-skipped'
  - 'step-06-innovation-skipped'
  - 'step-07-project-type'
  - 'step-08-scoping'
  - 'step-09-functional'
  - 'step-10-nonfunctional'
  - 'step-11-polish'
classification:
  projectType: 'developer_tool'
  domain: 'devops_cms_migration_tooling'
  complexity: 'medium'
  projectContext: 'brownfield'
  notes: 'CLI primary interface today, CI/CD integration near-term, SaaS medium-term goal. TYPO3-only scope.'
inputDocuments:
  - '_bmad-output/project-context.md'
  - 'docs/index.md'
  - 'docs/project-overview.md'
  - 'docs/architecture.md'
  - 'docs/source-tree-analysis.md'
  - 'docs/development-guide.md'
  - 'docs/component-inventory.md'
  - 'documentation/INSTALLATION.md'
  - 'documentation/USAGE.md'
  - 'documentation/developers/INTEGRATION_TESTS.md'
  - 'documentation/developers/TER_API_FINDINGS.md'
  - 'documentation/review/2025-08-19_architecture-review.md'
  - 'documentation/implementation/UpgradeAnalysisTool.md'
  - 'documentation/implementation/development/MVP.md'
  - 'documentation/implementation/development/feature/ConfigurationParsingFramework.md'
  - 'documentation/implementation/development/feature/ConfigurationParsingFramework-Implementation.md'
  - 'documentation/implementation/development/feature/InstallationDiscoverySystem.md'
  - 'documentation/implementation/development/feature/GitRepositoryVersionSupport.md'
  - 'documentation/implementation/development/feature/RectorFindingsTracking.md'
  - 'documentation/implementation/development/feature/ClearCacheCommand.md'
  - 'documentation/implementation/development/feature/RefactorReportingService.md'
  - 'documentation/implementation/development/feature/Typo3RectorAnalyser.md'
  - 'documentation/implementation/development/feature/phpstanAnalyser.md'
  - 'documentation/implementation/development/feature/PathResolutionService.md'
  - 'documentation/implementation/feature/planned/StreamingAnalyzerOutput.md'
  - 'documentation/implementation/feature/planned/StreamingTemplateRendering.md'
documentCounts:
  briefs: 0
  research: 0
  brainstorming: 0
  projectDocs: 26
workflowType: 'prd'
---

# Product Requirements Document - typo3-upgrade-analyser

**Author:** D.Wenzel
**Date:** 2026-03-20

## Executive Summary

The TYPO3 Upgrade Analyzer is a standalone CLI tool that quantifies the gap between a TYPO3 installation and the next major version. It operates externally -- without requiring installation into the target system -- and produces objective risk scores, effort indicators, and actionable reports from automated cross-source analysis.

The core problem it solves is not "how to upgrade TYPO3" but "how big is the upgrade, and what will it cost?" Agencies and developers currently spend hours manually researching extension availability across TER, Packagist, and Git repositories, then separately running code analysis tools, and finally assembling their findings into estimates. This tool replaces that manual process with a single automated workflow that discovers the installation, analyzes every extension, and generates reports suitable for both client-facing offers and CI/CD pipelines.

The tool supports two upgrade strategies: big-bang projects (one-time assessment for scoping an offer) and continuous upgrade monitoring (repeated analysis to track readiness over time). Target users are TYPO3 agencies, consultants, and developers responsible for planning and executing major version upgrades across TYPO3 11 through 14.

Current state: Phase 1 foundation complete (CLI framework, 4 analyzers, multi-format reporting, path resolution, caching). Phase 2 core discovery partially complete (installation discovery done, configuration parsing in progress). The tool is functional and producing reports today.

Medium-term direction: CI/CD integration as a first-class use case, and SaaS offering to serve agencies without local tooling setup.

### What Makes This Special

1. **Speed over manual research.** Checking version availability across TER, Packagist, and Git for 40+ extensions is tedious, error-prone manual work. This tool does it in minutes.
2. **Private and public extension coverage.** Analyzes custom private extensions (code quality, Rector/Fractor findings, complexity metrics) alongside publicly available third-party extensions (version availability, maintenance status). One tool covers the full extension landscape.
3. **Objectivity.** Risk scores derived from automated multi-source analysis -- not developer intuition. Provides a reliable, defensible data basis for offers and planning, while still requiring human insight for evaluation and estimation.
4. **Repeatability.** Run frequently to track upgrade readiness over time. Fits both one-off project scoping and continuous monitoring workflows.
5. **Dual-audience output.** Machine-readable formats (JSON) for CI pipelines and automation. Human-readable formats (HTML, Markdown) for client reports, offers, and internal documentation. Single analysis, multiple outputs.

## Project Classification

| Attribute | Value |
|---|---|
| **Project Type** | Developer Tool (CLI primary, CI/CD near-term, SaaS medium-term) |
| **Domain** | DevOps / CMS Migration Tooling (TYPO3-specific) |
| **Complexity** | Medium -- external API dependencies, multi-strategy analysis, TYPO3 version-matrix complexity |
| **Project Context** | Brownfield -- Phase 1 complete, Phase 2 in progress, ~100 source files, ~140 test files |

## Success Criteria

### User Success

- **Report as contract basis.** A developer runs the tool against a TYPO3 installation and gets a report defensible enough to attach to a customer offer or internal project proposal. The report answers: how big is the gap, what are the risks, what will it cost.
- **Time savings.** What previously took hours of manual research across TER, Packagist, and Git repositories is completed in minutes with higher accuracy.
- **Adoption across teams.** Developers reach for the tool as the default first step when scoping any TYPO3 upgrade -- not as an optional extra.

### Business Success

- **Near-term (12 months):** Every upgrade team at the agency uses the tool for estimation. 10 upgrade projects instrumented. Project risks measurably reduced compared to pre-tool estimates.
- **Near-term (12 months):** 3-5 customers adopted continuous upgrade monitoring as a recurring service.
- **Mid-term:** Service-level tiers operational (Observe / React / Push Forward) offered at fixed monthly price. Steady recurring revenue stream established.

### Technical Success

- **Reliability:** Analysis runs complete without crashes or silent data loss across TYPO3 11-14 installations.
- **Correctness:** Risk scores and version availability data match manual verification within acceptable tolerance.
- **Extensibility:** New analyzers can be added without modifying existing code (plugin architecture proven).
- **Performance:** Full analysis of a 40+ extension installation completes within a reasonable timeframe without memory exhaustion.

### Measurable Outcomes

| Metric | Target | Timeframe |
|---|---|---|
| Internal team adoption | 100% of upgrade teams | 12 months |
| Upgrade projects using tool | 10+ | 12 months |
| Customers on continuous monitoring | 3-5 | 12 months |
| Analysis accuracy vs manual | >90% agreement | Ongoing |
| Time to produce upgrade estimate | <30 min (from hours) | Immediate |

## User Journeys

### Journey 1: Marco -- Senior Developer Scoping a Big-Bang Upgrade

**Persona:** Marco, senior TYPO3 developer at a mid-size agency. 8 years of TYPO3 experience. He's done several major version upgrades and knows the pain points. His estimates are usually good, but the research phase eats time he'd rather spend coding.

**Opening Scene:** A customer asks for an offer to upgrade their TYPO3 11 installation to v13. Marco knows the project has ~45 extensions -- a mix of TER packages, Composer dependencies, and 12 custom private extensions. Last time he scoped something like this, he spent a full day checking TER, Packagist, and Git repositories one extension at a time, then another half-day writing up findings.

**Rising Action:** Marco runs `typo3-analyzer analyze /path/to/installation --target-version=13.0`. The tool discovers the installation, catalogs all extensions, queries TER, Packagist, and GitHub for version availability, runs Rector and Fractor against the private extensions, and measures their complexity. Within minutes he has a complete picture.

**Climax:** The HTML report shows three extensions flagged as high risk -- one abandoned TER extension with no v13 release, one private extension with 47 Rector findings, and one Composer package whose maintainer hasn't tagged a compatible release. Marco now has objective data to support his estimate rather than gut feelings.

**Resolution:** Marco transfers the key findings into the offer document. The risk scores and extension-by-extension breakdown give the project manager concrete numbers to discuss with the customer. The estimate is defensible. The scoping that used to take 1.5 days took 2 hours including the write-up.

**Capabilities revealed:** CLI analysis workflow, multi-source version checking, code analysis (Rector/Fractor/LOC), risk scoring, HTML report generation.

### Journey 2: Lisa -- Junior Developer on Her First Upgrade

**Persona:** Lisa, junior TYPO3 developer, 1.5 years of experience. She's been assigned her first upgrade project (v12 to v13) and feels uncertain about what to look for and how to estimate the effort.

**Opening Scene:** Lisa's team lead asks her to prepare a preliminary assessment for a customer project. She's seen upgrade reports from senior colleagues but never produced one herself. She doesn't know which extensions are critical, what "Rector findings" mean in practice, or how to judge whether a private extension will be problematic.

**Rising Action:** Lisa runs the analyzer. The report categorizes every extension with a risk level and explains why -- "no compatible version found on TER or Packagist," "23 breaking changes detected by Rector," "high cyclomatic complexity in 4 files." She doesn't need to know where to look; the tool has already looked everywhere.

**Climax:** Lisa realizes she can read the report section by section and understand the upgrade landscape without deep domain knowledge. The recommendations give her a starting point for each extension. She flags the three high-risk items for her team lead to review.

**Resolution:** Lisa delivers a credible first assessment. Her team lead spots one nuance she missed (a private extension that can be replaced entirely), but the data basis was solid. Lisa has learned more about TYPO3 upgrade mechanics in one afternoon than she would have in a week of manual research.

**Capabilities revealed:** Clear report structure for less experienced users, actionable recommendations per extension, risk categorization that doesn't require expert interpretation.

### Journey 3: Katrin -- Project Manager Building an Offer

**Persona:** Katrin, project manager and consultant at the agency. Non-developer. She manages customer relationships, writes offers, and coordinates delivery. She needs to translate technical findings into business language.

**Opening Scene:** Marco hands Katrin the analyzer report for a customer's TYPO3 11-to-13 upgrade. The customer board meeting is in 3 days. Katrin needs to produce a professional offer document in the agency's corporate design (Word/PDF) that explains the upgrade scope, risks, and cost estimate in terms the customer's non-technical management can understand.

**Rising Action:** Katrin opens the report. The detailed technical data is useful for Marco, but her customer won't understand Rector rule counts or cyclomatic complexity scores. She manually extracts the extension risk overview, the summary statistics, and the high-level recommendations. She rewrites them in business language and pastes them into the agency's Word template.

**Climax (pain point):** The manual transfer takes significant time. She has to interpret technical metrics, decide what to include and exclude, format tables, and ensure nothing important is lost in translation. If the analysis is re-run (because the customer delayed the decision by 2 months), she has to redo the entire transfer.

**Resolution (with planned features):** With the customer-friendly report format and customizable templates, Katrin could get a pre-formatted summary report with agency branding that she drops into the offer with minimal editing. The technical detail stays in the full report for the development team.

**Capabilities revealed:** Customer-friendly report format (MVP priority 5), customizable templates with branding (MVP priority 4), separation of technical vs. executive summary content.

### Journey 4: CI Pipeline -- Continuous Upgrade Monitoring

**Persona:** Not a human -- a GitLab CI pipeline running nightly for a customer project under the continuous upgrade service model.

**Opening Scene:** The agency has sold a continuous upgrade monitoring service to a customer. The TYPO3 12 installation is in active development. TYPO3 14 is the next major version on the horizon. The agreement: the agency keeps the gap small through incremental maintenance, flagging risks early.

**Rising Action:** Every night, the CI pipeline runs the upgrade analyzer in non-interactive mode. It checks version availability for all extensions against the target version, runs code analysis on private extensions, and produces machine-readable output (JSON). A downstream job compares results against the previous run and detects changes -- new compatible versions released, new Rector findings introduced, risk score movements.

**Climax:** A TER extension that was previously compatible publishes a breaking change in a new release. The pipeline flags the risk score increase. A Renovate-created merge request for a dependency update is correlated with the analyzer's findings -- the update would resolve 5 Rector deprecation warnings.

**Resolution:** The development team gets a concise diff of what changed since the last run. Maintenance tickets are created for actionable items. The customer receives a periodic summary showing the gap is shrinking. The continuous upgrade service delivers on its promise of transparency and incremental progress.

**Capabilities revealed:** Non-interactive/CI mode, JSON output, exit codes for pipeline integration, delta detection between runs, integration with existing CI toolchain (Renovate, quality-tools).

### Journey 5: Thomas -- Agency Technical Director, Portfolio View

**Persona:** Thomas, technical director. Oversees 15+ TYPO3 projects across the agency. He needs to know which projects are at risk, where to allocate maintenance budget, and which customers to proactively approach with upgrade offers.

**Opening Scene:** Thomas reviews the quarterly portfolio status. Some projects are on TYPO3 11 (end of life approaching), others on 12 or 13. He needs a cross-project view of upgrade readiness to prioritize internal effort and customer outreach.

**Rising Action (current state):** Thomas asks individual developers for status on each project. Some have run the analyzer, some haven't. The information arrives in different formats and levels of detail. Aggregating it into a portfolio view is manual work.

**Resolution (vision):** With the SaaS platform and multi-installation dashboard, Thomas sees all 15 projects on a single screen. Risk scores, version distribution, and trend lines. He can identify the three projects most urgently needing attention and route them to the right team. The continuous upgrade customers show steady improvement; the others show growing gaps.

**Capabilities revealed:** Multi-installation dashboard (Vision), portfolio-level reporting, trend tracking (Vision), SaaS platform (Vision).

### Journey Requirements Summary

| Journey | Capabilities Required | Scope |
|---|---|---|
| Marco (senior dev, big-bang) | CLI workflow, multi-source checking, code analysis, risk scoring, HTML report | MVP (complete) |
| Lisa (junior dev, first upgrade) | Clear report structure, actionable recommendations, risk categorization | MVP (complete, template improvements planned) |
| Katrin (PM, offer document) | Customer-friendly report, customizable templates, branding, executive summary | MVP (priorities 4-5) |
| CI Pipeline (continuous monitoring) | Non-interactive mode, JSON output, exit codes, delta detection | Growth (CI/CD integration mode) |
| Thomas (director, portfolio) | Multi-installation dashboard, trend tracking, SaaS platform | Vision |

Cross-cutting: GitLab/Bitbucket availability checks (MVP priority 3) affect Marco, Lisa, and CI journeys. Streaming output (MVP priority 2) is a technical prerequisite that affects all journeys involving large installations. TYPO3 11 core extension bug fix (MVP priority 1) affects all journeys involving v11 installations.

## Developer Tool Specific Requirements

### Project-Type Overview

The TYPO3 Upgrade Analyzer is a CLI-first developer tool that combines characteristics of a standalone analysis tool (run, get results) with a configurable reporting platform (customize output for different audiences). It must be easy to install, require minimal configuration, and produce useful output on the first run.

### Distribution & Installation

**Current:** `composer create-project` or git clone + `composer install`. Requires PHP 8.3+ and Composer on the host.

**Target options (to be evaluated):**
- **PHAR archive.** Single-file distribution. Download and run. Lowest barrier for developers who already have PHP installed. Challenge: bundling external tool dependencies (Rector, Fractor binaries).
- **Docker image.** Zero host dependencies. Good fit for CI/CD pipelines where PHP version shouldn't matter. Challenge: filesystem access to the target TYPO3 installation (volume mounts).
- **Composer global install.** `composer global require cpsit/typo3-upgrade-analyser`. Familiar to PHP developers but pollutes global Composer state.

Priority: PHAR for developer usage, Docker for CI/CD. Composer install remains as the development/contribution path.

### Command Structure

**Core commands:**

| Command | Purpose | Status |
|---|---|---|
| `analyze` | Full analysis workflow (discover, analyze, report) | Existing |
| `list-analyzers` | Show available analyzers and their status | Existing |
| `list-extensions` | Show discovered extensions | Existing |
| `init-config` | Generate configuration file | Existing |
| `cache:clear` | Clear analysis cache | Existing |

**Planned commands:**

| Command | Purpose | Scope |
|---|---|---|
| `report generate` | Re-generate reports from cached analysis data | MVP |
| `report customize` | Configure report templates, branding, format | MVP |
| `risk configure` | User-provided risk weighing parameters | Growth |
| `effort estimate` | Effort estimation based on analysis and risk data | Growth |
| `analyze --quick` | Quick run with selected analyzers only | Growth |
| `config wizard` | Interactive configuration helper | Growth |

Design principle: `analyze` remains the workhorse. Additional commands serve specific sub-workflows without bloating the main command's option surface.

### Configuration Strategy

**Zero-config default:** The tool runs without any configuration file. Default target version is the current TYPO3 LTS (currently v13, shifts with releases). The tool auto-discovers the installation and applies sensible defaults.

**Progressive configuration depth:**
1. **No config** -- defaults for everything. Works for quick assessments.
2. **CLI flags** -- override target version, output format, analyzer selection. For one-off customization.
3. **YAML config file** -- persistent settings for repeated analysis. Generated via `init-config` or the planned config wizard.
4. **Environment variables** -- CI/CD-friendly overrides for pipeline integration. Override YAML settings when set.

**Config file discovery (convention):** `.typo3-analyzer.yaml` in the project root or the current working directory. Explicit `--config` flag overrides auto-discovery.

### Output & Integration

**Output formats:** HTML, Markdown, JSON (existing). CSV (planned). Customer-friendly report format (planned).

**CI/CD integration requirements:**
- Non-interactive mode (no prompts, no color codes when not a TTY)
- Meaningful exit codes (0 = success, 1 = analysis found high-risk items, 2 = tool error)
- JSON output to stdout for pipeline consumption
- Artifact-friendly file output (reports written to configurable directory)

**External tool dependencies:**
- Rector binary (for Typo3Rector analyzer)
- Fractor binary (for Fractor analyzer)
- Git CLI (for repository analysis)
- These must be bundled or clearly documented as prerequisites per distribution method

### Migration & Version Support

**TYPO3 version matrix:** 11, 12, 13, 14. Each version has different extension discovery mechanisms and configuration formats. The tool must handle all variants without user intervention.

**Target version shifting:** As TYPO3 releases new major versions, the default target version shifts. The tool should detect when its version matrix data is outdated and warn the user.

## Project Scoping & Phased Development

### MVP Strategy & Philosophy

**MVP Approach:** Problem-solving MVP -- deliver enough capability that every upgrade team at the agency reaches for the tool as their default estimation starting point. The tool already works today; the MVP is about closing the gaps that prevent universal internal adoption and making the output convincing enough for customer-facing offers.

**Investment thesis:** The agency has ~10 upgrade projects in the next 12 months. Each one currently involves hours of manual research. The tool eliminates that. The continuous upgrade service model turns one-off project work into recurring revenue. Investing in the tool now pays for itself within the first 2-3 projects where it's used, and the service model compounds that return.

**Resource model:** Currently single-developer. This PRD serves as the basis for securing additional development capacity. The phased roadmap is designed so that Phase 1 can be completed by a small team (1-2 developers) and delivers measurable value before Phase 2 requires broader investment.

### MVP Feature Set (Phase 1)

**Foundation (complete):**
- CLI framework with 4 analyzers (VersionAvailability, Typo3Rector, Fractor, LinesOfCode)
- Multi-format reporting (HTML, Markdown, JSON)
- Path resolution, caching, installation discovery
- TER, Packagist, and GitHub API integration

**Core journeys supported:** Marco (senior dev estimation), Lisa (junior dev first upgrade), Katrin (PM building offer).

**Must-have capabilities (adoption blockers):**

| # | Feature | Rationale | Effort Estimate |
|---|---|---|---|
| 1 | TYPO3 11 core extension bug fix | Incorrect results for v11 installations erode trust | Small |
| 2 | Streaming analyzer output | Memory exhaustion on large installations blocks reliability. Prerequisite for extended Fractor findings | Medium |
| 3 | GitLab/Bitbucket availability checks | Many agency projects host extensions on GitLab, not GitHub. Without this, version availability is incomplete | Medium |
| 4 | Customer-friendly report format | Non-technical report for customer offers. Key differentiator for selling upgrades | Medium |
| 5 | Customizable report templates | Agency branding in reports. Makes output directly usable in offers without manual reformatting | Medium |
| 6 | Documentation consolidation | Merge docs folders. Low effort, reduces contributor friction | Small |

**Dependency chain:**
- #2 (streaming output) unblocks extended Fractor findings (Phase 2)
- #4 and #5 are independent of each other but both serve Katrin's journey
- #1 and #3 are independent, can be parallelized
- #6 has no dependencies, can be done anytime

**What's explicitly out of MVP scope:**
- Configuration parsing framework (complex, uncertain value -- revisit in Phase 2)
- CI/CD integration mode (prototypical implementation exists, generalization is Phase 2)
- Risk weighing and effort estimation commands (requires MVP reports to be in use first)
- PHAR/Docker distribution (Composer install is sufficient for internal adoption)

### Post-MVP Features

**Phase 2 -- Growth (competitive advantage, CI/CD, broader adoption):**

| Feature | Depends On | Business Value |
|---|---|---|
| Extended Fractor findings | Streaming output (Phase 1) | Deeper TypoScript migration analysis |
| CI/CD integration mode | Core MVP stable | Enables continuous upgrade service offering |
| PHPStan analyzer | None | Additional code quality dimension |
| PHAR archive distribution | None | Lowers adoption barrier for external users |
| Docker image | None | CI/CD-native distribution |
| Risk weighing command | MVP reports in use | User-tunable risk assessment |
| Effort estimation command | Risk weighing | Data-driven effort estimates in reports |
| Config wizard | None | Reduces onboarding friction |
| Streaming template rendering | Streaming output | Handles large report datasets |
| Quick analysis mode | None | Faster feedback for selective checks |

**Phase 3 -- Vision (platform, SaaS, portfolio):**

| Feature | Business Value |
|---|---|
| SaaS platform | Agencies without local tooling, broader market |
| Service tiers (Observe / React / Push Forward) | Fixed-price recurring revenue model |
| Multi-installation dashboard | Portfolio view for agency directors |
| Historical trend tracking | Visualize upgrade progress over time |
| Automated upgrade path suggestions | Proactive migration recommendations |
| Configuration parsing framework | Deep installation understanding (revisit if value validated) |

### Risk Mitigation Strategy

**Technical risks:**
- *Memory exhaustion on large installations.* Mitigation: streaming output is MVP priority #2. Without it, the tool is unreliable for real-world projects with 40+ extensions.
- *External API instability.* TER, Packagist, GitHub/GitLab APIs can change or rate-limit. Mitigation: caching layer (exists), graceful degradation on API failure, clear error reporting.
- *TYPO3 version matrix complexity.* Each major version has different discovery mechanisms. Mitigation: comprehensive test fixtures per version, the v11 bug fix establishes the pattern for version-specific handling.

**Market/adoption risks:**
- *Internal resistance to new tooling.* Mitigation: zero-config defaults, immediate value on first run, reports that are directly usable (not requiring manual post-processing).
- *Customer-facing reports not convincing enough.* Mitigation: customer-friendly format and branding are MVP priorities, not afterthoughts.

**Resource risks:**
- *Remains single-developer.* Mitigation: Phase 1 is scoped for 1-2 developers. Each feature is independent enough to be developed in parallel if a second developer joins. The PRD and documentation provide onboarding context.
- *Competing priorities with billable project work.* Mitigation: demonstrate ROI on first 2-3 projects where the tool is used. Track time saved vs. time invested.

## Functional Requirements

### Installation Discovery & Analysis

- FR1: Developer can analyze a TYPO3 installation by providing its filesystem path
- FR2: System can auto-discover TYPO3 installation type (Composer-based, legacy) without user configuration
- FR3: System can detect the current TYPO3 version from the installation using multiple strategies
- FR4: System can discover and catalog all extensions in an installation, classifying them as core, public, or proprietary
- FR5: System can determine the default target version based on the current TYPO3 release cycle
- FR6: Developer can override the target version via CLI flag or configuration
- FR7: System can handle TYPO3 versions 11 through 14, including version-specific discovery mechanisms
- FR8: System can identify core extensions and exclude them from availability checks and code analysis

### Version Availability Checking

- FR9: System can check extension availability on TER for the target TYPO3 version
- FR10: System can check extension availability on Packagist for the target version
- FR11: System can check extension availability on GitHub repositories (tags, branches, version constraints)
- FR12: System can check extension availability on GitLab repositories, including private instances with authentication
- FR13: System can check extension availability on Bitbucket repositories (tags, branches, version constraints)
- FR14: System can aggregate availability data across all sources into a unified availability status per extension
- FR15: System can detect abandoned or unmaintained extensions based on repository and registry metadata

### Extension Type Analysis Strategy

- FR16: System can apply analysis strategy per extension type: core extensions are excluded from all checks; public and proprietary extensions receive code analysis; availability checks query sources appropriate to the extension's distribution channels

### Code Analysis

- FR17: System can run Rector analysis against public and proprietary extensions to detect breaking changes and deprecations
- FR18: System can run Fractor analysis against public and proprietary extensions to detect TypoScript migration needs
- FR19: System can measure code complexity metrics for public and proprietary extensions
- FR20: System can classify Rector findings by severity and change type (breaking, deprecation, migration)
- FR21: System can execute external analysis tools as isolated processes with timeout handling

### Risk Scoring & Assessment

- FR22: System can calculate a risk score (0-100) per extension based on multi-source analysis data
- FR23: System can categorize extensions into risk levels (low, medium, high, critical)
- FR24: System can generate per-extension recommendations based on analysis findings
- FR25: System can provide an aggregate risk overview across all extensions in an installation
- FR26: System can output structured risk and analysis metadata suitable for consumption by downstream automation (CI jobs, issue generation tools)

### Reporting -- Technical

- FR27: Developer can generate reports in HTML format with per-extension detail pages
- FR28: Developer can generate reports in Markdown format
- FR29: Developer can generate reports in JSON format for machine consumption
- FR30: Developer can re-generate reports from cached analysis data without re-running analysis
- FR31: Reports can include installation overview, risk distribution, version availability matrix, code analysis summaries, and recommendations

### Reporting -- Customer-Facing

- FR32: Developer can generate a customer-friendly report variant with reduced technical detail
- FR33: Developer can customize report templates with agency branding (logo, colors, contact information)
- FR34: Developer can include project metadata (project name, customer name, date, author) in reports
- FR35: Customer-friendly report can present risk overview and key findings in non-technical language

### Configuration & Setup

- FR36: System can run without any configuration file using sensible defaults
- FR37: Developer can generate a configuration file via an init command
- FR38: Developer can configure analyzer selection, output formats, and target version via YAML configuration
- FR39: Developer can override configuration settings via CLI flags
- FR40: Developer can override configuration settings via environment variables for CI/CD use

### Caching & Performance

- FR41: System can cache analysis results to avoid redundant API calls and tool executions
- FR42: Developer can clear the analysis cache
- FR43: System can stream analyzer output to prevent memory exhaustion on large installations
- FR44: System can report analysis progress during execution

### Tool Management

- FR45: Developer can list all available analyzers with their status and requirements
- FR46: Developer can list all discovered extensions for an installation
- FR47: System can detect whether required external tools (Rector, Fractor, Git) are available and report missing dependencies

## Non-Functional Requirements

### Performance

- NFR1: Full analysis of an installation with 40 extensions completes in under 5 minutes (excluding network latency for API calls on slow connections)
- NFR2: Individual API calls time out gracefully -- a single unresponsive source must not block the entire analysis
- NFR3: Memory usage stays within reasonable bounds for installations of any size (streaming output addresses this for MVP)
- NFR4: Analysis progress is visible to the user during execution -- no silent waiting periods longer than 10 seconds

### Reliability

- NFR5: If an external API (TER, Packagist, GitHub, GitLab) is unavailable, the analysis completes with partial results and clear indication of what data is missing
- NFR6: If an external tool (Rector, Fractor) crashes or times out for one extension, the analysis continues for remaining extensions
- NFR7: Cached results are invalidated correctly -- stale cache must never produce silently incorrect reports
- NFR8: Analysis results are deterministic -- same installation, same target version, same external data produces identical output

### Security & Credentials

- NFR9: API tokens and credentials are never stored in code, configuration files committed to version control, or analysis output
- NFR10: Credentials are loaded from environment variables, `.env.local` files (local development), or injected secrets (CI/CD)
- NFR11: Private GitLab instances are accessed via existing git credentials or API tokens -- the tool does not manage SSH keys
- NFR12: Private Packagist instances are accessed via Composer `auth.json` mechanisms -- the tool respects existing Composer authentication
- NFR13: Filesystem access to the target installation is read-only -- the tool never modifies the analyzed system

### Integration

- NFR14: External API clients use configurable timeouts and respect rate limits
- NFR15: All HTTP clients use a consistent User-Agent header for traceability
- NFR16: JSON output conforms to a stable, documented schema suitable for downstream tool consumption
- NFR17: Exit codes follow conventional semantics (0 = success, non-zero = categorized failure) for CI/CD integration
- NFR18: The tool operates correctly when run non-interactively (no TTY, no stdin) for pipeline environments

### Maintainability

- NFR19: PHPStan Level 8 compliance with zero errors across all source code
- NFR20: Minimum 80% line coverage, 100% coverage for risk scoring and availability checking logic
- NFR21: New analyzers can be added without modifying existing code (plugin architecture via DI tags)
- NFR22: Project documentation sufficient for a new developer to set up, understand, and contribute within one day
