# Fixture Coverage Gap Analysis for Epic 2

**Story:** P2-2
**Date:** 2026-03-25
**Purpose:** Identify which `composer.json` source entry formats lack test fixtures before Story 2.1 (ComposerSourceParser) begins.

---

## 1. Currently Covered Fixture Types

### 1.1 tests/Fixtures/ (top-level)

These fixtures support parser and configuration tests. None contain `composer.json` with a `repositories` section.

| Path                                                  | What it covers                     |
|-------------------------------------------------------|------------------------------------|
| `tests/Fixtures/Configuration/LocalConfiguration.php` | PHP config parsing                 |
| `tests/Fixtures/Configuration/PackageStates.php`      | PackageStates parsing              |
| `tests/Fixtures/Configuration/Services.yaml`          | YAML config parsing                |
| `tests/Fixtures/Configuration/SiteConfiguration.yaml` | Site config parsing                |
| `tests/Fixtures/Configuration/InvalidSyntax.php`      | Malformed PHP parse errors         |
| `tests/Fixtures/Configuration/InvalidSyntax.yaml`     | Malformed YAML parse errors        |
| `tests/Fixtures/public/typo3conf/ext/test_extension/` | Extension in public/typo3conf path |
| `tests/Fixtures/test_extension/`                      | Extension without path prefix      |
| `tests/Fixtures/typo3conf/ext/test_extension/`        | Extension in legacy typo3conf path |
| `tests/Fixtures/fractor/t3events_reservation.txt`     | Fractor analysis output            |

No `repositories` coverage in this directory.

### 1.2 tests/Integration/Fixtures/TYPO3Installations/

Each installation fixture has a `composer.json`. None has a `repositories` key. All `composer.lock` source URLs point to `github.com`.

| Directory                    | TYPO3 Version | composer.json | composer.lock | installed.json | `repositories` key | Source URL domain |
|------------------------------|---------------|:-------------:|:-------------:|:--------------:|:------------------:|-------------------|
| `ComposerInstallation/`      | v12           |       ✓       |       ✓       |       ✓        |         —          | github.com only   |
| `v11ComposerAppVendor/`      | v11           |       ✓       |       —       |       ✓        |         —          | —                 |
| `v11ComposerCustomWebDir/`   | v11           |       ✓       |       —       |       —        |         —          | —                 |
| `v12Composer/`               | v12           |       ✓       |       ✓       |       ✓        |         —          | github.com only   |
| `v12ComposerCustomBothDirs/` | v12           |       ✓       |       —       |       ✓        |         —          | —                 |
| `v12ComposerCustomWebDir/`   | v12           |       ✓       |       ✓       |       ✓        |         —          | github.com only   |
| `v13Composer/`               | v13           |       ✓       |       ✓       |       ✓        |         —          | github.com only   |
| `v13ComposerCustomWebDir/`   | v13           |       ✓       |       ✓       |       ✓        |         —          | github.com only   |
| `v14Composer/`               | v14           |       ✓       |       ✓       |       ✓        |         —          | github.com only   |
| `v14ComposerCustomWebDir/`   | v14           |       ✓       |       ✓       |       ✓        |         —          | github.com only   |
| `LegacyInstallation/`        | legacy        |       —       |       —       |       —        |         —          | —                 |
| `v11LegacyInstallation/`     | v11           |       —       |       —       |       —        |         —          | —                 |
| `BrokenInstallation/`        | —             |       —       |       —       |  ✓ (partial)   |         —          | —                 |

**Confirmed:** v11, v12, v13, v14 Composer installation detection and legacy path detection are covered by integration tests. The gap is exclusively in `repositories` entry coverage and non-GitHub source URLs.

---

## 2. Source Format Evaluation for Epic 2

### 2.1 Entry Types Required per Story

