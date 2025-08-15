# Installation Guide

This guide provides comprehensive installation instructions for the TYPO3 Upgrade Analyzer across different environments and use cases.

## Table of Contents

- [System Requirements](#system-requirements)
- [Installation Methods](#installation-methods)
- [Environment Setup](#environment-setup)
- [Configuration](#configuration)
- [Verification](#verification)
- [Troubleshooting](#troubleshooting)

## System Requirements

### Minimum Requirements

- **PHP 8.3** or higher with the following extensions:
  - `ext-json` (for JSON processing)
  - `ext-mbstring` (for string handling)
  - `ext-curl` (for HTTP requests)
  - `ext-dom` (for XML processing)
  - `ext-libxml` (for XML processing)

- **Composer 2.0** or higher
- **Memory**: 512MB minimum, 2GB recommended for large installations
- **Storage**: 100MB minimum, 1GB recommended for cache and reports

### External Dependencies

The following tools are automatically installed via Composer:

- **ssch/typo3-rector**: TYPO3-specific code analysis and refactoring
- **a9f/typo3-fractor**: TypoScript modernization and analysis

### Network Requirements

The analyzer requires internet access for:
- **TYPO3 Extension Repository (TER)**: Extension metadata and version information
- **Packagist**: Composer package availability
- **GitHub API**: Git repository analysis (optional, but recommended)

## Installation Methods

### Method 1: Composer (Recommended)

#### Local Installation (Project-specific)

```bash
# For development and testing environments
composer require --dev cpsit/typo3-upgrade-analyser

# Run analyzer
./vendor/bin/typo3-analyzer --version
```

#### Global Installation

```bash
# Install globally for system-wide access
composer global require cpsit/typo3-upgrade-analyser

# Ensure Composer global bin directory is in PATH
echo 'export PATH="$PATH:$HOME/.composer/vendor/bin"' >> ~/.bashrc
source ~/.bashrc

# Run analyzer from anywhere
typo3-analyzer --version
```

### Method 2: From Source (Development)

```bash
# Clone repository
git clone https://github.com/cpsit/typo3-upgrade-analyser.git
cd typo3-upgrade-analyser

# Install dependencies
composer install

# Make binary executable
chmod +x bin/typo3-analyzer

# Run analyzer
./bin/typo3-analyzer --version
```

## Environment Setup

### Development Environment

```bash
# Clone and setup development environment
git clone https://github.com/cpsit/typo3-upgrade-analyser.git
cd typo3-upgrade-analyser

# Install all dependencies (including dev dependencies)
composer install

# Verify development setup
composer test
composer lint
composer sca:php

# Setup IDE integration (optional)
composer run-script setup-ide
```

### Production Environment

This is a development tool and it should never be used in production
environments!

### CI/CD Environment

```bash
# Install with specific platform requirements
composer install --no-dev --no-interaction --optimize-autoloader

# Run in CI mode (no interactive prompts)
./bin/typo3-analyzer analyze --config=ci-config.yaml
```

## Configuration

### Initial Configuration

1. **Create Configuration File**:
   ```bash
   ./bin/typo3-analyzer init-config
   ```

2. **Edit Configuration** (`config/configuration.yaml`):
   ```yaml
   installation:
     path: '/path/to/your/typo3/installation'

   target_version: '13.0'

   output:
     directory: 'var/analysis-results'
     formats: ['html', 'markdown']

   analyzers:
     version_availability:
       enabled: true
       check_ter: true
       check_packagist: true
       check_git: true

     typo3_rector:
       enabled: true
       timeout: 300

     fractor:
       enabled: true
       timeout: 300

     lines_of_code:
       enabled: true
   ```

### Environment-Specific Configuration

#### Development Configuration

```yaml
# config/development.yaml
debug: true
cache:
  enabled: false
logging:
  level: debug
  output: 'var/logs/debug.log'
```

#### Production Configuration

```yaml
# config/production.yaml
debug: false
cache:
  enabled: true
  ttl: 3600
logging:
  level: info
  output: 'var/logs/analyzer.log'
```

#### CI Configuration

```yaml
# config/ci.yaml
output:
  directory: 'build/analysis-results'
  formats: ['html', 'json']
analyzers:
  # Disable network-dependent analyzers in CI
  version_availability:
    check_git: false
timeout:
  global: 600
```

### Directory Structure Setup

The analyzer creates the following directory structure:

```
project-root/
├── config/
│   ├── configuration.yaml      # Main configuration
│   └── custom-analyzers.yaml   # Custom analyzer settings
├── var/
│   ├── cache/                  # Caches for tools and twig templates
│   ├── logs/                   # Log files
│   ├── results/                # Cached results in json format
│   ├── reports/                # Analysis output
│   └── temp/                   # Temporary files
└── bin/
    └── typo3-analyzer          # Main executable
```

## Verification

### Basic Verification

```bash
# Check version and installation
./bin/typo3-analyzer --version

# List available commands
./bin/typo3-analyzer list

# List available analyzers
./bin/typo3-analyzer list-analyzers

# Initialize configuration
./bin/typo3-analyzer init-config

# Initialize configuration (interactive)
./bin/typo3-analyzer init-config --interactive
```

### Test Installation with Sample Data

```bash
# Run analysis on test data
./bin/typo3-analyzer analyze --config=tests/Fixtures/test-config.yaml

# Verify reports are generated
ls -la var/reports/
```

## Troubleshooting

### Common Issues

#### 1. PHP Version Issues

**Error**: `PHP version 8.3 or higher is required`

**Solution**:
```bash
# Check current PHP version
php --version

# Update PHP (Ubuntu/Debian)
sudo apt update
sudo apt install php8.3

# Update PHP (macOS with Homebrew)
brew install php@8.3
brew link --overwrite php@8.3
```

#### 2. Composer Issues

**Error**: `Composer not found` or version too old

**Solution**:
```bash
# Install/update Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Verify installation
composer --version
```

#### 3. Memory Limit Issues

**Error**: `Fatal error: Allowed memory size exhausted`

**Solution**:
```bash
# Increase memory limit for analysis
php -d memory_limit=2G ./bin/typo3-analyzer analyze

# Or set in php.ini
memory_limit = 2G
```

#### 4. Permission Issues

**Error**: `Permission denied` when writing reports

**Solution**:
```bash
# Fix permissions for output directory
chmod -R 755 var/
chown -R $USER:$GROUP var/

# Ensure binary is executable
chmod +x bin/typo3-analyzer
```

#### 5. Network/API Issues

**Error**: `Could not connect to TER/Packagist API`

**Solution**:
```bash
# Check network connectivity
curl -I https://extensions.typo3.org
curl -I https://packagist.org

# Configure proxy if needed
export HTTP_PROXY=http://proxy.company.com:8080
export HTTPS_PROXY=http://proxy.company.com:8080

# Disable network-dependent analyzers
./bin/typo3-analyzer analyze --analyzers=lines_of_code
```

### Logging and Debugging

#### Enable Debug Logging

```yaml
# config/configuration.yaml
debug: true
logging:
  level: debug
  output: 'var/logs/debug.log'
```

#### Analyze Log Files

```bash
# View recent log entries
tail -f var/log/typo3-upgrade-analyzer.log

# Search for errors
grep -i error var/logs/typo3-upgrade-analyzer.log

# Check cache issues
ls -la var/cache/
```

### Getting Help

#### Reporting Issues

When reporting issues, include:

1. **System Information**:
   ```bash
   php --version
   composer --version
   ./bin/typo3-analyzer --version
   ```

2. **Error Logs**:
   ```bash
   # Include relevant log entries
   tail -n 50 var/logs/analyzer.log
   ```

3. **Configuration** (sanitized):
   ```bash
   # Remove sensitive information
   cat config/configuration.yaml | grep -v password
   ```

### Performance Optimization

#### For Large Installations

```yaml
# config/configuration.yaml
cache:
  enabled: true
  ttl: 7200  # 2 hours

performance:
  parallel_processing: true
  max_concurrent_requests: 5
  timeout: 600

analyzers:
  # Disable heavy analyzers for initial runs
  typo3_rector:
    enabled: false  # Enable after initial analysis
```

#### Memory Optimization

```bash
# Run with increased memory and time limits
php -d memory_limit=4G -d max_execution_time=3600 ./bin/typo3-analyzer analyze
```

## Next Steps

After successful installation:

1. **Read Usage Documentation**: See [USAGE.md](USAGE.md) for detailed command examples
2. **Configure Analyzers**: Customize analyzer settings for your specific needs
3. **Run First Analysis**: Start with a small TYPO3 installation to verify setup
4. **Review Reports**: Understand the output format and recommendations
5. **Automate Analysis**: Set up regular analysis runs for continuous monitoring

For development and contribution, see [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.
