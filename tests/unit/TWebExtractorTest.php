<?php

use Belisoful\Prado\Util\WebExtract\TWebExtract;
use Belisoful\Prado\Util\WebExtract\TWebExtractor;
use Belisoful\Prado\Util\WebExtract\TWebFetchException;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TInvalidOperationException;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once(__DIR__ . '/../test_tools/WebExtractTestTools.php');

class TWebExtractorTest extends PHPUnit\Framework\TestCase
{
	/**
	 * @param int $calls counts the requests the server answers
	 * @param null|callable $answer what the server answers, the article by default
	 * @return callable a mock server
	 */
	private function server(int &$calls, ?callable $answer = null): callable
	{
		return function ($method, $url) use (&$calls, $answer) {
			$calls++;

			return $answer ? $answer($url) : WebExtractTestTools::page(WebExtractTestTools::articleHtml());
		};
	}

	/**
	 * @param TWebExtractor $extractor the extractor
	 * @param int $id the extract
	 * @return array its row
	 */
	private function row(TWebExtractor $extractor, int $id): array
	{
		return $extractor->getDbConnection()->createCommand('SELECT * FROM web_extracts WHERE id = ' . $id)->queryRow();
	}

	/**
	 * @param TWebExtractor $extractor the extractor
	 * @param int $id an extract waiting for a retry, to be made due now
	 */
	private function makeDue(TWebExtractor $extractor, int $id): void
	{
		$extractor->getDbConnection()->createCommand('UPDATE web_extracts SET next_attempt_time = ' . (time() - 1) . ' WHERE id = ' . $id)->execute();
	}

	public function testNormalizeUrl()
	{
		$extractor = WebExtractTestTools::createExtractor();

		$this->assertSame('https://news.example/', $extractor->normalizeUrl('HTTPS://News.Example'));
		$this->assertSame('http://news.example/a/B', $extractor->normalizeUrl('  http://news.example:80/a/B#comments '));
		$this->assertSame('https://news.example/', $extractor->normalizeUrl('https://news.example:443/'));
		$this->assertSame('https://news.example:8443/x', $extractor->normalizeUrl('https://news.example:8443/x'));
		$this->assertSame(
			'https://news.example/story?id=7&page=2',
			$extractor->normalizeUrl('https://news.example/story?utm_source=tw&id=7&fbclid=abc&UTM_Medium=social&page=2&gclid=x')
		);
		$this->assertSame('https://news.example/story', $extractor->normalizeUrl('https://news.example/story?utm_campaign=x'));
	}

	public function testNormalizeUrlRefusesWhatCannotBeFetched()
	{
		$extractor = WebExtractTestTools::createExtractor();

		foreach (['' => 'webextract_url_invalid', 'news.example/story' => 'webextract_url_invalid',
			'ftp://news.example/' => 'webextract_url_scheme_refused', 'https://u:p@news.example/' => 'webextract_url_credentials_refused',
			'https://news.example/' . str_repeat('a', 2048) => 'webextract_url_invalid'] as $url => $code) {
			try {
				$extractor->normalizeUrl($url);
				$this->fail($url . ' should have been refused.');
			} catch (TWebFetchException $e) {
				$this->assertSame($code, $e->getErrorCode(), $url);
			}
		}
	}

	public function testTheParametersStrippedCanBeChanged()
	{
		$extractor = WebExtractTestTools::createExtractor([], ['StripParameters' => 'ref, session*']);

		$this->assertSame('ref,session*', $extractor->getStripParameters());
		$this->assertSame('https://news.example/?utm_source=x', $extractor->normalizeUrl('https://news.example/?ref=home&utm_source=x&sessionid=9'));

		$extractor->setStripParameters('');
		$this->assertSame('', $extractor->getStripParameters());
		$this->assertSame('https://news.example/?ref=home', $extractor->normalizeUrl('https://news.example/?ref=home'));

		$this->assertSame(TWebExtractor::DEFAULT_STRIP_PARAMETERS, WebExtractTestTools::createExtractor()->getStripParameters());
	}

