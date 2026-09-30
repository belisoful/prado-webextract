# AGENTS.md

## What This Is

`belisoful/prado-webextract`: Fetch web pages safely and reduce them to clean text and metadata, for PRADO.

## Commands

| Command | What it does |
| --- | --- |
| `composer lint` | Compile-check `src/` and `tests/` with `php -l` |
| `composer cs` | Check code style without writing (what CI runs) |
| `composer fix` | Apply php-cs-fixer |
| `composer stan` | PHPStan at the level in `phpstan.neon.dist` |
| `composer unittest` | The unit suite |
| `composer coverage` | Unit suite with a text and Clover report |
| `composer integration` | The Composer install test (needs a PRADO checkout, default `../prado.master`) |
| `composer fulltest` | The full check, in order |

Run one test or file with
`vendor/bin/phpunit --testsuite unit --filter <test, class, or directory>`. Never add or change
phpunit options beyond `--filter`; the options in `phpunit.xml` are the project's.

**The full check is `composer fulltest`: `php -l`, php-cs-fixer, PHPStan, PHPUnit, in that
order. All four must pass before code is ready to commit.**

## Architecture

```
src/TWebExtractor.php        the bootstrap module: the queue, the table, retries, events
src/TWebExtract.php          one address's extract (text and metadata), a plain record
src/TWebFetcher.php          safe fetching: Symfony HttpClient with the private network checks
src/TWebFetchResult.php      what a fetch returned, body already in UTF-8
src/TWebFetchException.php   a failed fetch, and whether retrying could help
src/TWebPageReader.php       Readability for the text, Embed for the metadata
src/TWebNoRequestClient.php  a PSR-18 client that refuses, for Embed when no client is given
config/classes.json        Prado3 short name => FQN, registered by extra.prado.class-map
config/errorMessages.txt   message code => text, registered by extra.prado.error-messages
tests/unit/Package/        the package standard checks, inherited by every extension
tests/integration/         installs the package into a throwaway consumer and checks the wiring
tests/test_tools/          phpunit and phpstan bootstraps, the coverage floor check
agents/                    working files for coding agents
```

## Security

Every address this package fetches was chosen by someone else, so fetching is server-side
request forgery waiting to happen. Hold these:

- Every request goes through `TWebFetcher::getHttpClient()` or `getPsr18Client()`, which wrap
  Symfony's `NoPrivateNetworkHttpClient`. No library may be handed a client of its own making,
  and no code may call `file_get_contents`, curl, or a stream wrapper on a fetched address.
- `ml/json-ld` (under Embed) fetches remote `@context` documents with `file_get_contents` and
  ignores its own loader setting when called without options, as Embed calls it.
  `TWebPageReader::inlineJsonLdContexts()` removes those addresses before Embed sees the page;
  do not bypass it.
- The Symfony floors in composer.json are security floors: `symfony/http-client` 6.4.17 (DNS
  pinning and redirect checks, and a working `stream()` through the decorator) and
  `symfony/http-foundation` 6.4.41 / 7.4.13 / 8.0.13 (CVE-2026-48736, IPv6 transition forms of
  private addresses). Do not lower them.

## Invariants

These are what the package standard checks enforce. Breaking one fails the unit suite.

- Every type in `src/` has a namespace matching its path under the single PSR-4 root, and a file
  name matching the type it declares.
- Classes, traits, and enums are prefixed `T`; interfaces are prefixed `I`; both continue in
  PascalCase.
- Every class, interface, and enum in `src/` is listed in `config/classes.json`, mapped to its
  fully qualified name. Traits are exempt: they have no short name to resolve.
- Every error code raised in `src/` has text in `config/errorMessages.txt`, and every message in
  that file is raised somewhere in `src/`. No duplicate keys.
- Extension settings live under `extra.prado`. The un-nested `extra.bootstrap` is deprecated; do
  not reintroduce it.
- The bootstrap class autoloads, is a `TModule`, and lives under the PSR-4 root.

Two more that the checks cannot see:

- Changes stay backward compatible.
- New public classes and methods carry PHPDoc with `@param`, `@return`, `@throws`, `@author`,
  and `@since` set to the next release version. Dynamic events (`dy*`) are documented with
  `@method` on the class, because no class declares them.

## Conventions

- Tabs for indentation. PascalCase classes, camelCase methods and variables,
  SCREAMING_SNAKE_CASE constants. `php-cs-fixer` settles the rest; run `composer fix` rather
  than hand-formatting.
- Properties are a getter and setter pair (`getPropertyA`/`setPropertyA`), with the setter
  coercing through `TPropertyValue`.
- Throw PRADO exceptions (`TConfigurationException`, `TInvalidDataValueException`,
  `TInvalidOperationException`) with a message code, never a literal string.
- Page templates are `.page`, control templates `.tpl`.
- A package declares its own messages and class map in `config/`. `TPluginModule` also looks for
  an `errorMessages.txt` beside the module class; this package does not use that path, because
  `extra.prado.error-messages` applies even when the module is not configured.

## PRADO framework notes

The framework is at `vendor/pradosoft/prado/`, with its own `AGENTS.md`. What matters most here:

- Everything descends from `TComponent`, which supplies dynamic properties (`__get`/`__set`),
  behaviors, and dynamic events.
- `dy*` methods are dynamic events implemented by attached behaviors, not by the calling class.
  The first parameter is filtered and returned.
- `fx*` methods are global events, registered according to `getAutoGlobalListen()`.
- Events are raised in priority order.
- Application lifecycle: `onInitComplete` → `onBeginRequest` → `onLoadState` →
  `onLoadStateComplete` → `onAuthentication` → `onAuthenticationComplete` → `onAuthorization` →
  `onAuthorizationComplete` → `onPreRunService` → `runService` → `onSaveState` →
  `onSaveStateComplete` → `onPreFlushOutput` → `flushOutput` → `onEndRequest` (or `onError`).
- `TPageService::onPreRunPage` gives a module access to the page lifecycle before it runs.
- Application configuration is XML or PHP.

## Testing

- New code ships with unit tests covering typical cases, edge cases, and error paths.
- Tests are isolated: no shared state between them.
- When working on one class or cluster, run only those tests with `--filter`.

## Safeguards -- ANTI-PATTERNS

Required without exception:

- NEVER run these `git` commands without asking first: clone, checkout, mv, restore, rm, branch,
  add, commit, merge, rebase, reset, pull, push, fetch.
- NEVER run `rm` on any path without asking first.
- NEVER remove composer `--dev` dependencies; they are required to develop the package.
- NEVER erase or overwrite files while unit testing and fixing. The file changes are the thing
  under test.
- NEVER delete a folder or file until the task it belongs to is completely finished.
