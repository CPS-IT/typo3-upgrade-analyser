# TYPO3 Upgrade Analyzer - Minimal Viable Product (MVP)

## User Story

**As a developer I want to generate a list of extensions in a given TYPO3 installation with their current versions.**

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

3. **Simple List Output**
   - Terminal table format
   - Columns: Extension Key, Current Version, Type, Active Status
   - Clear status indicators

### Core Command
```bash
./bin/typo3-analyzer list-extensions /path/to/typo3 --target-version=12.4
```

### Expected Output
```
TYPO3 Installation Extensions
+----------------+----------+---------+--------+
| Extension      | Version  | Type    | Active |
+----------------+----------+---------+--------+
| news           | 8.7.0    | composer| YES    |
| powermail      | 10.9.0   | composer| YES    |
| realurl        | 2.3.2    | local   | NO     |
| custom_ext     | 1.0.0    | local   | YES    |
+----------------+----------+---------+--------+

Found 4 extensions (3 active, 1 inactive)
```

## Implementation Requirements

### Core Components
1. **ExtensionDiscovery** - Find extensions in directories `vendor` or `typo3conf/ext`
2. **ExtensionMetadataReader**: read the installation status from PackageStates.php or `vendor/composer/installed.json` 
3. **ListExtensionsCommand** - single input option: `c`/`config` - Path to custom configuration file 
4. **TableFormatter** - Terminal output

## Success Criteria

A developer can:

1. Point tool at any TYPO3 installation
2. Get complete extension inventory with versions and types
3. See active/inactive status of extensions
4. Complete analysis in under 30 seconds for typical installation

## Technical Constraints

- Read-only analysis (no installation modifications)
- Handle both legacy and composer installations  
- Memory efficient for large extension lists

## Out of Scope

- Target version compatibility checks
- API queries to external services (TER, Packagist)
- Static code analysis
- Configuration parsing beyond PackageStates.php
- Risk scoring algorithms
- HTML/JSON output formats
- Individual extension details
