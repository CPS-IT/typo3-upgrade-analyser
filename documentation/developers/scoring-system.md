# Scoring System Overview

## Global Risk Score Domain

All analyzers produce a **risk score** on a 0.0 -- 10.0 scale, stored in `AnalysisResult`.

| Range     | Risk Level | Meaning                           |
|-----------|------------|-----------------------------------|
| 0.0 -- 2.0 | low        | Minimal upgrade friction expected |
| 2.0 -- 5.0 | medium     | Some work required                |
| 5.0 -- 8.0 | high       | Significant effort or blockers    |
| 8.0 -- 10.0| critical   | Major risk, may need alternatives |

**Source:** `src/Domain/Entity/AnalysisResult.php:65-85`

Validation enforces the 0.0-10.0 bounds (line 67). The `getRiskLevel()` method (line 78) maps score to level using the thresholds above.

---

## 1. VersionAvailabilityAnalyzer

**Source:** `src/Infrastructure/Analyzer/VersionAvailabilityAnalyzer.php:136-206`

### What it measures

Whether a compatible version of the extension exists in known distribution channels (TER, Packagist, VCS).

### Special cases

- **System extensions** always return risk 1.0 (maintained by TYPO3 core team, line 142).
- **Local/path extensions** return risk 1.0 and skip external checks (lines 64-71).

### Formula

Each enabled source contributes weighted points when available:

| Source    | Weight | Rationale                       |
|-----------|--------|---------------------------------|
| TER       | 4      | Official TYPO3 channel          |
| Packagist | 3      | Composer ecosystem standard     |
| VCS       | 2      | Binary: available (true) or not |

```
maxPossibleScore = sum(weights of enabled sources)
availabilityScore = sum(weights of sources where extension is found)

thresholdHigh   = maxPossibleScore * 0.66
thresholdMedium = maxPossibleScore * 0.44
thresholdLow    = maxPossibleScore * 0.22
```

Mapping to risk score:

| Condition                            | Risk Score |
|--------------------------------------|------------|
| availabilityScore >= thresholdHigh   | 1.5        |
| availabilityScore >= thresholdMedium | 2.5        |
| availabilityScore >= thresholdLow    | 5.0        |
| below thresholdLow or no sources     | 9.0        |

### Example (all 3 sources enabled, max = 9)

| Points earned | Risk |
|---------------|------|
| 6 -- 9        | 1.5  |
| 4 -- 5        | 2.5  |
| 2 -- 3        | 5.0  |
| 0 -- 1        | 9.0  |

### Verbal description

The analyzer checks whether a compatible version of the extension is published to any known source. More sources and more trusted sources (TER > Packagist > VCS) lower the risk. If the extension cannot be found anywhere, risk is near-maximum.

---

## 2. LinesOfCodeAnalyzer

**Source:** `src/Infrastructure/Analyzer/LinesOfCodeAnalyzer.php:244-282`

### What it measures

Codebase size and structural complexity as a proxy for upgrade effort.

### Formula

Additive score, starting at 0.0, capped at 10.0:

| Factor                     | Threshold        | Points |
|----------------------------|------------------|--------|
| Total lines of code        | > 10,000         | +3.0   |
|                            | > 5,000          | +2.0   |
|                            | > 2,000          | +1.0   |
| Largest file (lines)       | > 1,000          | +2.0   |
|                            | > 500            | +1.0   |
| Average file size (lines)  | > 300            | +1.0   |
| Methods+functions per file | > 20             | +1.0   |

```
riskScore = min(sum(applicable points), 10.0)
```

### Verbal description

Larger codebases require more testing, more refactoring effort, and carry higher probability of hidden breakage. This analyzer accumulates penalty points for volume (total LOC), concentration (large files), and density (high method count per file). The maximum reachable score is 7.0 under current thresholds.

---

## 3. Typo3RectorAnalyzer

**Source:** `src/Infrastructure/Analyzer/Typo3RectorAnalyzer.php:387-425`

### What it measures

Deprecated or breaking PHP code patterns detected by TYPO3 Rector rules.

### Risk Score Formula

```
base = 1.0

if totalFindings == 0:
    return 1.0

base += criticalIssues * 1.2
base += warnings * 0.6
base += infoIssues * 0.2
base += (fileImpactPercentage / 100) * 1.5

complexityMultiplier = 1 + (complexityScore / 20)
base *= complexityMultiplier

if effortHours > 16:   base += 2.0
elif effortHours > 8:  base += 1.0
elif effortHours > 4:  base += 0.5

riskScore = min(base, 10.0)
```

### Verbal description

