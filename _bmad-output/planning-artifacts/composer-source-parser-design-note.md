# ComposerSourceParser Design Note

**Story:** P2-3 — Discovery Spike: GitLab/Bitbucket Composer Source Entry Fixtures
**Date:** 2026-03-25
**Status:** Final — drives Story 2.1 implementation

---

## 1. Primary Identification Field

`ComposerSourceParser` reads `source.url` from each entry in `composer.lock`'s `packages[]` array.

The `source.url` domain is the **single primary identification field** for Git-hosted providers.

### Why not `source.type`?

Composer always writes `"type": "git"` for all VCS repositories regardless of provider. It is not diagnostic. The only exception is `"type": "path"` for local path repositories — see section 4.

### Why not `repositories[].url` from `composer.json`?

`repositories[].url` is the *declared* source, not the *resolved* source. After cloning, Composer normalises URLs (e.g. adds `.git` suffix). The `composer.lock` `source.url` is always authoritative. Use `composer.json` `repositories[].url` only as a fallback for packages that are listed in `require` but absent from `composer.lock` (unusual; treat as a warning).

---

## 2. Known Public Host Matching

| Domain | Provider |
|--------|----------|
| `gitlab.com` | GitLab SaaS |
| `bitbucket.org` | Bitbucket |
| `github.com` | GitHub (existing — not in scope of this spike) |

Matching is **exact domain match** on the hostname extracted from `source.url`. No suffix matching, no heuristics.

Implementation pattern:

```php
$host = parse_url($sourceUrl, PHP_URL_HOST);
match ($host) {
    'gitlab.com'    => ProviderType::GitLabSaas,
    'bitbucket.org' => ProviderType::Bitbucket,
    'github.com'    => ProviderType::GitHub,
    default         => null, // self-hosted or unmatched
};
```

---

## 3. Self-Hosted GitLab

Self-hosted GitLab instances use arbitrary custom domains (e.g. `git.example.com`, `gitlab.corp:8443`). There is no reliable heuristic to distinguish a self-hosted GitLab from any other Git server by URL alone.

**Decision:** The analyser's `.typo3-analyzer.yaml` configuration must explicitly declare custom GitLab domains:

```yaml
git_providers:
  gitlab_instances:
    - git.example.com
    - gitlab.corp
```

`ComposerSourceParser` passes the extracted host to a `ProviderResolver` that checks:
1. Known public hosts (section 2) — first.
2. Configured `gitlab_instances` list — second.
3. No match → unmatched source; emit a Console WARNING (not silent skip, per architecture constraints).

The fixture `GitLabSelfHosted/` uses `git.example.com` as the representative custom domain.

---

## 4. Edge Cases

### 4.1 Path Repositories (`"type": "path"`)

`source.type = "path"` is the one case where `source.type` is diagnostic. Path repositories are local filesystem packages and must not be sent to any remote API.

`ComposerSourceParser` must check `source.type` before domain matching:

```php
if ($package['source']['type'] === 'path') {
    // local package — skip provider resolution
    continue;
}
```

Fixture: `tests/Fixtures/ComposerSources/` does not include a path-type fixture — this is handled inline in Story 2.1 per the gap analysis recommendation.

### 4.2 SSH URLs

Composer can record SSH URLs: `git@gitlab.com:myvendor/ext.git`.

`parse_url()` returns `null` for `PHP_URL_HOST` on SSH URLs (the `git@` prefix is not a valid scheme). `ComposerSourceParser` must handle this:

```php
$host = parse_url($sourceUrl, PHP_URL_HOST);
if ($host === null) {
    // SSH URL — extract host from "user@host:path" pattern
    if (preg_match('/^[^@]+@([^:]+):/', $sourceUrl, $m)) {
        $host = $m[1];
    }
}
```

### 4.3 Non-Standard Ports (Self-Hosted)

Self-hosted instances may include port numbers: `https://git.example.com:8443/vendor/ext.git`.

