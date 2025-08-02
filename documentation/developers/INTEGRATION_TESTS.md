# TYPO3 Upgrade Analyzer - Integration Test Framework

## Overview

This document provides a comprehensive overview of the integration test framework implemented for the TYPO3 Upgrade Analyzer. The framework enables real-world testing of the analyzer with actual external APIs including GitHub, TER (TYPO3 Extension Repository), and Packagist.

## Framework Components

### 🏗️ Infrastructure Components

#### 1. PHPUnit Configuration (`phpunit.xml`)
- **Environment Variables**: GitHub tokens, API timeouts, rate limiting
- **Test Groups**: Integration, real-world, GitHub API, TER API, performance
- **Exclusions**: Integration tests excluded from default runs to prevent accidental API calls

#### 2. Composer Scripts (`composer.json`)
```bash
composer test:integration      # All integration tests
composer test:real-world      # Real-world scenario tests
composer test:github-api      # GitHub API specific tests
composer test:ter-api         # TER API specific tests
composer test:performance     # Performance benchmarks
```

#### 3. Base Test Class (`AbstractIntegrationTest`)
- **Environment Management**: API tokens, timeouts, caching
- **HTTP Client Setup**: Authenticated and unauthenticated clients
- **Rate Limiting**: Automatic delays to respect API limits
- **Test Data Management**: JSON fixtures and caching
- **Assertion Helpers**: Specialized assertions for API responses

### 📊 Test Data & Fixtures

#### Known Extensions (`Fixtures/known_extensions.json`)
- **Active Extensions**: `georgringer/news`, `friendsoftypo3/extension-builder`
- **Archived Extensions**: `dmitryd/typo3-realurl` (for legacy testing)
- **System Extensions**: `typo3/cms-core`
- **Local Extensions**: Test extensions without public repositories

#### Test Scenarios
- ✅ Active repository with semantic versioning
- 📦 Multiple availability sources (TER + Packagist + Git)
- 🗄️ Archived/unmaintained repositories
- 🔧 System extension handling
- 🏠 Local extension without public availability

## 🧪 Test Categories

### 1. External Tool Integration Tests

#### GitHub Repository Integration (`GitRepositoryIntegrationTest`)
**26 Test Methods** covering:
- Repository metadata retrieval
- Tag and branch information extraction
- Repository health analysis
- Archived vs. active repository handling
- Authentication scenarios (with/without tokens)
- Rate limit management
- Performance benchmarks
- Error handling for non-existent repositories

#### TER API Integration (`TerApiIntegrationTest`)
**12 Test Methods** covering:
- Extension version compatibility checking
- TYPO3 version compatibility logic
- Latest version retrieval
- Error handling for non-existent extensions
- Multiple extension batch processing
- API response structure validation
- Performance benchmarks

### 2. Complete Workflow Integration Tests

#### Version Availability Analysis (`VersionAvailabilityIntegrationTest`)
**11 Test Methods** covering:
- Multi-source availability analysis (TER + Packagist + Git)
- Risk score calculation validation
- Recommendation generation quality
- Different extension types handling
- Cross-version compatibility testing
- Error handling and resilience
- Performance validation

### 3. Complex Scenario Testing

#### Mixed Analysis Integration (`MixedAnalysisIntegrationTest`)
**9 Test Methods** covering:
- Complete TYPO3 installation analysis
- Consistency across multiple sources
- Migration scenarios for archived extensions
- Batch processing efficiency
- Risk score consistency validation
- Network failure resilience

### 4. Performance & Reliability Testing

#### Performance Reliability Test (`PerformanceReliabilityTest`)
**9 Test Methods** covering:
- API response time benchmarks
- Memory usage monitoring and leak detection
- Rate limit handling
- Network timeout resilience
- Concurrent analysis stability
- Large dataset processing
- Diverse network conditions testing

## 📈 Performance Benchmarks

### API Response Times
- **GitHub API**: < 3.0 seconds
- **TER API**: < 5.0 seconds  
- **Packagist API**: < 3.0 seconds

### Analysis Times
- **Single Extension**: < 20 seconds
- **Complete Installation**: < 60 seconds
- **Batch Processing**: < 15 seconds average per extension