Starts at a baseline of 1.0. Each finding adds risk proportional to its severity. The file impact percentage reflects how broadly changes are spread across the codebase. A complexity multiplier (1.0 -- 1.5) amplifies the base when the change landscape is diverse and requires manual intervention. Large estimated effort adds a final step penalty. Capped at 10.0.

---

## 4. FractorAnalyzer

**Source:** `src/Infrastructure/Analyzer/FractorAnalyzer.php:391-429`

Identical formula to the Rector analyzer (section 3). Measures deprecated or breaking patterns in non-PHP files (TypoScript, Fluid, YAML, etc.) using Fractor rules.

---

## 5. Complexity Score (Rector / Fractor shared)

**Source:** `src/Infrastructure/Analyzer/Rector/RectorResultParser.php:146-182` (Fractor equivalent is identical)

### Formula

Four weighted factors, each normalized to 0 -- 1, then scaled to 0 -- 10:

| Factor               | Weight | Calculation                              |
|----------------------|--------|------------------------------------------|
| Rule diversity       | 0.3    | min(uniqueRules / 10, 1.0)              |
| File spread          | 0.2    | min(uniqueFiles / 20, 1.0)              |
| Severity mix         | 0.3    | Shannon entropy of severity distribution, normalized by log2(4) = 2 |
| Manual intervention  | 0.2    | manualCount / totalFindings              |

```
totalComplexity = sum(factor_i * weight_i)
complexityScore = round(totalComplexity * 10, 1)
```

### Entropy calculation

**Source:** `RectorResultParser.php:286-305`

```
entropy = -sum(p_i * log2(p_i))  for each severity level with count > 0
normalizedEntropy = entropy / 2   (max entropy for 4 categories)
```

### Verbal description

Complexity rises when many different rules trigger (diverse changes), many files are affected (broad spread), severity levels are evenly distributed (no single dominant category), and a high proportion of findings require manual intervention. The entropy component specifically captures whether the upgrade work is concentrated (low entropy, simpler planning) or scattered across severity levels (high entropy, harder to prioritize).

---

## 6. Upgrade Readiness Score (Rector / Fractor shared)

**Source:** `src/Infrastructure/Analyzer/Rector/RectorAnalysisSummary.php:202-241`

### Formula

Starts at 10.0 (fully ready), penalized downward:

```
score = 10.0
score -= criticalIssues * 0.8
score -= warnings * 0.3
score -= infoIssues * 0.1
score -= complexityScore / 2
score -= (fileImpactPercentage / 100) * 2

upgradeReadiness = clamp(score, 1.0, 10.0)
```

Risk level mapping (inverse of the global mapping because higher = better here):

| Readiness | Level    |
|-----------|----------|
| >= 8.0    | low      |
| >= 6.0    | medium   |
| >= 3.0    | high     |
| < 3.0     | critical |

### Verbal description

A top-down score: assume the extension is fully ready, then subtract penalties for each problem found. Critical issues have the highest penalty weight, followed by warnings and info issues. Complexity and file impact further reduce readiness. This score is used internally by the Rector/Fractor analyzers for recommendation generation; it is not the same as the AnalysisResult risk score.

---

## 7. Effort Estimation

### Per-finding effort (minutes)

**Source:** `src/Infrastructure/Analyzer/Rector/RectorChangeType.php:71-86`

| Change Type          | Minutes | Manual Intervention |
|----------------------|---------|---------------------|
| BREAKING_CHANGE      | 60      | yes                 |
| CLASS_REMOVAL        | 45      | yes                 |
| INTERFACE_CHANGE     | 30      | yes                 |
| METHOD_SIGNATURE     | 20      | yes                 |
| CONFIGURATION_CHANGE | 15      | yes                 |
| SECURITY             | 25      | no                  |
| PERFORMANCE          | 12      | no                  |
| DEPRECATION          | 10      | no                  |
| BEST_PRACTICE        | 8       | no                  |
| ANNOTATION_CHANGE    | 5       | no                  |
| CODE_STYLE           | 3       | no                  |

Fractor uses the same model minus CLASS_REMOVAL and METHOD_SIGNATURE.

### Aggregation

**Source:** `RectorResultParser.php:270-279`

```
totalMinutes = sum(finding.getEstimatedEffort() for each finding)
totalHours = round(totalMinutes / 60, 1)
```

---

## 8. Severity Weights

**Source:** `src/Infrastructure/Analyzer/Rector/RectorRuleSeverity.php:28-36` (Fractor identical)

| Severity   | Risk Weight | Description                              |
|------------|-------------|------------------------------------------|
| CRITICAL   | 1.0         | Breaking changes blocking upgrade        |
| WARNING    | 0.6         | Deprecations that will break in future   |
| INFO       | 0.2         | Best practices and improvements          |
| SUGGESTION | 0.1         | Optional optimizations                   |

