# ComposerSources Fixtures

Test fixtures for `ComposerSourceParser` (Story 2.1) and the GitLab/Bitbucket provider implementations (Stories 2.2, 2.3).

Each subdirectory represents one source type and contains three files:

| File | Purpose |
|------|---------|
| `composer.json` | Minimal project manifest with a `repositories` entry referencing the source |
| `composer.lock` | Lock file excerpt — `packages[0].source` shows `type`, `url`, `reference` |
| `installed.json` | `vendor/composer/installed.json` format — same `source` block, plus `installation-source` |

## Fixture Directories

| Directory | Source type | Primary domain |
|-----------|-------------|----------------|
| `GitLabSaasPublic/` | GitLab SaaS, public repository | `gitlab.com` |
| `GitLabSaasPrivate/` | GitLab SaaS, private repository | `gitlab.com` |
| `GitLabSelfHosted/` | GitLab self-hosted instance | `git.example.com` |
| `BitbucketPublic/` | Bitbucket, public repository | `bitbucket.org` |
| `BitbucketPrivate/` | Bitbucket, private repository | `bitbucket.org` |

## Key Design Facts

- `source.type` in `composer.lock` is always `"git"` for VCS repositories. Provider identity must be inferred from the `source.url` domain, **not** `source.type`.
- Public vs private repositories are structurally identical in `composer.lock` and `installed.json`. The distinction is runtime-only (authentication token presence).
- Self-hosted GitLab instances use custom domains. Provider identification requires explicit configuration in `.typo3-analyzer.yaml` mapping the custom domain to the GitLab provider.

See `_bmad-output/planning-artifacts/composer-source-parser-design-note.md` for the full design decisions.