`parse_url($url, PHP_URL_HOST)` returns only the hostname without port. Provider configuration must match on hostname only (no port). This is correct default behaviour.

### 4.4 Bitbucket with SSH URL

SSH remote for Bitbucket: `git@bitbucket.org:myvendor/ext.git`. The SSH host extraction (section 4.2) correctly yields `bitbucket.org`, which matches the known public host.

### 4.5 Missing `source` Key (Dist-Only Packages)

Some packages in `composer.lock` or `installed.json` have no `source` block — they were installed from a dist tarball (zip/tar) rather than a VCS checkout. This happens for:
- Packagist packages installed without `--prefer-source`
- Packages using `"type": "artifact"` repositories
- Any package where Composer used the `dist` key only

`ComposerSourceParser` must skip any package entry where `source` is absent:

```php
if (!isset($package['source'])) {
    // dist-only install — no VCS source to resolve
    continue;
}
```

This is **not** a warning condition. Dist-only packages are the normal case for Packagist-sourced dependencies. Only packages with a `source` block are candidates for provider resolution.

### 4.6 Public vs Private Distinction

Public and private repositories are **structurally identical** in `composer.lock` and `installed.json`. The `source.url` domain, path, and `source.reference` carry no privacy signal.

The `GitLabProvider` and `BitbucketProvider` (Stories 2.2 and 2.3) must attempt unauthenticated API access first, then retry with a configured token if the response is 401/403. This is a provider-level responsibility, not a parser-level one.

`ComposerSourceParser` does not distinguish public from private — it returns a `DeclaredRepository` with `url` and `type` only.

---

## 5. `packages-dev` Scanning Decision

**Decision: `ComposerSourceParser` must scan both `packages` and `packages-dev` arrays.**

Rationale: A TYPO3 project may legitimately host a private extension as a dev dependency (e.g. a project-specific extension only needed in development, or a fork of a public extension used in CI). If the analyser only scans `packages`, dev-only VCS sources are silently ignored and the user receives an incomplete upgrade report.

The parser iterates over `array_merge($data['packages'], $data['packages-dev'] ?? [])` and applies the same source-resolution logic to both.

This decision does **not** affect the `DeclaredRepository` VO — no distinction between prod and dev is carried into the value object. If that distinction becomes relevant (e.g. for effort-scoring), it can be added as an optional field in Story 2.1 or later.

---

## 6. `DeclaredRepository` Value Object Fields

Per `_bmad-output/planning-artifacts/architecture.md`:

```php
// Infrastructure/ExternalTool/DeclaredRepository.php
final class DeclaredRepository
{
    public function __construct(
        public readonly string $url,
        public readonly string $type,   // 'gitlab-saas' | 'bitbucket' | 'github' | 'gitlab-self-hosted' | 'unmatched'
        /** @var array<string> */
        public readonly array $packages,
    ) {}
}
```

`type` is a resolved provider type string, not the raw `source.type` from Composer.

---

## 7. Provider Resolution Order

1. Check `source` key absent → skip silently (dist-only package, see section 4.5).
2. Check `source.type === 'path'` → skip (local package, see section 4.1).
3. Extract hostname from `source.url` (handle SSH URLs, see section 4.2).
4. Match against known public hosts (`gitlab.com`, `bitbucket.org`, `github.com`).
5. Match against configured `gitlab_instances` list from `.typo3-analyzer.yaml`.
6. No match → type = `'unmatched'`, emit Console WARNING.

---

## 8. Fields Used as Primary vs Fallback

| Context | Field | Role |
|---------|-------|------|
| Package already in `composer.lock` | `packages[].source.url` | **Primary** |
| Package in `require` but missing from `composer.lock` | `repositories[].url` in `composer.json` | **Fallback** (uncommon) |
| `vendor/composer/installed.json` | `packages[].source.url` | Cross-check only; same data as lock file |