	public function testQueueingStoresAWaitingExtractOncePerAddress()
	{
		$calls = 0;
		$extractor = WebExtractTestTools::createExtractor($this->server($calls));
		$extract = $extractor->queue('https://news.example/story?utm_source=feed');

		$this->assertGreaterThan(0, $extract->getId());
		$this->assertTrue($extract->getIsPending());
		$this->assertSame('https://news.example/story', $extract->getUrl());
		$this->assertSame($extract->getId(), $extractor->queue('HTTPS://NEWS.EXAMPLE/story#top')->getId());
		$this->assertSame(1, $extractor->countByStatus(TWebExtractor::STATUS_PENDING));
		$this->assertSame(0, $calls);
	}

	public function testQueueAllSkipsWhatCannotBeFetched()
	{
		$extractor = WebExtractTestTools::createExtractor();
		$extracts = $extractor->queueAll(['https://news.example/a', 'ftp://news.example/b', 'https://news.example/c']);

		$this->assertSame(['https://news.example/a', 'https://news.example/c'], array_keys($extracts));
	}

	public function testExtractFetchesReadsAndStores()
	{
		$calls = 0;
		$extractor = WebExtractTestTools::createExtractor($this->server($calls));
		$extract = $extractor->extract('https://news.example/2026/09/bridge-closes');

		$this->assertTrue($extract->getIsExtracted());
		$this->assertSame('Old bridge closes after council vote', $extract->getTitle());
		$this->assertSame(1, $extract->getAttempts());
		$this->assertGreaterThan(0, $extract->getFetchedTime());

		$stored = $extractor->find('https://news.example/2026/09/bridge-closes');
		$this->assertSame($extract->getId(), $stored->getId());
		$this->assertSame(TWebExtractor::STATUS_EXTRACTED, $stored->getStatus());
		foreach (['Title', 'Description', 'Author', 'SiteName', 'Language', 'ImageUrl', 'PublishedTime', 'Text', 'WordCount', 'Meta', 'FinalUrl', 'HttpStatus', 'ContentType', 'Attempts', 'FetchedTime'] as $property) {
			$this->assertSame($extract->{'get' . $property}(), $stored->{'get' . $property}(), $property);
		}
		$this->assertSame('news.example', $stored->getHost());
		$this->assertSame(1, $calls);
	}

	public function testExtractDoesNotFetchTwiceUnlessAskedTo()
	{
		$calls = 0;
		$extractor = WebExtractTestTools::createExtractor($this->server($calls));
		$extractor->extract('https://news.example/story');
		$extractor->extract('https://news.example/story');
		$this->assertSame(1, $calls);

		$again = $extractor->extract('https://news.example/story', true);
		$this->assertSame(2, $calls);
		$this->assertSame(2, $again->getAttempts());
		$this->assertTrue($again->getIsExtracted());
	}

	public function testAPermanentFailureIsRecordedAndNotTriedAgain()
	{
		$calls = 0;
		$failed = [];
		$extractor = WebExtractTestTools::createExtractor($this->server($calls, fn () => new MockResponse('', ['http_code' => 404])));
		$extractor->attachEventHandler('onExtractFailed', function ($sender, $extract) use (&$failed) {
			$failed[] = $extract;
		});
		$extract = $extractor->extract('https://news.example/gone');

		$this->assertSame(TWebExtractor::STATUS_FAILED, $extract->getStatus());
		$this->assertSame(404, $extract->getHttpStatus());
		$this->assertStringContainsString('404', $extract->getError());
		$this->assertCount(1, $failed);
		$this->assertSame(0, $extractor->processQueue());
		$extractor->extract('https://news.example/gone');
		$this->assertSame(1, $calls);
	}

	public function testABlockedAddressFailsAtOnce()
	{
		$calls = 0;
		$extractor = WebExtractTestTools::createExtractor($this->server($calls));
		$extract = $extractor->extract('http://169.254.169.254/latest/meta-data/');

		$this->assertSame(TWebExtractor::STATUS_FAILED, $extract->getStatus());
		$this->assertStringContainsString('169.254.169.254', $extract->getError());
		$this->assertSame(0, $calls);
	}

