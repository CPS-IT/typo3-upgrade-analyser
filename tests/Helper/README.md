# Test Helper Scripts

This directory contains utility scripts for environment validation, debugging, and API testing. These scripts can be run independently of the main test suite for troubleshooting and verification purposes.

## Scripts Overview

### API Testing Scripts

#### `test_github_api_access.php`
**Purpose**: Comprehensive GitHub API testing (REST and GraphQL)
**Usage**: `php tests/Helper/test_github_api_access.php`
**Features**:
- Tests both authenticated and unauthenticated access
- Validates REST API endpoints (repositories, tags, contributors)
- Tests GraphQL queries for advanced repository data
- Checks rate limits and permissions
- Provides token setup guidance

#### `test_ter_api_access.php`
**Purpose**: TYPO3 Extension Repository (TER) API testing
**Usage**: `php tests/Helper/test_ter_api_access.php`
**Features**:
- Tests TER API endpoints with and without authentication
- Validates extension metadata and versions
- Analyzes TYPO3 version compatibility
- Tests both modern and legacy extensions
- Provides detailed API response analysis

#### `test_packagist_api_access.php`
**Purpose**: Packagist API testing for Composer packages
**Usage**: `php tests/Helper/test_packagist_api_access.php`
**Features**:
- Tests Packagist API for TYPO3 extensions
- Analyzes TYPO3 version constraints in composer.json
- Compares availability with TER API
- Tests both existing and non-existent packages

### Client Testing Scripts

#### `ter_client_test.php`
**Purpose**: Quick TER API client functionality test
**Usage**: `php tests/Helper/ter_client_test.php`
**Features**:
- Tests TerApiClient with real API calls
- Validates version compatibility logic
- Tests common extensions (news, bootstrap_package, realurl)
- Quick environment validation

### Git Integration Scripts

#### `validate_git_support.php`
**Purpose**: Comprehensive Git repository support validation
**Usage**: `php tests/Helper/validate_git_support.php`
**Features**:
- Tests Git repository analysis functionality
- Validates GitHub API integration
- Tests repository metadata extraction
- Checks Git tag and version parsing

#### `validate_git_simple.php`
**Purpose**: Simple Git functionality test
**Usage**: `php tests/Helper/validate_git_simple.php`
**Features**:
- Basic Git provider functionality test
- Quick validation of Git integration
- Simplified debugging for Git issues

## Environment Requirements

### Required Environment Variables

Create `.env.local` file in project root with:

```bash
# GitHub API Token (optional, increases rate limits)
GITHUB_TOKEN=ghp_your_token_here

# TYPO3 Extension Repository API Token (optional)
TER_TOKEN=your_ter_token_here
```

### Token Setup

#### GitHub Token
1. Go to: https://github.com/settings/tokens
2. Create new token (classic)
3. Select scopes: `repo` (for public repositories)
4. Set: `export GITHUB_TOKEN=your_token`

#### TER Token
1. Register at: https://extensions.typo3.org/
2. Generate API token in your profile
3. Set: `export TER_TOKEN=your_token`

## Usage Scenarios

### Environment Validation
Run before integration tests to ensure API connectivity:
```bash
php tests/Helper/test_github_api_access.php
php tests/Helper/test_ter_api_access.php
php tests/Helper/test_packagist_api_access.php
```

### Debugging API Issues
Use when integration tests fail to isolate API problems:
```bash
php tests/Helper/ter_client_test.php
```

### Git Integration Debugging
Use when Git repository analysis fails:
```bash
php tests/Helper/validate_git_support.php
```

## Expected Results

### Successful API Access
- All API endpoints return 200 status codes
- Extension data is properly parsed
- TYPO3 version compatibility is correctly detected
- Rate limits are within acceptable ranges

### Common Issues
- **403 Forbidden**: Missing or invalid API tokens
- **404 Not Found**: Extension doesn't exist or wrong endpoint
- **429 Rate Limited**: Too many requests, need authentication
- **500 Server Error**: API service temporarily unavailable

## Maintenance

These scripts should be updated when:
- New API endpoints are added
- Authentication methods change
- Test extensions are deprecated or replaced
- New TYPO3 versions are released

## Integration with Main Tests

These helper scripts complement the main test suite:
- Use them for environment validation before running integration tests
- Reference them for debugging when integration tests fail
- Use them for manual verification of API changes
