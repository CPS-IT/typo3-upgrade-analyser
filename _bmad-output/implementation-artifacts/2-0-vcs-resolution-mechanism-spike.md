# Story 2.0: VCS Resolution Mechanism Research Spike

Status: ready-for-dev

## Story

As a developer,
I want a timeboxed research spike validating Composer CLI behavior for VCS resolution,
so that Stories 2.2 and 2.3 are built on verified assumptions rather than documentation alone.

## Acceptance Criteria

1. **Given** a test environment with access to real VCS URLs (GitHub, GitLab, Bitbucket, or self-hosted), **when** the following Composer CLI commands are tested: `composer show -o -d /path --format=json`, `composer show -liD -d /path --format=json`, `composer show -a vendor/package --format=json`, **then** the spike document records: which command gives available versions + compatibility data, whether `--working-dir` passes auth from the target installation's `auth.json`, performance (batch vs per-package), and edge cases.
2. `git ls-remote --tags <url>` and `git ls-remote --heads <url>` are tested as Tier 2 fallback: output format, auth behavior via SSH agent, and tag-name-to-version-string parsing documented.
3. Composer PHP API (`VcsRepository`, `RepositoryManager`) is evaluated: stability across Composer 2.x, standalone instantiation against a foreign project's config, memory footprint.
4. Deliverable written to `documentation/implementation/development/feature/VcsResolutionSpike.md` covering benchmark results, recommended Composer command variant for Story 2.2, and go/no-go for `git ls-remote` as Story 2.3 fallback.
5. No production code produced. Spike is research and documentation only.
6. Document explicitly answers: can `composer show -o -d /path` short-circuit our own version comparison logic? Can it replace per-package N calls with one batch call?

## Tasks / Subtasks

- [ ] Task 1: Set up test environment (AC: 1, 2)
  - [ ] Identify 5–10 real VCS URLs spanning GitHub, GitLab, Bitbucket and at least one self-hosted or SSH URL
  - [ ] Use an existing TYPO3 Composer installation as the `--working-dir` target (real `composer.lock` with VCS sources preferred)
  - [ ] Confirm `auth.json` setup for at least one private repo if available

- [ ] Task 2: Benchmark Composer CLI commands (AC: 1, 6)
  - [ ] Run `composer show -o -d /path --format=json` — record: fields present, whether it covers VCS-sourced packages, network triggered?, lock file required?
  - [ ] Run `composer show -liD -d /path --format=json` — same checks
  - [ ] Run `composer show -a vendor/package --format=json` for 3–5 packages — record: all versions listed, constraint data present?, timing
  - [ ] Compare batch (`-o`, `-liD`) vs per-package (`-a`) in terms of data completeness and wall time for 10 and 40 packages (warm cache vs cold)
  - [ ] Document whether `--working-dir` activates the target installation's `repositories` config and `auth.json`
  - [ ] Test `COMPOSER_AUTH` env var as fallback if `--working-dir` does not bridge auth

- [ ] Task 3: Test git CLI fallback (AC: 2)
  - [ ] Run `git ls-remote --tags <url>` against 3–5 VCS URLs
  - [ ] Document raw tag output format and write a tag-name parser rule covering `v1.2.3`, `1.2.3`, `1.0.0-RC1`, `dev-main` patterns
  - [ ] Test via SSH agent auth (no tool-specific token)
  - [ ] Record average wall time per URL

- [ ] Task 4: Evaluate Composer PHP API (AC: 3)
  - [ ] Check `composer/composer` package stability for `VcsRepository` and `RepositoryManager` across 2.5–2.8
  - [ ] Determine if standalone instantiation without a full Composer bootstrap is feasible
  - [ ] Estimate memory footprint for 40-package resolution

- [ ] Task 5: Write deliverable document (AC: 4, 5, 6)
  - [ ] Create `documentation/implementation/development/feature/VcsResolutionSpike.md`
  - [ ] Include benchmark table (command × metric × result)
  - [ ] State recommended approach for Story 2.2 (specific CLI command and flags)
  - [ ] State go/no-go for Story 2.3 `git ls-remote` fallback with reasoning
  - [ ] Note any constraints discovered (e.g. lock file required, network required, Composer version minimum)

