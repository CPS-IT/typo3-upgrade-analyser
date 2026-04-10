# TER API Findings and Documentation

## Overview

During integration testing with the TYPO3 Extension Repository (TER) API, we discovered critical limitations that affect how the Upgrade Analyzer should handle TER data.

## Key Findings

### 1. TER Contains Extensions for All Modern TYPO3 Versions

**Discovery**: TER actively supports modern TYPO3 versions including v11, v12, and v13**.

**Tested Extensions (Updated Results)**:
- `news` (georgringer/news): **Supports TYPO3 11, 12, 13** (Latest: v12.3.0 for TYPO3 12+13)
- `bootstrap_package` (bk2k/bootstrap-package): **Supports TYPO3 11, 12, 13** (Latest: v15.0.3 for TYPO3 12+13)
- `extension_builder` → `extensionbuilder_typo3`: **Supports TYPO3 12, 13** (Latest: v0.6.154)
- Legacy extensions like `realurl`: Max TYPO3 version 8 (correctly archived)

**Impact**: **TER CAN be relied upon for modern TYPO3 version compatibility checks** - it's actively maintained with current extensions.

### 2. TER API Response Structure

**Extension Data Format**:
```json
[
  {
    "key": "extension_key",
    "downloads": 12345,
    "verified": true,
    "version_count": 89,
    "meta": {...},
    "current_version": {
      "number": "12.3.0",  // Note: 'number' not 'version'
      "typo3_versions": [11, 12, 13]  // Note: TER returns integers, not strings
    }
  }
]
```

**Versions Data Format**:
```json
[
  [
    {
      "number": "12.3.0",  // Version field name is 'number'
      "typo3_versions": [11, 12, 13],  // TER returns integers for TYPO3 versions
      "title": "Version title",
      "description": "Version description",
      "state": "stable",
      "upload_date": 1234567890
    }
  ]
]
```

**Key Points**:
- TER API returns arrays, not objects with named keys
- Extension data is always in `response[0]`
- Versions data is always in `response[0]` (array of versions)
- Version field is `number`, not `version`
- **CRITICAL: `typo3_versions` contains integers (11, 12, 13), not strings ("11", "12", "13")** ⚠️

### 3. Modern Extension Distribution Pattern

**Current TYPO3 Ecosystem**:
- **TER**: **Active repository supporting all TYPO3 versions (4-13)**
- **Packagist**: Alternative source for Composer-based extension management
- **Git Repositories**: Direct source for latest development versions

**Implications for Upgrade Analysis**:
1. **TER can be used for all TYPO3 version checks (4-13)**
2. **TER and Packagist provide complementary coverage** - use both for comprehensive analysis
3. Git repositories provide bleeding-edge compatibility checks

## Implementation Recommendations

### 1. TER Client Usage

```php
// Correct usage pattern
$terClient = new TerApiClient($httpClient, $logger);

// TER supports both legacy and modern TYPO3 versions
$hasLegacySupport = $terClient->hasVersionFor('news', new Version('8.7.0'));   // true
$hasModernSupport = $terClient->hasVersionFor('news', new Version('12.4.0')); // true
$hasLatestSupport = $terClient->hasVersionFor('news', new Version('13.0.0'));  // true
```

### 2. Multi-Source Strategy

```php
// Recommended approach for comprehensive analysis
class VersionAvailabilityAnalyzer
{
    public function analyze(Extension $extension, Version $targetVersion): AnalysisResult
    {
        $sources = [];

        // Check TER for all TYPO3 versions (4-13)
        $sources['ter'] = $this->terClient->hasVersionFor($extension->getKey(), $targetVersion);

        // Check Packagist for Composer-based availability
        if ($extension->hasComposerName()) {
            $sources['packagist'] = $this->packagistClient->hasVersionFor($extension->getComposerName(), $targetVersion);
        }

        // Check Git for latest development
        if ($extension->hasGitRepository()) {
            $sources['git'] = $this->gitClient->hasVersionFor($extension->getGitUrl(), $targetVersion);
        }

        return new AnalysisResult($extension, $sources);
    }
}
```

### 3. Test Expectations

**Updated Test Data**
- Popular extensions: `expected_typo3_12_compatibility: true` (News, Bootstrap Package, ExtensionBuilder TYPO3)
- Legacy extensions: `expected_typo3_12_compatibility: false` (RealURL, archived extensions)
- System extensions: `expected_typo3_12_compatibility: true` (Packagist-based)

