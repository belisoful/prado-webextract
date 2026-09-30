prado-webextract
================

Fetch web pages safely and reduce them to clean text and metadata, for PRADO.

Give it a web address and it keeps, in one table row per address:

- the article as plain text, without the navigation, advertising, and footer around it;
- the title, summary, author, site name, language, representative image, and publication date;
- keywords, the canonical address, feeds, and a provider's embed code for a video, when the page
  offers them.

It is built for addresses the public submits. Private, loopback, and link-local networks are
refused, including behind redirects and in IPv6 forms, and every request has limits on size,
time, and redirects.

The work is done by established libraries:

| Job | Library |
| --- | --- |
| Fetching, and the private network checks | [symfony/http-client](https://symfony.com/doc/current/http_client.html) |
| The article text | [fivefilters/readability.php](https://github.com/fivefilters/readability.php), a port of Firefox's Reader View |
| Metadata: Open Graph, Twitter cards, JSON-LD, oEmbed | [embed/embed](https://github.com/php-embed/Embed) |

Installation
------------

```
composer require belisoful/prado-webextract
```

Configuration
-------------

```xml
<modules>
	<module id="db" class="TDataSourceConfig">
		<database ConnectionString="mysql:host=localhost;dbname=site" Username="site" Password="..." />
	</module>
	<module id="webextract" class="TWebExtractor" ConnectionID="db" />
</modules>
```

The table (`web_extracts` by default) is created on first use.

| Property | Default | |
| --- | --- | --- |
| `TableName` | `web_extracts` | |
| `AutoCreateTables` | `true` | |
| `MaxAttempts` | `3` | tries before an address fails for good |
| `RetryDelay` | `3600` | seconds before the first retry; each later one waits twice as long |
| `LeaseTime` | `300` | seconds a queue worker holds an address |
| `StripParameters` | tracking parameters | query parameters taken off an address (`utm_*`, `fbclid`, ...) |
| `ProcessOnEndRequest` | `0` | addresses fetched after each response, for hosts with no cron |
| `Timeout` | `10` | seconds to wait for a server |
| `MaxDuration` | `30` | seconds a whole fetch may take |
| `MaxBytes` | `2097152` | the largest page read |
| `MaxRedirects` | `5` | |
| `UserAgent` | identifies the package | |
| `AllowPrivateNetworks` | `false` | never for addresses the public submits |
| `ReadMetadata` | `true` | read metadata with Embed, which may ask a provider's oEmbed service |

Use
---

Fetching takes seconds, so queue addresses as they arrive and fetch them from cron:

```php
$extractor = $this->getApplication()->getModule('webextract');
$extractor->queueAll($urls);
```

```xml
<module id="cron" class="Prado\Util\Cron\TCronModule">
	<job Name="webextract" Schedule="* * * * *" Task="webextract->processQueue(10)" />
</module>
```

Then read what was learned:

```php
foreach ($extractor->findAll($urls) as $url => $extract) {
	if ($extract->getIsExtracted()) {
		echo $extract->getTitle(), ' - ', $extract->getSiteName(), "\n", $extract->getDescription();
	}
}
```

`extract($url)` fetches one address immediately for a caller that can wait. An address is
fetched once; `extract($url, true)` fetches it again.

A failure that could go differently later (a timeout, a 503) is retried; one that cannot (a 404,
a private address, a page too large) fails at once. PDFs, images, and other files that are not
text are marked unsupported without being downloaded.

Events: `onExtracting` (after reading, before storing, so a handler can change the extract, for
example putting a video's transcript in its text), `onExtracted`, and `onExtractFailed`.

The text is plain text and safe to show escaped. The `embed` meta entry holds a provider's embed
HTML, which is third-party markup: decide whether to trust the provider before showing it.

Development
-----------

`composer fulltest` runs the full check: compile, code style, static analysis, unit tests.
`composer integration` installs the package into a throwaway PRADO application and checks it.
See AGENTS.md for the conventions this package holds to, and its Security section before
changing how anything is fetched.