## Dev Notes

### Purpose and Scope

This is a pure research task. No source files under `src/` are modified. The only output is `documentation/implementation/development/feature/VcsResolutionSpike.md`. No tests, no PHPStan run required.

The spike unblocks:
- **Story 2.2** — exact Composer CLI command variant and auth mechanism are spike outputs
- **Story 2.3** — go/no-go on `git ls-remote` as viable Tier 2 fallback is a spike output

### What Is Being Replaced

The existing `GitProvider/` subsystem (to be deleted in Story 2.6) is the reference for what capabilities the new approach must match:

| Current class | Capability | New approach |
|---|---|---|
| `GitHubClient` (383 LOC) | GitHub GraphQL + REST version queries | `ComposerVcsResolver` + `GenericGitResolver` |
| `GitProviderFactory` | Provider URL routing | Eliminated — Composer handles routing |
| `AbstractGitProvider` | Rate limiting, auth, retry | Eliminated — Composer handles auth |
| `GitVersionParser` | Tag-to-version parsing | Reused or re-implemented in `GenericGitResolver` |

Current source files for reference (do NOT modify):
- `src/Infrastructure/ExternalTool/GitProvider/GitHubClient.php` — GraphQL queries show what data is currently extracted
- `src/Infrastructure/ExternalTool/GitVersionParser.php` — existing tag-to-version parsing logic; evaluate reuse in Tier 2

### Architecture Decisions Already Made

These are fixed — the spike validates implementation details, not the approach itself:

- **Tier 1:** `ComposerVcsResolver` uses `composer show` with `--working-dir` pointing to the analyzed installation. Class lives in `src/Infrastructure/ExternalTool/`.
- **Tier 2:** `GenericGitResolver` uses `git ls-remote --tags`. Class lives in `src/Infrastructure/ExternalTool/`.
- **Auth:** No tool-specific tokens (no `GITHUB_TOKEN`, no `GITLAB_TOKEN`). Auth via `auth.json` (Tier 1) and SSH agent (Tier 2).
- **DeclaredRepository VO:** Simplified — `url` and `packages` only, no `type` field. Lives in `src/Infrastructure/ExternalTool/`. Class is NEW (not yet created).
- **Failure semantics:** Tier 1 fail → pass to Tier 2. Both fail → emit Console WARNING, record `null` (not `false`).

[Source: `_bmad-output/planning-artifacts/architecture.md` — Git Source Detection & Version Availability]

### Key Questions the Spike Must Definitively Answer

1. **Batch vs per-package:** Does `composer show -o -d /path --format=json` cover VCS-sourced packages (not just Packagist)? If yes, one call replaces N per-package calls.
2. **Auth passthrough:** Does `--working-dir` activate the target installation's `auth.json`? If not, what is the correct workaround?
3. **Version data sufficiency:** Does the Composer CLI output include available version list and TYPO3 constraint fields (`require` block) needed for compatibility matching?
4. **`git ls-remote` viability:** Is it fast enough for 40-extension installations (<5 min total, NFR1)? Does SSH agent auth work without additional config?

### Project Structure Notes

- Deliverable file: `documentation/implementation/development/feature/VcsResolutionSpike.md` (new file, following the pattern of `GitRepositoryVersionSupport.md` in same directory)
- No changes to `src/`, `tests/`, `config/`, or `resources/`
- No `composer.json` changes

### References

- [Source: `_bmad-output/planning-artifacts/epics.md` — Epic 2, Story 2.0]
- [Source: `_bmad-output/planning-artifacts/architecture.md` — Git Source Detection & Version Availability]
- [Source: `_bmad-output/planning-artifacts/sprint-change-proposal-2026-03-26.md` — Section 4C, Story 2.0 research questions]
- [Source: `src/Infrastructure/ExternalTool/GitVersionParser.php` — existing tag parsing logic, candidate for reuse in Tier 2]

## Dev Agent Record

### Agent Model Used

<!-- to be filled by dev agent -->

### Debug Log References

### Completion Notes List

### File List

- `documentation/implementation/development/feature/VcsResolutionSpike.md` (new)