| Story                    | What it parses                                          | Source format required                                                         |
|--------------------------|---------------------------------------------------------|--------------------------------------------------------------------------------|
| 2.1 ComposerSourceParser | `composer.json` `repositories[]`                        | `vcs` entries for all providers; `path` entries; absence of `repositories` key |
| 2.2 GitLabProvider       | Source URL domain in `composer.lock` / `installed.json` | `gitlab.com`, custom self-hosted domain                                        |
| 2.3 BitbucketProvider    | Source URL domain in `composer.lock` / `installed.json` | `bitbucket.org`                                                                |
| 2.4 Unmatched Warning    | Source URL not matching any known provider              | Any unrecognised domain                                                        |

### 2.2 Coverage Status per Format

| Format                           | AC ref          | Fixture path                                     | Covered?    | Notes                                                                               |
|----------------------------------|-----------------|--------------------------------------------------|-------------|-------------------------------------------------------------------------------------|
| GitHub public (packagist / dist) | 2.1             | all existing `composer.lock` fixtures            | **Partial** | Source URL present; no `repositories` entry in `composer.json`                      |
| GitHub private (VCS entry)       | 2.1, 2.2        | —                                                | **Missing** | No fixture with `repositories[].url = github.com` and `source.url = github.com`     |
| GitLab SaaS public               | 2.1, 2.2        | —                                                | **Missing** | No `gitlab.com` source URL anywhere                                                 |
| GitLab SaaS private              | 2.1, 2.2        | —                                                | **Missing** | Structurally identical to public in `composer.lock`; auth differs at API level only |
| GitLab self-hosted               | 2.1, 2.2        | —                                                | **Missing** | Custom domain; heuristic-free identification requires explicit provider config      |
| Bitbucket public                 | 2.1, 2.3        | —                                                | **Missing** | No `bitbucket.org` source URL anywhere                                              |
| Bitbucket private                | 2.1, 2.3        | —                                                | **Missing** | Structurally identical to public in `composer.lock`; auth differs at API level only |
| `path` type (local package)      | 2.1, issue #149 | —                                                | **Missing** | No `"type": "path"` entry in any `repositories` section                             |
| Unmatched domain                 | 2.4             | —                                                | **Missing** | No fixture with unrecognised VCS URL                                                |
| Missing `repositories` key       | 2.1             | all existing `composer.json`                     | **Covered** | Trivially: no `repositories` key at all                                             |
| Empty `repositories` array       | 2.1             | —                                                | **Missing** | Edge case: key present but empty array                                              |
| Malformed `composer.json`        | 2.1             | `tests/Fixtures/Configuration/InvalidSyntax.php` | **Partial** | Invalid PHP covered; invalid JSON not — needs dedicated JSON fixture                |

---

## 3. Minimal Fixture Structure per Missing Format

All fixtures follow the three-file pattern: `composer.json` (repositories declaration), `composer.lock` (resolved source), `vendor/composer/installed.json` (installed packages). Proposed location: `tests/Fixtures/ComposerSources/<TypeDir>/`.

> Note on `source.type`: Composer always writes `"type": "git"` for VCS repos. Provider identity must be inferred from `source.url` domain, not `source.type`.

### 3.1 GitHub Private VCS Entry

**composer.json**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/myvendor/private-extension"
        }
    ],
    "require": {
        "myvendor/private-extension": "^2.0"
    }
}
```

**composer.lock** (packages entry)
```json
{
    "name": "myvendor/private-extension",
    "version": "2.1.0",
    "source": {
        "type": "git",
        "url": "https://github.com/myvendor/private-extension.git",
        "reference": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2"
    }
}
```

**vendor/composer/installed.json** — same `source` block, plus `"installation-source": "source"`.

### 3.2 GitLab SaaS Public

**composer.json**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://gitlab.com/myvendor/typo3-ext-news"
        }
    ],
    "require": {
        "myvendor/typo3-ext-news": "^3.0"
    }
}
```

**composer.lock** (packages entry)
```json
{
    "name": "myvendor/typo3-ext-news",
    "version": "3.0.1",
    "source": {
        "type": "git",
        "url": "https://gitlab.com/myvendor/typo3-ext-news.git",
        "reference": "b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3"
    }
}
```

