<?php

/**
 * Test doubles for the web extractor.
 *
 * The extractor reaches its database through TDbPropertiesTrait, which asks
 * getCustomDbConnection() when no ConnectionID names a TDataSourceConfig module. Overriding that
 * hands the extractor an in-memory SQLite database, so the tests run the real queries.
 *
 * Pages come from a Symfony MockHttpClient under the real fetcher, so the private network checks
 * and the limits are the real ones too. NoPrivateNetworkHttpClient resolves a host name before
 * connecting; TestWebFetcher answers that from a fixed table instead of DNS, so no test touches
 * the network.
 */

use Belisoful\Prado\Util\WebExtract\TWebExtractor;
use Belisoful\Prado\Util\WebExtract\TWebFetcher;
use Prado\Data\TDbConnection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TestWebFetcher extends TWebFetcher
{
	/** @var array<string, string> host name => the address it resolves to */
	public array $hosts = [
		'news.example' => '93.184.215.14',
		'www.news.example' => '93.184.215.14',
		'other.example' => '93.184.215.15',
		'internal.example' => '10.0.0.5',
	];

	protected function getRequestOptions(): array
	{
		return ['resolve' => $this->hosts] + parent::getRequestOptions();
	}
}

class TestWebExtractor extends TWebExtractor
{
	/** @var null|\Prado\Data\TDbConnection the connection this extractor was handed */
	private ?TDbConnection $_testConnection = null;

	public function setTestDbConnection(TDbConnection $connection): void
	{
		$this->_testConnection = $connection;
	}

	protected function getCustomDbConnection(): ?TDbConnection
	{
		return $this->_testConnection;
	}
}

class WebExtractTestTools
{
	/**
	 * @param callable|MockResponse|MockResponse[] $responses what the mock server answers
	 * @param array $properties extractor properties to set before init, as name => value
	 * @return \TestWebExtractor an initialized extractor on a fresh database, fetching from the mock
	 */
	public static function createExtractor($responses = [], array $properties = []): TestWebExtractor
	{
		$connection = new TDbConnection('sqlite::memory:');
		$connection->setActive(true);
		$extractor = new TestWebExtractor();
		$extractor->setTestDbConnection($connection);
		$extractor->setFetcher(self::createFetcher($responses));
		$extractor->setID('webextract');
		foreach ($properties as $name => $value) {
			$extractor->{'set' . $name}($value);
		}
		$extractor->init(null);

		return $extractor;
	}

	/**
	 * @param callable|MockResponse|MockResponse[] $responses what the mock server answers
	 * @return \TestWebFetcher a fetcher over the mock
	 */
	public static function createFetcher($responses = []): TestWebFetcher
	{
		$fetcher = new TestWebFetcher();
		$fetcher->setTransport(new MockHttpClient($responses));

		return $fetcher;
	}

	/**
	 * @param string $body the page
	 * @param string $contentType the Content-Type header
	 * @param array $info more of what MockResponse takes, such as 'http_code'
	 * @return MockResponse a response carrying the page
	 */
	public static function page(string $body, string $contentType = 'text/html; charset=utf-8', array $info = []): MockResponse
	{
		return new MockResponse($body, $info + ['response_headers' => ['Content-Type: ' . $contentType]]);
	}

	/**
	 * @return string an article page, with Open Graph tags, a byline, and furniture around the
	 *   article that should not end up in its text
	 */
	public static function articleHtml(): string
	{
		$paragraphs = '';
		foreach (['The council voted on Tuesday to close the old bridge to traffic.', 'Engineers had warned for years that the supports were failing, and a report last spring found cracks in two of the four piers.', 'Residents on the east bank will now face a detour of eleven kilometres, and local businesses say they expect to lose trade.', 'A replacement is planned, but the money for it has not been found, and work is not expected to start before next year.'] as $sentence) {
			$paragraphs .= '<p>' . str_repeat($sentence . ' ', 3) . "</p>\n";
		}

		return <<<HTML
			<!DOCTYPE html>
			<html lang="en-GB">
			<head>
				<meta charset="utf-8">
				<title>Bridge closes | Riverside Gazette</title>
				<meta property="og:title" content="Old bridge closes after council vote">
				<meta property="og:description" content="The council has closed the old bridge after engineers found cracks.">
				<meta property="og:site_name" content="Riverside Gazette">
				<meta property="og:image" content="https://news.example/images/bridge.jpg">
				<meta property="article:published_time" content="2026-09-01T09:30:00+00:00">
				<meta name="keywords" content="bridge, council, transport">
				<link rel="canonical" href="https://news.example/2026/09/bridge-closes">
			</head>
			<body>
				<nav><ul><li><a href="/">Home</a></li><li><a href="/news">News</a></li><li><a href="/sport">Sport</a></li></ul></nav>
				<div class="advert">Buy one get one free at Bob's Carpets</div>
				<article>
					<h1>Old bridge closes after council vote</h1>
					<p class="byline">By Jane Porter</p>
					{$paragraphs}
					<ul><li>Detour: eleven kilometres</li><li>Reopening: not planned</li></ul>
				</article>
				<footer><p>Copyright Riverside Gazette. All rights reserved. Privacy policy. Cookie settings.</p></footer>
				<script>trackEverything();</script>
			</body>
			</html>
			HTML;
	}
}
