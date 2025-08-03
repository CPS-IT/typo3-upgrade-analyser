# TYPO3 Upgrade Analyzer - Minimal Viable Product (MVP)

## User Story

**As a developer I want to generate a list of extensions in a given TYPO3 installation. This list must contain their current version and the availability status for a given target version.**

## MVP Scope

### Core Functionality

1. **Installation Discovery**
   - use an existing configuration file to read  
     - installation path
     - target version
   - recognize the extension installation path 

2. **Version Detection**  
   - determine current version of each extension
   - Handle both legacy and composer-based extensions

3. **Availability Check**
   - Query TER API for each extension's target version compatibility
   - Query Packagist for composer packages
   - Return availability status: AVAILABLE/UNAVAILABLE/UNKNOWN

4. **Simple List Output**
   - Terminal table format
   - Columns: Extension Key, Current Version, Target Availability
   - Clear status indicators

### Core Command
```bash
./bin/typo3-analyzer list-extensions /path/to/typo3 --target-version=12.4
```

### Expected Output
```
TYPO3 Installation Extensions (Target: 12.4)
+----------------+----------+-------------+
| Extension      | Current  | Available   |
+----------------+----------+-------------+
| news           | 8.7.0    | YES         |
| powermail      | 10.9.0   | YES         |
| realurl        | 2.3.2    | NO          |
| custom_ext     | 1.0.0    | UNKNOWN     |
+----------------+----------+-------------+

Summary: 2 compatible, 1 incompatible, 1 unknown
```

## Implementation Requirements

### Core Components
1. **ExtensionDiscovery** - Find extensions in directories `vendor` or `typo3conf/ext`
2. **ExtensionMetadataReader**: read the installation status from PackageStates.php or `vendor/composer/installed.json` 
3. **CompatibilityChecker** - API queries for availability
4. **ListExtensionsCommand** - single input option: `c`/`config` - Path to custom configuration file 
5. **TableFormatter** - Terminal output

### API Integration
- TER API for TYPO3 extensions
- Packagist API for composer packages
- Handle rate limits and timeouts gracefully

## Success Criteria

A developer can:
1. Point tool at any TYPO3 installation
2. Get complete extension inventory with target compatibility
3. Identify blocking extensions immediately
4. Complete analysis in under 2 minutes for typical installation

## Technical Constraints

- Read-only analysis (no installation modifications)
- Handle both legacy and composer installations  
- Graceful API failure handling
- Memory efficient for large extension lists

## Out of Scope

- Static code analysis
- Configuration parsing beyond PackageStates.php
- Risk scoring algorithms
- HTML/JSON output formats
- Individual extension details