### 3.3 GitLab SaaS Private

Identical URL structure to public in `composer.lock`. A dedicated fixture directory makes the intent explicit for Story 2.2 unit tests (unauthenticated vs token-based API call).

**composer.json**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://gitlab.com/myvendor/private-typo3-ext"
        }
    ],
    "require": {
        "myvendor/private-typo3-ext": "^1.5"
    }
}
```

**composer.lock** (packages entry) — source URL is `https://gitlab.com/myvendor/private-typo3-ext.git`. Structurally indistinguishable from public at the file level; the distinction is runtime only (token presence).

### 3.4 GitLab Self-Hosted

**composer.json**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://git.example.com/myvendor/internal-ext"
        }
    ],
    "require": {
        "myvendor/internal-ext": "^1.0"
    }
}
```

**composer.lock** (packages entry)
```json
{
    "name": "myvendor/internal-ext",
    "version": "1.0.0",
    "source": {
        "type": "git",
        "url": "https://git.example.com/myvendor/internal-ext.git",
        "reference": "c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4"
    }
}
```

Note: Provider identification for self-hosted requires the analysed installation's `.typo3-analyzer.yaml` to declare `git.example.com` as a GitLab instance. The fixture alone is insufficient — the spike (P2-3) must also establish the design decision for how self-hosted domains are matched.

### 3.5 Bitbucket Public

**composer.json**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://bitbucket.org/myvendor/typo3-form-ext"
        }
    ],
    "require": {
        "myvendor/typo3-form-ext": "^2.0"
    }
}
```

**composer.lock** (packages entry)
```json
{
    "name": "myvendor/typo3-form-ext",
    "version": "2.0.3",
    "source": {
        "type": "git",
        "url": "https://bitbucket.org/myvendor/typo3-form-ext.git",
        "reference": "d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5"
    }
}
```

### 3.6 Bitbucket Private

Same URL pattern as public. Token-based auth at runtime only.

**composer.json**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://bitbucket.org/myvendor/private-typo3-ext"
        }
    ],
    "require": {
        "myvendor/private-typo3-ext": "^1.0"
    }
}
```

**composer.lock** — source URL `https://bitbucket.org/myvendor/private-typo3-ext.git`.

### 3.7 `path` Type (Local Package)

**composer.json**
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../local-packages/myvendor-local-ext"
        }
    ],
    "require": {
        "myvendor/local-ext": "*"
    }
}
```

**composer.lock** (packages entry)
```json
{
    "name": "myvendor/local-ext",
    "version": "dev-main",
    "source": {
        "type": "path",
        "url": "../local-packages/myvendor-local-ext",
        "reference": "abc123def456abc123def456abc123def456abc1"
    },
    "dist": {
        "type": "path",
        "url": "../local-packages/myvendor-local-ext",
        "reference": ""
    }
}
```

`source.type` is `"path"` (not `"git"`) for path repositories — this is the one case where `source.type` is diagnostic.

### 3.8 Unmatched Domain

**composer.json**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://code.internal-forge.example.org/myvendor/secret-ext"
        }
    ],
    "require": {
        "myvendor/secret-ext": "^1.0"
    }
}
```

**composer.lock** (packages entry) — source URL matches the unrecognised domain.

### 3.9 Empty `repositories` Array

**composer.json**
```json
{
    "repositories": [],
    "require": {
        "typo3/cms-core": "^13.4"
    }
}
```

No `composer.lock` entry required — this is a parser edge case only.

### 3.10 Malformed `composer.json` (Invalid JSON)

A plain text file with invalid JSON content:
```
{ "repositories": [ INVALID JSON HERE }
```

Location: `tests/Fixtures/ComposerSources/MalformedJson/composer.json` (or reuse `tests/Fixtures/Configuration/InvalidSyntax.*` pattern).

---

## 4. Recommendation: Story 2.1 Inline vs Spike P2-3