	public function testATransientFailureIsTriedAgainLaterUntilTheAttemptsRunOut()
	{
		$calls = 0;
		$extractor = WebExtractTestTools::createExtractor(
			$this->server($calls, fn () => new MockResponse('', ['http_code' => 503])),
			['MaxAttempts' => 3, 'RetryDelay' => 600]
		);
		$before = time();
		$extract = $extractor->extract('https://news.example/busy');

		$this->assertTrue($extract->getIsPending());
		$this->assertSame(1, $extract->getAttempts());
		$this->assertGreaterThanOrEqual($before + 600, $extract->getNextAttemptTime());
		// Not due yet.
		$this->assertSame(0, $extractor->processQueue());

		$this->makeDue($extractor, $extract->getId());
		$this->assertSame(1, $extractor->processQueue());
		$this->assertSame(2, $extractor->find('https://news.example/busy')->getAttempts());
		$this->assertSame(0, $extractor->processQueue());
		$this->makeDue($extractor, $extract->getId());
		$this->assertSame(1, $extractor->processQueue());

		$final = $extractor->find('https://news.example/busy');
		$this->assertSame(TWebExtractor::STATUS_FAILED, $final->getStatus());
		$this->assertSame(3, $final->getAttempts());
		$this->assertSame(0, $extractor->processQueue());
		$this->assertSame(3, $calls);
	}

	public function testTheRetryDelayDoubles()
	{
		$extractor = WebExtractTestTools::createExtractor(fn () => new MockResponse('', ['http_code' => 503]), ['MaxAttempts' => 5, 'RetryDelay' => 100]);
		$first = $extractor->extract('https://news.example/busy');
		$second = $extractor->extract('https://news.example/busy', true);

		$this->assertEqualsWithDelta(100, $first->getNextAttemptTime() - $first->getFetchedTime(), 1);
		$this->assertEqualsWithDelta(200, $second->getNextAttemptTime() - $second->getFetchedTime(), 1);
	}

	public function testWhatIsNotTextIsUnsupported()
	{
		$failed = 0;
		$extractor = WebExtractTestTools::createExtractor([WebExtractTestTools::page('%PDF', 'application/pdf')]);
		$extractor->attachEventHandler('onExtractFailed', function () use (&$failed) {
			$failed++;
		});
		$extract = $extractor->extract('https://news.example/report.pdf');

		$this->assertSame(TWebExtractor::STATUS_UNSUPPORTED, $extract->getStatus());
		$this->assertSame('application/pdf', $extract->getContentType());
		$this->assertSame('', $extract->getError());
		$this->assertSame(1, $failed);
	}

	public function testProcessQueueFetchesWhatIsWaitingOldestFirst()
	{
		$fetched = [];
		$extractor = WebExtractTestTools::createExtractor(function ($method, $url) use (&$fetched) {
			$fetched[] = $url;

			return WebExtractTestTools::page('<p>Page</p>');
		});
		$extractor->queueAll(['https://news.example/1', 'https://news.example/2', 'https://news.example/3']);

		$this->assertSame(2, $extractor->processQueue(2));
		$this->assertSame(['https://news.example/1', 'https://news.example/2'], $fetched);
		$this->assertSame(1, $extractor->processQueue(10));
		$this->assertSame(0, $extractor->processQueue(10));
		$this->assertSame(3, $extractor->countByStatus(TWebExtractor::STATUS_EXTRACTED));
	}

	public function testProcessQueueLeavesWhatAnotherWorkerHolds()
	{
		$extractor = WebExtractTestTools::createExtractor([WebExtractTestTools::page('<p>Page</p>')]);
		$extract = $extractor->queue('https://news.example/held');
		$extractor->getDbConnection()->createCommand('UPDATE web_extracts SET locked_until = ' . (time() + 60) . ' WHERE id = ' . $extract->getId())->execute();

		$this->assertSame(0, $extractor->processQueue());

		$extractor->getDbConnection()->createCommand('UPDATE web_extracts SET locked_until = ' . (time() - 1) . ' WHERE id = ' . $extract->getId())->execute();
		$this->assertSame(1, $extractor->processQueue());
		$this->assertSame(0, (int) $this->row($extractor, $extract->getId())['locked_until']);
	}

