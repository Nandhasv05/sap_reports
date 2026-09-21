# SAP Reports Testing Guide

This document is the test plan for the SAP Reports PHP MVC module. It covers local testing, automated testing, integration testing, browser testing, performance testing, and authorized penetration testing.

## 1. Scope and Application Map

The application is served from `/sap_reports` and uses these report routes:

| Route | Purpose |
|---|---|
| `/` | Report catalogue |
| `/sales` | Sales order lines |
| `/open` | Active orders |
| `/division` | Division summary |
| `/fabric` | Fabric utilization by sales order |
| `/trims` | Trims utilization by sales order |
| `/login.php` | Portal login entry point |
| `/logout.php` | Portal logout |

Main test surfaces:

- `app/core/Router.php`: URI normalization, method matching, HEAD handling, and 404 behavior.
- `app/core/SapODataClient.php`: authentication, paging, JSON parsing, timeouts, HTTP errors, and SAP response formats.
- `app/services/SapSalesService.php`: sales mapping, filtering, summaries, charts, pagination, and cache behavior.
- `app/services/SapUtilizationService.php`: sales-order normalization, material categorization, filtering, and utilization summaries.
- `app/models/ReportsModel.php`: report selection, active-order filtering, division aggregation, and pagination.
- `app/controllers/ReportsController.php`: query validation, view selection, and CSV export.
- `public/index.php` and `app/core/portal_auth.php`: bootstrapping, authentication, and access control.

## 2. Test Safety and Data Rules

1. Run automated and security tests against local or staging only. Do not run active scans against production or SAP without written authorization.
2. Never use real customer data in fixtures, screenshots, logs, or bug reports. Use masked or synthetic records.
3. Do not commit SAP passwords, cookies, session IDs, or API responses containing sensitive data.
4. The current `config/sap.php` contains an SAP username and password. Treat these credentials as exposed: rotate them before testing and move them to environment variables or an ignored local configuration file.
5. Use `SAP_ENABLED=0` or a fake SAP server for tests that do not require the real SAP system.
6. Record the environment, commit/version, test date, test data set, and tool versions for every test run.

## 3. One-Time Windows/MAMP Setup

Open PowerShell and locate the PHP executable bundled with MAMP:

```powershell
Get-ChildItem C:\MAMP\bin\php -Directory
```

Set a session variable using the PHP directory shown by that command. Replace the version folder as needed:

```powershell
$env:PHP = 'C:\MAMP\bin\php\php8.x.x\php.exe'
& $env:PHP -v
```

If Composer is not installed, install it from https://getcomposer.org/download/ and then verify:

```powershell
composer --version
```

Use `npm.cmd` instead of `npm` when PowerShell execution policy blocks `npm.ps1`:

```powershell
node --version
npm.cmd --version
```

The application currently has no `composer.json`, PHPUnit configuration, or frontend test package. The commands below describe the recommended additions and can be adopted incrementally.

## 4. Test Environment

Create a local test configuration with:

- PHP 8.1 or the same PHP version used by the deployment.
- Apache and the MAMP document root.
- The required PHP extensions, especially `curl`, `json`, and `mbstring` if used by the shared portal.
- A fake SAP OData service, or recorded synthetic SAP fixtures.
- A test portal user with the minimum report permission.
- A separate writable cache/temp directory.

Start MAMP and open:

```text
http://localhost:8888/sap_reports/
```

If the local Apache port differs, use the port configured in MAMP.

## 5. Test Pyramid and Execution Order

Run tests in this order:

1. Static analysis and syntax checks.
2. Unit tests with no network, database, SAP, or browser dependency.
3. Integration tests using a fake SAP service and test portal authentication.
4. API/HTTP tests against the local MAMP URL.
5. Browser end-to-end tests on Chromium at desktop and mobile sizes.
6. Performance tests with a controlled fixture size.
7. Authorized penetration testing against local or staging.

Stop and fix failures at each level before moving to the next level.

## 6. Static and Syntax Checks

