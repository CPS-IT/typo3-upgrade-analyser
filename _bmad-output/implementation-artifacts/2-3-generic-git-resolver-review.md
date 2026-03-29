# Adversarial Review: GenericGitResolver (Story 2-3)

**Date:** 2026-03-28
**Reviewer:** Claude Sonnet 4.6 (adversarial review skill)
**Content reviewed:**
- `src/Infrastructure/ExternalTool/GenericGitResolver.php`
- `tests/Unit/Infrastructure/ExternalTool/GenericGitResolverTest.php`
- `_bmad-output/implementation-artifacts/2-3-generic-git-resolver.md`

---

## Findings

1. **`$packageName` is a dead parameter in `resolve()`.** It is accepted by the public API, passed nowhere, and ignored entirely. Every caller wastes a lookup to produce a value that is silently discarded. Either use it (e.g., in log messages alongside `$vcsUrl`) or remove it. If it is part of a planned interface contract, document that explicitly rather than leaving a ghost parameter.

2. **`runLsRemote` omits `-q`, so `git ls-remote` may print `From <url>` to stdout on some git versions and remotes.** The current parser filters by `refs/tags/` so this line is ignored in practice, but the contract is fragile: the parser accidentally works rather than explicitly handling this. Add `-q` to be explicit and future-proof.

3. **Critical bug: v-prefixed tag names are stored without the `v`, then used verbatim as git refs in `fetchComposerJson`.**
   - `parseTagsFromOutput` captures group 1 of `/^v?(\d+\.\d+\.\d+...)$/`, stripping the `v`. For a repo tag `v2.3.4`, the stored version is `2.3.4`.
   - `fetchComposerJson` constructs `refs/tags/2.3.4` — which does not exist in the repo. The actual git ref is `refs/tags/v2.3.4`.
   - `git archive` exits non-zero → treated as compatible → compatibility check is silently bypassed for every v-prefixed repository.
   - The tag parsing variant test (`testTagParsingVariants`) verifies `2.3.4` is selected from `v2.3.4`, but no test verifies that `git archive` is invoked with the correct original ref. The bug is invisible in the test suite.
   - Fix: store both the original tag name (for git operations) and the normalised version string (for `version_compare`).

4. **`fetchComposerJson` is structurally untestable because `createArchiveProcess` is not injectable.** The test `testResolvedCompatibleWhenArchiveFailsTreatsAsCompatible` does not test "archive fails → compatible." It tests "archive fails in the real git environment during CI," which is an environment assumption disguised as a unit test. If git is installed and the URL resolves differently, the test silently passes for the wrong reason. This is not a unit test — it is an integration test sitting in the unit test directory.

5. **Test comment at line 149 is factually wrong.** It reads: "findTypo3Requirements never called because fetchComposerJson returns null." It is called. When `fetchComposerJson` returns null, `$requires` is null, `isCompatible(null, ...)` calls `findTypo3Requirements([])`. The stub returning `[]` determines the outcome, not the non-call of the method.

6. **No interface for `GenericGitResolver`.** The codebase uses interfaces for all injectable collaborators (`ComposerConstraintCheckerInterface`). `GenericGitResolver` is a concrete class with no interface. Story 2.5 (the caller) will depend on the concrete class, violating DIP and making caller-side testing harder. `PackagistVersionResolver` also lacks an interface — that is a pre-existing debt, not an excuse to repeat it.

7. **`RESOLVED_NO_MATCH` conflates two semantically distinct outcomes:** (a) ls-remote succeeded but returned no parseable semver tags, and (b) ls-remote returned semver tags but none are compatible. The caller (Story 2.5) cannot distinguish between "this extension is not versioned with semver" and "this extension is versioned but not TYPO3-13 compatible." These require different user-facing messages and potentially different handling logic.

8. **`shouldTryFallback()` returns `true` for `FAILURE` on the last-resort tier.** The method is designed for the Packagist → Git cascade. When `GenericGitResolver` returns `FAILURE`, there is no further tier to try. The method returning `true` is architecturally misleading and will require the Story 2.5 caller to check the resolver tier explicitly to avoid a dead code path.

9. **Hierarchical git tag names (`releases/1.0.0`) produce unintended matches.** `strrpos($line, '/')` on `abc123\trefs/tags/releases/1.0.0` returns `1.0.0`, which passes the semver regex. The parser silently accepts `releases/1.0.0` as version `1.0.0`. The parser's assumption that "last path segment = tag name" is undocumented and untested.

10. **Malformed JSON from `git archive` is silently treated as compatible.** A WARNING is logged but the return value is null, and null is treated as compatible. A malformed `composer.json` most likely indicates a broken or mis-archived state, not a valid extension with no TYPO3 constraint. The spec collapses two distinct failure modes — archive unavailable vs. content corrupt — into the same outcome without explicit risk acceptance justification.

11. **`readonly class` syntax requires PHP 8.2, but the project's declared minimum is PHP 8.1.** The suggestion to make the class `readonly` is conceptually correct (all constructor properties are already `readonly`), but cannot be applied without bumping `composer.json`'s PHP requirement or accepting a silent runtime failure on 8.1.