**Test Strategy**:
```php
// Test TER for what it actually provides
public function testTerModernSupport(): void
{
    // TER supports both legacy and modern TYPO3 versions
    $this->assertTrue($terClient->hasVersionFor('news', new Version('8.7.0')));
    $this->assertTrue($terClient->hasVersionFor('news', new Version('12.4.0')));  // Now true!
    $this->assertTrue($terClient->hasVersionFor('news', new Version('13.0.0')));  // TYPO3 v13 support
}

// Test legacy extensions that don't support modern TYPO3
public function testLegacyExtensionLimitations(): void
{
    $this->assertTrue($terClient->hasVersionFor('realurl', new Version('8.7.0')));
    $this->assertFalse($terClient->hasVersionFor('realurl', new Version('12.4.0')));
}
```

## Developer Guidelines

### 1. When to Use TER API

**✅ Appropriate Use Cases**:
- **Checking all TYPO3 compatibility (versions 4-13)**
- Historical version analysis
- Primary source for extension availability (alongside Packagist)
- Official TYPO3 extension repository coverage

**❌ Inappropriate Use Cases**:
- **Assuming all extensions support latest TYPO3** (some legacy extensions are archived)
- **Ignoring data type handling** (TER returns integer TYPO3 versions)
- Assuming uniform response structure across all extensions

### 2. Error Handling

```php
// Proper TER API error handling
try {
    $hasVersion = $terClient->hasVersionFor($extensionKey, $typo3Version);
} catch (ExternalToolException $e) {
    // TER API failures should not break analysis
    $this->logger->warning('TER API unavailable, using fallback sources', [
        'extension' => $extensionKey,
        'error' => $e->getMessage()
    ]);

    // Continue with Packagist/Git sources
    return $this->checkAlternativeSources($extensionKey, $typo3Version);
}
```

### 3. Documentation for Users

When presenting TER-based results to users:

```php
$result = new AnalysisResult($extension, [
    'ter_available' => true,
    'ter_version' => '12.3.0',  // Latest TER version
    'ter_typo3_support' => [11, 12, 13],  // Supported TYPO3 versions
    'ter_note' => 'Extension actively maintained in TER for modern TYPO3 versions.',
    'packagist_available' => true,
    'recommendation' => 'Extension available via both TER and Composer'
]);
```

## Known TER API Bugs

The following bugs in the TER API affect the reliability of the `ter` source in version availability analysis. Both are tracked upstream:

### Bug #650 — `typo3_versions` omits non-LTS major versions

- **Issue**: [extensions.typo3.org/ter #650](https://git.typo3.org/services/t3o-sites/extensions.typo3.org/ter/-/issues/650)
- **Symptom**: The `typo3_versions` field only includes TYPO3 LTS versions. Non-LTS major versions (e.g. TYPO3 14, which is not yet LTS) are absent even when `dependencies.typo3` explicitly covers them.
- **Example**: `news:14.0.1` returns `"typo3_versions": [13]` despite `dependencies.typo3: "13.4.20 - 14.4.99"`.
- **Root cause**: TER stores only LTS major versions in the `typo3_versions` column.
- **Impact**: Upgrade analysis targeting TYPO3 v14 or any future non-LTS major version will incorrectly report TER-sourced extensions as incompatible when relying on `typo3_versions`.

### Bug #653 — `typo3_versions` is empty for all extension versions

- **Issue**: [extensions.typo3.org/ter #653](https://git.typo3.org/services/t3o-sites/extensions.typo3.org/ter/-/issues/653)
- **Symptom**: As of 2026-04-03, `typo3_versions` is empty (`[]`) for all extension versions returned by the `/extension/*` endpoints. This is a regression beyond #650.
- **Example**: `GET /api/v1/extension/warming` returns `"typo3_versions": []` for `current_version`.
- **Impact**: The `ter` source is currently unreliable for any version compatibility decision. Results must be treated as potentially absent.

### Recommended mitigations

- Enable `packagist` and `git` sources alongside or instead of `ter` to compensate:
  ```yaml
  analysis:
    analyzers:
      version_availability:
        sources: [ter, packagist, git]
  ```
- The analyzer already treats each source independently — a missing or incorrect TER result does not block Packagist or git results.
- Monitor upstream issues for resolution before treating TER results as authoritative for TYPO3 v14+.

## Conclusion

The TER API is structurally sound and provides useful historical data for extensions up to TYPO3 v13, but is currently affected by two active bugs (#650, #653) that make `typo3_versions` unreliable. Use `packagist` and `git` as the primary sources for compatibility checks against TYPO3 v14 and above.

**Key Technical Requirements:**
- Handle **integer TYPO3 versions** (not strings) in version compatibility logic
- Parse **array response structure** with data in `response[0]`
- Use **'number' field** for version numbers (not 'version')
- Implement proper error handling for archived/legacy extensions
- Do not treat absent or empty `typo3_versions` as a definitive "not compatible" — check `dependencies` as a fallback where possible