From `C:\MAMP\htdocs\sap_reports`:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & $env:PHP -l $_.FullName }
```

Recommended quality tools:

```powershell
composer require --dev phpunit/phpunit:^10 phpstan/phpstan squizlabs/php_codesniffer
vendor\bin\phpstan analyse app config routes public --level=5
vendor\bin\phpcs app config routes public --standard=PSR12
```

Also check that no secret has been added to source control:

```powershell
git grep -n -I -E "password|secret|api[_-]?key|token" -- '*.php' '*.js' '*.json' '*.env*'
```

Any real credential found by this command must be removed from source, rotated, and replaced with environment-based configuration.

## 7. Unit Testing

Unit tests must be deterministic and must not call the real SAP host. Inject fake clients or test the pure methods directly.

### Router tests

- `/sap_reports/`, `/sap_reports/index.php`, and full URLs normalize to the same route.
- Query strings do not become part of the route pattern.
- GET routes accept HEAD requests.
- POST does not dispatch a GET-only route.
- Unknown routes render the 404 response.
- Route parameters are passed to handlers correctly.
- Repeated slashes and backslash normalization behave as intended.

### SAP OData client tests

- Disabled integration returns a clear error without making a request.
- Missing credentials return an error without making a request.
- OData v4 `value` responses are extracted.
- OData v2 `d.results` responses are extracted.
- A single OData v2 `d` row is extracted.
- Invalid JSON and unexpected response shapes fail safely.
- HTTP 4xx/5xx responses return an error and do not leak credentials.
- Paging stops on an empty page, a short page, or `max_rows`.
- `page_size`, `$skip`, `$top`, and extra filters are correct.
- Request timeout and connection failure return controlled errors.

### Service and model tests

- Sales filtering matches order, material, style, and date range.
- Page numbers below 1 and beyond the final page are clamped.
- Open orders include only active statuses.
- Division totals correctly sum lines, quantities, and amounts.
- Zero quantities do not cause divide-by-zero errors.
- Utilization sales orders are padded and normalized consistently.
- Trims are categorized correctly for button, zipper, thread, labels, packing, lining, consumables, and unknown material.
- Duplicate GRN sales orders are removed.
- Empty SAP data produces an empty result, not a PHP warning or fatal error.

### Controller and CSV tests

- Invalid report names return the catalogue instead of loading an arbitrary view.
- `page`, `per_page`, `from`, `to`, `q`, `so`, and `export` inputs are bounded and handled safely.
- CSV exports have the correct content type and headers.
- Every CSV field is escaped by `fputcsv`.
- CSV exports contain the correct headers for sales, division, fabric, and trims.
- HTML output escapes user-controlled values.
- SAP errors are shown as controlled application errors, not stack traces.

### PHPUnit setup example

After Composer is available, create `phpunit.xml` and a `tests/` directory. A minimal command is:

```powershell
vendor\bin\phpunit --colors=always
```

Organize tests as `tests/Unit`, `tests/Integration`, and `tests/Feature`. Keep fixtures in `tests/Fixtures`; never store production SAP responses there.

## 8. Integration and API Testing

Use a fake SAP server that can return controlled responses for:

- One page, multiple pages, empty pages, and more than `max_rows`.
- OData v2 and v4 JSON shapes.
- 401, 403, 404, 429, and 500 responses.
- Invalid JSON, slow responses, and connection failures.
- Rows containing nulls, zeroes, long strings, duplicate values, and unexpected fields.

Verify the local application with PowerShell or Postman. Examples:

```powershell
curl.exe -I http://localhost:8888/sap_reports/
curl.exe -i http://localhost:8888/sap_reports/unknown-route
curl.exe -i "http://localhost:8888/sap_reports/sales?page=1&per_page=25&q=10001"
curl.exe -i "http://localhost:8888/sap_reports/division?export=csv"
curl.exe -i "http://localhost:8888/sap_reports/fabric?so=12345"
```

Check status code, redirect behavior, response headers, HTML, CSV content, and server logs. Confirm unauthenticated users cannot access report routes and users without report access receive the expected denial response.

## 9. Automated Browser Testing

Recommended tool: Playwright. Install it in a separate test workspace or add it to the project after confirming the team uses Node tooling:

```powershell
npm.cmd install --save-dev @playwright/test
npx.cmd playwright install chromium
```

Automate these scenarios:

1. Login with a test account and verify the report catalogue.
2. Open each of the five report routes.
3. Search by a known sales order and verify filtered results.
4. Change page size and navigate between pages.
5. Use date filters and verify the result count.
6. Export sales, division, fabric, and trims CSV files.
7. Open fabric and trims with no sales order and verify the empty state.
8. Simulate an SAP outage and verify an understandable error state.
9. Log out, then verify protected routes redirect or deny access.
10. Test keyboard navigation, visible focus, labels, table headings, color contrast, and responsive layouts at 1280px and 390px widths.

Suggested command after tests exist:

```powershell
npx.cmd playwright test --reporter=html
```

Save screenshots, traces, and videos only for failed tests, and remove sensitive data before sharing them.

## 10. Performance and Reliability Testing

Run performance tests against local or staging with synthetic data and a fixed SAP stub. Do not load-test the real SAP system without approval.

Measure:

- Time to first byte for catalogue and report pages.
- Response time for first page and CSV export.
- Memory usage while processing `max_rows`.
- Cache hit and cache miss behavior.
- Behavior during slow SAP responses and repeated SAP failures.
- Concurrent users opening different report types.

Example tools:

```powershell
winget install k6.k6
k6 run .\tests\performance\reports.js
```

Set pass criteria before running, for example: no 5xx responses, no authentication bypass, no unbounded memory growth, and agreed p95 response-time limits for catalogue, report, and export requests.

## 11. Authorized Penetration Testing

Perform these checks only on local or staging systems with written authorization and test accounts:

### Authentication and authorization

- Access every report route without a session.
- Access reports with an authenticated user lacking report permission.
- Reuse, modify, expire, and fixate session cookies.
- Verify logout invalidates the session.
- Test CSRF protection on every state-changing portal action.

### Input and output security

- Test `r`, `page`, `per_page`, `q`, `so`, `from`, `to`, and `export` with quotes, HTML, SQL metacharacters, very long values, encoded values, and null bytes.
- Verify there is no reflected or stored XSS in filters, tables, errors, or CSV output.
- Verify SQL injection is not possible through filters or identifiers.
- Test CSV formula injection using values beginning with `=`, `+`, `-`, and `@` in a safe fixture.
- Verify path traversal cannot select a view or local file.
- Verify error pages do not reveal filesystem paths, credentials, SAP URLs, or stack traces.

### HTTP and deployment security

- Confirm HTTPS is enforced in staging/production.
- Review HSTS, CSP, frame, content-type, referrer, and cookie security headers.
- Confirm directory listing is disabled and `.env`, backups, logs, and source files are not downloadable.
- Confirm `app/`, `config/`, and `tests/` cannot be served as public files.
- Check rate limiting and lockout behavior on login.
- Check dependency and PHP version vulnerabilities.

Recommended tools for an authorized assessment:

- OWASP ZAP baseline scan for passive checks.
- Burp Suite for manual authenticated workflow testing.
- Nikto for web-server misconfiguration checks.
- Nuclei only with a reviewed template set and an approved target.
- Composer audit and a server vulnerability scanner for dependency/runtime review.

Example passive scan against local MAMP:

```powershell
docker run --rm -t owasp/zap2docker-stable zap-baseline.py -t http://host.docker.internal:8888/sap_reports/
```

Do not treat an automated scanner result as proof of a vulnerability. Reproduce, assess impact, fix, and retest manually.

## 12. Regression Checklist

Before each release, verify:

- [ ] PHP lint and static analysis pass.
- [ ] Unit tests pass with SAP disabled or mocked.
- [ ] Fake-SAP integration tests pass for success, empty, malformed, timeout, and HTTP error cases.
- [ ] Login, logout, and access-control tests pass.
- [ ] All five report routes load.
- [ ] Search, date filters, pagination, and page-size limits work.
- [ ] CSV exports open correctly and contain expected columns.
- [ ] No sensitive values appear in HTML, headers, logs, screenshots, or test output.
- [ ] Browser tests pass on desktop and mobile viewport sizes.
- [ ] Performance checks meet the agreed p95 and memory limits.
- [ ] Security scan findings are triaged and accepted findings are documented.
- [ ] Deployment smoke test passes after release.

## 13. Defect Report Template

For every failure, record:

```text
Title:
Environment and URL:
Build/commit:
User role:
Preconditions and test data:
Steps to reproduce:
Expected result:
Actual result:
HTTP status and response:
Logs or sanitized screenshot:
Severity and impact:
Suggested fix:
Retest result:
```

## 14. Minimum Release Gate

Do not release until all of the following are true:

- No critical or high authentication, authorization, injection, secret-exposure, or data-leakage findings remain.
- Unit, integration, and browser smoke tests pass.
- SAP outage and malformed-response behavior is controlled.
- CSV exports and report totals have been checked against a known fixture.
- Credentials have been rotated and are supplied through secure environment configuration.
- The release has a recorded test report and rollback plan.


