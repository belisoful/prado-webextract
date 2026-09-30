# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `TWebExtractor`, a module keeping one extract per web address in a table: a queue fetched by
  `processQueue()` from cron or after each response, `extract()` for an immediate fetch, retries
  with doubling delays for transient failures, leases so several workers can share the queue,
  and the events `onExtracting`, `onExtracted`, and `onExtractFailed`.
- `TWebFetcher`, safe fetching on Symfony HttpClient: http and https only, no credentials in
  addresses, private networks refused (at every redirect and in IPv6 transition forms), and
  limits on body size, time, and redirects. Bodies are converted to UTF-8.
- `TWebPageReader`, reading a page's article text with Readability and its metadata with Embed.
  JSON-LD contexts are made inline first, because the JSON-LD library would otherwise fetch them
  outside the fetcher's checks.
- `TWebExtract`, `TWebFetchResult`, `TWebFetchException`, and `TWebNoRequestClient`.