| Format                     | Recommended path     | Rationale                                                                                                                            |
|----------------------------|----------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| GitHub private VCS entry   | **Story 2.1 inline** | Same domain as existing fixtures; `GitHubClient` already handles auth; simple file addition                                          |
| `path` type                | **Story 2.1 inline** | Well-defined Composer format; no API interaction; `source.type = "path"` is unambiguous                                              |
| Empty `repositories` array | **Story 2.1 inline** | Parser edge case; trivially small fixture                                                                                            |
| Malformed `composer.json`  | **Story 2.1 inline** | Already handled by existing `InvalidSyntax` pattern; one small file                                                                  |
| Unmatched domain           | **Story 2.1 inline** | Just a VCS entry with an unknown domain; Story 2.4 parser test requires it                                                           |
| GitLab SaaS public         | **Spike P2-3**       | URL pattern confirmed (`gitlab.com`), but API shape and design note needed for GitLabProvider (Story 2.2)                            |
| GitLab SaaS private        | **Spike P2-3**       | Same URL structure as public; fixture exists to document auth boundary for Story 2.2 unit tests                                      |
| GitLab self-hosted         | **Spike P2-3**       | Critical design decision: how does the parser identify `git.example.com` as GitLab? Must be documented before 2.1 hardcodes anything |
| Bitbucket public           | **Spike P2-3**       | `bitbucket.org` domain clear; URL format and API shape need verification                                                             |
| Bitbucket private          | **Spike P2-3**       | Auth-only distinction; document boundary explicitly for Story 2.3 unit tests                                                         |

**Summary:** 5 formats can be created inline in Story 2.1 without design decisions. 5 formats require P2-3 because they drive architectural choices in `GitLabProvider` and `BitbucketProvider` that must be settled before Story 2.1 hardcodes any pattern-matching logic.

---

## 5. v11/v12/v13/v14 Composer and Legacy Coverage Confirmation

Integration tests that exercise discovery across versions reference `tests/Integration/Fixtures/TYPO3Installations/`:

| Coverage area                   | Test file                                                   | Fixtures used                |
|---------------------------------|-------------------------------------------------------------|------------------------------|
| v11 Composer (app/vendor)       | `Typo3V13ComposerDiscoveryTest.php` (also covers v11 paths) | `v11ComposerAppVendor/`      |
| v11 Composer (custom web dir)   | `ExtensionDiscoveryWorkflowIntegrationTestCase.php`         | `v11ComposerCustomWebDir/`   |
| v12 Composer (standard)         | `ExtensionDiscoveryWorkflowIntegrationTestCase.php`         | `v12Composer/`               |
| v12 Composer (custom web dir)   | `ExtensionDiscoveryWorkflowIntegrationTestCase.php`         | `v12ComposerCustomWebDir/`   |
| v12 Composer (custom both dirs) | `ExtensionDiscoveryWorkflowIntegrationTestCase.php`         | `v12ComposerCustomBothDirs/` |
| v13 Composer (standard)         | `Typo3V13ComposerDiscoveryTest.php`                         | `v13Composer/`               |
| v13 Composer (custom web dir)   | `Typo3V13ComposerDiscoveryTest.php`                         | `v13ComposerCustomWebDir/`   |
| v14 Composer (standard)         | `Typo3V14ComposerDiscoveryTest.php`                         | `v14Composer/`               |
| v14 Composer (custom web dir)   | `Typo3V14ComposerDiscoveryTest.php`                         | `v14ComposerCustomWebDir/`   |
| Legacy (older)                  | `ExtensionDiscoveryWorkflowIntegrationTestCase.php`         | `LegacyInstallation/`        |
| v11 Legacy                      | `ExtensionDiscoveryWorkflowIntegrationTestCase.php`         | `v11LegacyInstallation/`     |

**Conclusion:** v11–v14 Composer installation detection and legacy installation detection are fully covered by integration tests. No gaps in TYPO3 version coverage. The gap is exclusively in `repositories` section content within `composer.json` and non-GitHub `source.url` values in `composer.lock`.
