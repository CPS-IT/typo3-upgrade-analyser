# Integration Tests

This directory contains integration tests that test the TYPO3 Upgrade Analyzer against real external APIs and services.

## Setup

### Environment Variables

Before running integration tests, you need to set up the appropriate environment variables:

```bash
# Enable real API calls (required for integration tests)
export ENABLE_REAL_API_CALLS=true

# GitHub API token (optional but recommended for higher rate limits)
export GITHUB_TOKEN=your_github_personal_access_token

# Optional: Configure request timeouts and delays
export API_REQUEST_TIMEOUT=10
export API_RATE_LIMIT_DELAY=1
export INTEGRATION_TEST_CACHE_DIR=var/integration-test-cache
```

### GitHub Personal Access Token

To get higher API rate limits and avoid being rate-limited during tests:

1. Go to https://github.com/settings/tokens
2. Generate a new personal access token (classic)
3. Select the following scopes:
   - `public_repo` (to read public repository information)
4. Copy the token and set it as `GITHUB_TOKEN` environment variable

## Running Tests

### All GitHub API Tests
```bash
# Set environment variables first
export ENABLE_REAL_API_CALLS=true
export GITHUB_TOKEN=your_token_here

# Run all GitHub API integration tests
composer test:github-api
```

### Individual Test Groups
```bash
# Run specific test classes
vendor/bin/phpunit tests/Integration/ExternalTool/GitRepositoryIntegrationTest.php

# Run specific test methods
vendor/bin/phpunit --filter testGitHubClientSupportsGitHubUrls
```

## Test Behavior

### Without API Configuration
- Tests will be **skipped** with message: "Real API calls are disabled"
- This is the expected behavior in CI/CD environments without API credentials

### With API Configuration
- Tests will make real API calls to GitHub
- Rate limiting is automatically handled
- Tests will be skipped if rate limits are reached
- Results are cached to avoid unnecessary API calls

### Rate Limiting
- Tests automatically detect rate limiting and skip remaining tests
- Rate limit status is cached to avoid retrying too soon
- With GitHub token: ~5,000 requests/hour
- Without token: ~60 requests/hour

## Test Data

Tests use real repository data defined in `Fixtures/known_extensions.json`:
- `georgringer/news` - Active, popular TYPO3 extension
- `dmitryd/typo3-realurl` - Archived TYPO3 extension
- `friendsoftypo3/extension-builder` - Community-maintained extension

## Troubleshooting

### All Tests Skipped
- Verify `ENABLE_REAL_API_CALLS=true` is set
- Check that environment variables are exported in your shell

### Rate Limit Errors
- Set `GITHUB_TOKEN` environment variable with a valid personal access token
- Wait for rate limit reset (shown in error message)
- Clear rate limit cache: `rm -f var/integration-test-cache/rate_limit_status.json`

### Network Issues
- Tests require internet connection to GitHub API
- Check firewall/proxy settings if requests fail
- Increase `API_REQUEST_TIMEOUT` if needed

## Contributing

When adding new integration tests:
1. Use the `AbstractIntegrationTest` base class
2. Call `$this->requiresRealApiCalls()` in setUp()
3. Handle rate limiting gracefully with try-catch blocks
4. Use caching for expensive API calls when appropriate
5. Test both authenticated and unauthenticated scenarios