These weights are used by the rule registry for per-finding risk calculation. They differ from the multipliers used in the analyzer-level risk score (section 3), where critical issues contribute 1.2 per occurrence rather than 1.0.

---

## 9. Extension-Level Aggregation

**Source:** `src/Infrastructure/Reporting/ReportContextBuilder.php:135-167`

### Formula

```
avgRisk = sum(riskScores from all analyzers) / count(riskScores)
maxRisk = max(riskScores)
riskLevel = thresholdMapping(avgRisk)   // same 0-2-5-8 thresholds
```

If all analyzers failed: `overall_risk = 10.0, risk_level = "critical"`.
If no analyzers ran: `overall_risk = 0.0, risk_level = "unknown"`.

### Verbal description

Each extension gets one risk score per analyzer. The overall extension risk is the arithmetic mean of all successful analyzer scores. The maximum score is tracked separately but not used for the risk level classification.

---

## 10. Installation-Level Statistics

**Source:** `src/Infrastructure/Reporting/ReportContextBuilder.php:187-231`

Counts extensions per risk level (low/medium/high/critical/unknown) and per availability channel (TER/Packagist/VCS/none). The `critical_extensions` count sums high + critical.

---

## Analysis: What the Scoring System Captures and What It Does Not

### Dimensions covered

| Dimension              | Analyzer(s)                   | Aspect measured                              |
|------------------------|-------------------------------|----------------------------------------------|
| **Availability**       | VersionAvailability           | Can you get a compatible version at all?      |
| **API compatibility**  | Rector                        | Deprecated/removed PHP API usage              |
| **Config compatibility** | Fractor                     | TypoScript, Fluid, YAML breaking changes      |
| **Codebase volume**    | LinesOfCode                   | Size proxy for testing/migration effort        |
| **Change effort**      | Rector, Fractor               | Estimated hours to fix detected issues         |
| **Change complexity**  | Rector, Fractor               | Diversity, spread, and severity of changes     |

### What is well-reflected

1. **Binary upgrade blockers**: The version availability check directly answers "can this extension even run on the target version?" This is the single most important gate.
2. **Known deprecation debt**: Rector and Fractor provide concrete, actionable findings from static analysis against known TYPO3 migration rules.
3. **Effort proportionality**: The per-change-type effort model differentiates between trivial (code style, 3 min) and hard (breaking change, 60 min) fixes, which prevents a count of findings from being misleading.

### Gaps and limitations

1. **No runtime compatibility testing**: The system cannot detect issues that only manifest at runtime (database schema conflicts, event listener ordering, middleware incompatibilities).
2. **No dependency chain analysis**: An extension may be available on Packagist, but its own dependencies may not be compatible with the target TYPO3 version. This is not checked.
3. **Effort estimates are per-finding, not per-context**: Two findings in the same file requiring the same refactoring are counted as separate effort. The model has no concept of "batch fixing".
4. **LinesOfCode is a weak proxy**: Large codebase does not necessarily mean difficult upgrade. A 15,000-line extension with zero deprecated API calls is safer than a 500-line extension full of removed classes. The LOC analyzer cannot know this on its own.
5. **Equal weighting in aggregation**: The extension-level risk is a simple average across all analyzers. This means the VersionAvailability score (which can be a hard blocker) is averaged equally with the LinesOfCode score (which is purely informational). A "not available anywhere" extension (risk 9.0) could still show medium overall risk if LOC and Rector scores are low.
6. **No target-version-specific availability check**: The VersionAvailability analyzer checks whether the extension exists in a source at all, but does not verify that the available version actually supports the target TYPO3 version (this is tracked as a planned feature).
7. **Complexity score ceiling**: The maximum complexity score is bounded by the normalization divisors (10 unique rules, 20 unique files). Extensions with 50+ unique rules and 100+ files are scored the same as those with 10 rules and 20 files.
8. **No code quality baseline**: The system measures gap-to-target but not current code quality. An extension with poor test coverage, no CI, or known bugs carries hidden risk that no analyzer currently surfaces.

### Recommendations for scoring model evolution

- **Weighted aggregation**: Consider weighting VersionAvailability higher (or treating it as a gate) rather than averaging it with informational analyzers.
- **Version constraint check**: Extend VersionAvailability to verify that found packages actually declare compatibility with the target TYPO3 version.
- **Batch effort discount**: When multiple findings of the same change type affect the same file, apply a diminishing effort multiplier.
- **Complexity normalization caps**: Make the divisors in the complexity score configurable or scale them to the extension size.