### Memory Usage
- **Maximum Increase**: < 10MB during analysis
- **No Memory Leaks**: Verified during repeated analyses

## 🔧 Configuration & Setup

### Environment Variables
```bash
# GitHub API token (optional but recommended)
GITHUB_TOKEN=your_github_token_here

# Enable/disable real API calls
ENABLE_REAL_API_CALLS=true

# Timeout settings (seconds)
INTEGRATION_TEST_TIMEOUT=30
API_REQUEST_TIMEOUT=10

# Rate limiting
API_RATE_LIMIT_DELAY=1

# Test cache directory
INTEGRATION_TEST_CACHE_DIR=var/integration-test-cache
```

### GitHub Token Benefits
- **Authenticated**: 5000 requests/hour
- **Unauthenticated**: 60 requests/hour
- **Access**: Private repository metadata (if needed)

## 🚀 Usage Examples

### Running All Integration Tests
```bash
# Run all integration tests (requires API access)
composer test:integration

# Run specific test groups
composer test:github-api
composer test:ter-api
composer test:real-world
composer test:performance
```

### Running Individual Test Classes
```bash
# Specific test class
vendor/bin/phpunit tests/Integration/ExternalTool/GitRepositoryIntegrationTest.php

# Specific test method
vendor/bin/phpunit --filter testGetRepositoryInfoForActiveRepository tests/Integration/ExternalTool/GitRepositoryIntegrationTest.php
```

### Environment-Specific Testing
```bash
# With GitHub token
GITHUB_TOKEN=your_token composer test:github-api

# Without real API calls (limited functionality)
ENABLE_REAL_API_CALLS=false composer test:integration
```

## 🛡️ Safety Features

### Rate Limiting Protection
- Automatic delays between requests
- Rate limit header monitoring
- Token-based authentication for higher limits
- Graceful degradation without tokens

### Caching System
- Response caching to avoid repeated API calls
- Cache invalidation strategies
- Development-friendly cache management

### Error Handling
- Network timeout handling
- API failure recovery
- Graceful degradation when services unavailable
- Comprehensive exception handling

## 📋 Test Coverage

### Total Integration Tests: **55 Test Methods**
- GitHub Repository Integration: 26 tests
- TER API Integration: 12 tests  
- Version Availability Analysis: 11 tests
- Mixed Analysis Scenarios: 9 tests
- Performance & Reliability: 9 tests
- Configuration Validation: 12 tests

### Test Scenarios Covered:
- ✅ Active repository analysis
- 📦 Multi-source availability checking
- 🗄️ Archived extension handling
- 🔧 System extension processing
- 🏠 Local extension analysis
- ⚡ Performance benchmarking
- 🛡️ Error handling and resilience
- 🔄 Rate limiting and authentication
- 💾 Memory usage monitoring
- 🌐 Network condition variations

## 🤝 Contributing

### Adding New Integration Tests

1. **Extend `AbstractIntegrationTest`**
2. **Use appropriate test groups**: `@group integration`, `@group github-api`, etc.
3. **Add API requirement checks**: `$this->requiresRealApiCalls()`
4. **Include performance assertions**
5. **Handle expected failures gracefully**
6. **Update documentation**

### Best Practices
- Use caching for repeated API calls during development
- Respect API rate limits with delays
- Test both authenticated and unauthenticated scenarios
- Include performance benchmarks
- Handle network failures gracefully
- Use descriptive test names and documentation

## 🏆 Benefits

### Real-World Validation
- Tests actual API behavior, not mocked responses
- Validates complete workflows end-to-end
- Ensures compatibility with external service changes
- Provides confidence in production deployment

### Performance Monitoring
- Establishes performance baselines
- Monitors API response times
- Detects memory leaks and performance regressions
- Validates scalability for large installations

### Reliability Assurance
- Tests error handling and recovery
- Validates network failure resilience
- Ensures graceful degradation
- Provides comprehensive edge case coverage

### Development Support
- Fast feedback on API integration issues
- Caching reduces development API calls
- Clear separation of test types
- Comprehensive documentation and examples

This integration test framework provides comprehensive real-world validation of the TYPO3 Upgrade Analyzer, ensuring reliable operation with external APIs while maintaining development efficiency through intelligent caching and rate limiting.