	public function testEventsAreRaisedAndOnExtractingCanChangeWhatIsStored()
	{
		$extracted = [];
		$extractor = WebExtractTestTools::createExtractor([WebExtractTestTools::page(WebExtractTestTools::articleHtml())]);
		$extractor->attachEventHandler('onExtracting', function ($sender, TWebExtract $extract) {
			$extract->setText('A transcript, in place of the page.');
			$extract->setMeta(['transcript' => true] + $extract->getMeta());
		});
		$extractor->attachEventHandler('onExtracted', function ($sender, $extract) use (&$extracted) {
			$extracted[] = $extract;
		});
		$extractor->extract('https://news.example/video');

		$stored = $extractor->find('https://news.example/video');
		$this->assertSame('A transcript, in place of the page.', $stored->getText());
		$this->assertTrue($stored->getMeta()['transcript']);
		$this->assertCount(1, $extracted);
	}

	public function testFindAllIsKeyedByTheAddressesAsGiven()
	{
		$extractor = WebExtractTestTools::createExtractor();
		$extract = $extractor->queue('https://news.example/a');
		$found = $extractor->findAll(['https://news.example/a?utm_source=x', 'https://news.example/a', 'https://news.example/none', 'not a url']);

		$this->assertSame(['https://news.example/a?utm_source=x', 'https://news.example/a'], array_keys($found));
		$this->assertSame($extract->getId(), $found['https://news.example/a']->getId());
		$this->assertSame([], $extractor->findAll([]));
		$this->assertSame([], $extractor->findAll(['ftp://x/']));
	}

	public function testFindAndFindById()
	{
		$extractor = WebExtractTestTools::createExtractor();
		$extract = $extractor->queue('https://news.example/a');

		$this->assertSame($extract->getId(), $extractor->findById($extract->getId())->getId());
		$this->assertNull($extractor->findById(999));
		$this->assertNull($extractor->find('https://news.example/b'));
		$this->assertNull($extractor->find('not a url'));
	}

	public function testRemove()
	{
		$extractor = WebExtractTestTools::createExtractor();
		$extractor->queue('https://news.example/a');

		$this->assertTrue($extractor->remove('https://news.example/a'));
		$this->assertNull($extractor->find('https://news.example/a'));
		$this->assertFalse($extractor->remove('https://news.example/a'));
	}

	public function testWhatIsStoredFitsTheColumns()
	{
		$long = str_repeat('é', 600);
		$html = '<html><head><meta property="og:title" content="' . $long . '">'
			. '<meta property="article:published_time" content="2999-01-01T00:00:00Z"></head><body><p>x</p></body></html>';
		$extractor = WebExtractTestTools::createExtractor([WebExtractTestTools::page($html)]);
		$extract = $extractor->extract('https://news.example/odd');
		$row = $this->row($extractor, $extract->getId());

		$this->assertSame(512, mb_strlen($row['title'], 'UTF-8'));
		$this->assertSame(0, (int) $row['published_time']);
	}

	public function testAReaderThatBreaksFailsTheAddressForGood()
	{
		$extractor = WebExtractTestTools::createExtractor([WebExtractTestTools::page('<p>x</p>')]);
		$extractor->setReader(new class () extends \Belisoful\Prado\Util\WebExtract\TWebPageReader {
			public function read($page, $extract, $client = null): void
			{
				throw new \RuntimeException('The reader broke.');
			}
		});
		$extract = $extractor->extract('https://news.example/breaks');

		$this->assertSame(TWebExtractor::STATUS_FAILED, $extract->getStatus());
		$this->assertSame('The reader broke.', $extract->getError());
	}

