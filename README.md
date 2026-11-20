# elastic-apm-agent-v2
PHP Agent for Elastic APM v2 API version

---

### modified version


## PHP 8.5 migration

The Composer package is still `ivoglent/elastic-apm-agent-v2` and the PSR-4
namespace is still `Elastic\Apm\PhpAgent\`. Use the fork
`guigpm/elastic-apm-agent-v2` as a Composer VCS repository after publishing the
validated branch. The branch constraint is `dev-codex/php-8.5`. Pin the resolved
commit in the consuming project's lock file.

| Dependency | Constraint | Validated version |
| --- | --- | --- |
| PHP | `~8.5.13` | 8.5.13 |
| Guzzle | `^7.9` | 7.16.3 |
| Ramsey UUID | `^4.7` | 4.10.0 |
| PHPUnit (development) | `^13.0` | 13.4.8 |

PHP 7.4 and applications requiring Guzzle 6 or UUID 3 need the previous agent
revision. Guzzle 7's `GuzzleHttp\ClientInterface` is still the interface for
the optional client passed to `Config`. Update custom implementations of that
interface to Guzzle 7. `Agent::stopTransaction()` still finalizes and sends the
batch. `Agent::send()` still accepts a PSR-7 request and Guzzle options, returns
true for HTTP 2xx and false for other responses returned by the client.
Exceptions from Guzzle still propagate. A returned response clears the batch.
A thrown exception leaves the batch in memory for an explicit `send()` retry.

Generated transaction and span IDs now have 16 hexadecimal characters. Trace
and error IDs have 32. An ID passed to `startTransaction()` is preserved.
Nested spans link to their active parent. Errors include their trace and
transaction IDs, and the exception stack trace is a JSON array. A span's
`db` and `http` context objects are serialized directly under `context`.
Ending an unknown span raises the agent's `RuntimeException`. The service
environment defaults to an empty string when omitted.

Validation on PHP 8.5.13 CLI in Alpine 3.24.4 with Composer 2.11.2:

```sh
composer update --no-interaction --prefer-dist
composer install --no-interaction --prefer-dist
composer validate --strict
composer check-platform-reqs
php -d error_reporting=E_ALL vendor/bin/phpunit --bootstrap vendor/autoload.php --fail-on-all-issues tests
composer audit --locked
```

The transport tests use Guzzle's mock handler. They check the intake URI,
headers, NDJSON and event relationships, a 2xx response, HTTP 503 with and
without Guzzle HTTP exceptions, retry after an HTTP exception, and a connection
exception. PHP-FPM request variables are simulated. The validation used no
live APM Server.