	public function testProcessAfterResponseFetchesSomeOfWhatIsWaiting()
	{
		$calls = 0;
		$extractor = WebExtractTestTools::createExtractor($this->server($calls), ['ProcessOnEndRequest' => 2]);
		$this->assertSame(2, $extractor->getProcessOnEndRequest());

		$extractor->processAfterResponse(null, null);
		$this->assertSame(0, $calls);

		$extractor->queueAll(['https://news.example/1', 'https://news.example/2', 'https://news.example/3']);
		$extractor->processAfterResponse(null, null);
		$this->assertSame(2, $calls);
	}

	public function testEnsureStorageCreatesTheTableOrSaysItIsMissing()
	{
		$extractor = WebExtractTestTools::createExtractor([], ['TableName' => 'my_extracts']);
		$extractor->ensureStorage();
		$this->assertSame(0, (int) $extractor->getDbConnection()->createCommand('SELECT COUNT(*) FROM my_extracts')->queryScalar());

		$strict = WebExtractTestTools::createExtractor([], ['AutoCreateTables' => false]);
		$this->assertFalse($strict->getAutoCreateTables());
		$this->expectException(TConfigurationException::class);
		$strict->ensureStorage();
	}

	public function testStorageSettingsAreFixedOnceInitialized()
	{
		$extractor = WebExtractTestTools::createExtractor();

		foreach (['setTableName' => 'x', 'setAutoCreateTables' => false, 'setProcessOnEndRequest' => 1] as $setter => $value) {
			try {
				$extractor->{$setter}($value);
				$this->fail($setter . ' should be refused after init.');
			} catch (TInvalidOperationException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testSettingsAreChecked()
	{
		$extractor = new TWebExtractor();
		$extractor->setMaxAttempts('5');
		$extractor->setRetryDelay(0);
		$extractor->setLeaseTime('60');
		$this->assertSame(5, $extractor->getMaxAttempts());
		$this->assertSame(0, $extractor->getRetryDelay());
		$this->assertSame(60, $extractor->getLeaseTime());
		$this->assertSame('web_extracts', $extractor->getTableName());

		foreach (['setMaxAttempts' => 0, 'setRetryDelay' => -1, 'setLeaseTime' => 0, 'setProcessOnEndRequest' => -1] as $setter => $value) {
			try {
				$extractor->{$setter}($value);
				$this->fail($setter . '(' . $value . ') should have been refused.');
			} catch (TInvalidDataValueException $e) {
				$this->assertSame('webextract_setting_invalid', $e->getErrorCode());
			}
		}
	}

	public function testTheFetcherAndReaderSettingsCanBeSetHere()
	{
		$extractor = new TWebExtractor();
		$extractor->setTimeout(3);
		$extractor->setMaxDuration(9);
		$extractor->setMaxBytes(1000);
		$extractor->setMaxRedirects(1);
		$extractor->setUserAgent('RumorBot/1.0');
		$extractor->setAllowPrivateNetworks(true);
		$extractor->setReadMetadata(false);

		$fetcher = $extractor->getFetcher();
		$this->assertSame([3.0, 9.0, 1000, 1, 'RumorBot/1.0', true], [$fetcher->getTimeout(), $fetcher->getMaxDuration(),
			$fetcher->getMaxBytes(), $fetcher->getMaxRedirects(), $fetcher->getUserAgent(), $fetcher->getAllowPrivateNetworks()]);
		$this->assertSame([3.0, 9.0, 1000, 1, 'RumorBot/1.0', true], [$extractor->getTimeout(), $extractor->getMaxDuration(),
			$extractor->getMaxBytes(), $extractor->getMaxRedirects(), $extractor->getUserAgent(), $extractor->getAllowPrivateNetworks()]);
		$this->assertFalse($extractor->getReader()->getReadMetadata());
		$this->assertFalse($extractor->getReadMetadata());
	}

	public function testHashUrl()
	{
		$this->assertSame(hash('sha256', 'https://news.example/'), TWebExtractor::hashUrl('https://news.example/'));
		$this->assertSame(64, strlen(TWebExtractor::hashUrl('x')));
	}
}
