<?php

use Belisoful\Prado\Util\WebExtract\TWebFetcher;
use Belisoful\Prado\Util\WebExtract\TWebFetchException;
use Prado\Exceptions\TInvalidDataValueException;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once(__DIR__ . '/../test_tools/WebExtractTestTools.php');

class TWebFetcherTest extends PHPUnit\Framework\TestCase
{
	/**
	 * @param TWebFetcher $fetcher the fetcher
	 * @param string $url the address
	 * @return TWebFetchException what the fetch threw
	 */
	private function failure(TWebFetcher $fetcher, string $url): TWebFetchException
	{
		try {
			$fetcher->fetch($url);
		} catch (TWebFetchException $e) {
			return $e;
		}
		$this->fail('Fetching ' . $url . ' should have failed.');
	}

	public function testFetchesAPage()
	{
		$fetcher = WebExtractTestTools::createFetcher([WebExtractTestTools::page('<p>Hello</p>')]);
		$page = $fetcher->fetch('https://news.example/story');

		$this->assertSame('https://news.example/story', $page->getUrl());
		$this->assertSame('https://news.example/story', $page->getFinalUrl());
		$this->assertSame(200, $page->getHttpStatus());
		$this->assertSame('text/html', $page->getContentType());
		$this->assertSame('utf-8', $page->getCharset());
		$this->assertSame('<p>Hello</p>', $page->getBody());
		$this->assertTrue($page->getIsHtml());
	}

	public function testSendsItsUserAgentAndAcceptsText()
	{
		$headers = [];
		$fetcher = WebExtractTestTools::createFetcher(function ($method, $url, $options) use (&$headers) {
			$headers = $options['headers'];

			return WebExtractTestTools::page('ok');
		});
		$fetcher->fetch('https://news.example/');

		$this->assertContains('User-Agent: ' . TWebFetcher::DEFAULT_USER_AGENT, $headers);
		$this->assertStringContainsString('text/html', implode("\n", preg_grep('/^Accept:/', $headers)));

		$fetcher->setUserAgent('RumorBot/2.0');
		$fetcher->fetch('https://news.example/');
		$this->assertContains('User-Agent: RumorBot/2.0', $headers);

		$fetcher->setUserAgent('');
		$this->assertSame(TWebFetcher::DEFAULT_USER_AGENT, $fetcher->getUserAgent());
	}

	public function testRefusesAnythingButHttpAndHttps()
	{
		$fetcher = WebExtractTestTools::createFetcher();

		foreach (['ftp://news.example/file', 'file:///etc/passwd', 'gopher://news.example/', 'javascript:alert(1)'] as $url) {
			$e = $this->failure($fetcher, $url);
			$this->assertContains($e->getErrorCode(), ['webextract_url_scheme_refused', 'webextract_url_invalid'], $url);
			$this->assertFalse($e->getIsTransient());
		}
	}

	public function testRefusesAnAddressWithNoHost()
	{
		$e = $this->failure(WebExtractTestTools::createFetcher(), 'not a url');

		$this->assertSame('webextract_url_invalid', $e->getErrorCode());
	}

	public function testRefusesAnAddressCarryingCredentials()
	{
		$e = $this->failure(WebExtractTestTools::createFetcher(), 'https://admin:secret@news.example/');

		$this->assertSame('webextract_url_credentials_refused', $e->getErrorCode());
	}

	public function testRefusesPrivateLoopbackAndLinkLocalAddresses()
	{
		$fetcher = WebExtractTestTools::createFetcher(fn () => $this->fail('No request should have been made.'));

		foreach (['http://127.0.0.1/', 'http://10.1.2.3/', 'http://192.168.0.1/admin', 'http://172.16.0.1/',
			'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'http://0.0.0.0/'] as $url) {
			$e = $this->failure($fetcher, $url);
			$this->assertSame('webextract_fetch_host_blocked', $e->getErrorCode(), $url);
			$this->assertFalse($e->getIsTransient(), $url);
		}
	}

	public function testRefusesAPrivateAddressWrittenInAnIpv6Form()
	{
		$fetcher = WebExtractTestTools::createFetcher(fn () => $this->fail('No request should have been made.'));

		// Each of these reaches 127.0.0.1 or 10.0.0.1: IPv4-mapped, NAT64, 6to4, and IPv4-compatible
		// forms, the transition forms CVE-2026-48736 covers.
		foreach (['http://[::ffff:127.0.0.1]/', 'http://[64:ff9b::7f00:1]/', 'http://[2002:7f00:1::]/', 'http://[2002:a00:1::]/',
			'http://[::127.0.0.1]/', 'http://[fc00::1]/', 'http://[fe80::1]/'] as $url) {
			$e = $this->failure($fetcher, $url);
			$this->assertSame('webextract_fetch_host_blocked', $e->getErrorCode(), $url);
		}
	}

	public function testRefusesAHostNameThatResolvesToAPrivateAddress()
	{
		$fetcher = WebExtractTestTools::createFetcher(fn () => $this->fail('No request should have been made.'));
		$e = $this->failure($fetcher, 'http://internal.example/');

		$this->assertSame('webextract_fetch_host_blocked', $e->getErrorCode());
		$this->assertStringContainsString('internal.example', $e->getMessage());
	}

	public function testRefusesARedirectToAPrivateAddress()
	{
		$requested = [];
		$fetcher = WebExtractTestTools::createFetcher(function ($method, $url) use (&$requested) {
			$requested[] = $url;

			return new MockResponse('', ['http_code' => 302, 'redirect_url' => 'http://169.254.169.254/latest/meta-data/',
				'response_headers' => ['Location: http://169.254.169.254/latest/meta-data/']]);
		});
		$e = $this->failure($fetcher, 'https://news.example/innocent');

		$this->assertSame('webextract_fetch_host_blocked', $e->getErrorCode());
		$this->assertSame(['https://news.example/innocent'], $requested);
	}

	public function testFollowsARedirectToAPublicAddress()
	{
		$fetcher = WebExtractTestTools::createFetcher(function ($method, $url) {
			if ($url === 'https://news.example/short') {
				return new MockResponse('', ['http_code' => 301, 'redirect_url' => 'https://other.example/long-story',
					'response_headers' => ['Location: https://other.example/long-story']]);
			}

			return WebExtractTestTools::page('<p>The story</p>');
		});
		$page = $fetcher->fetch('https://news.example/short');

		$this->assertSame('https://news.example/short', $page->getUrl());
		$this->assertSame('https://other.example/long-story', $page->getFinalUrl());
		$this->assertSame('<p>The story</p>', $page->getBody());
	}

	public function testStopsAfterTooManyRedirects()
	{
		$fetcher = WebExtractTestTools::createFetcher(fn ($method, $url) => new MockResponse('', [
			'http_code' => 302, 'redirect_url' => $url . 'x', 'response_headers' => ['Location: ' . $url . 'x']]));
		$fetcher->setMaxRedirects(2);
		$e = $this->failure($fetcher, 'https://news.example/loop');

		$this->assertSame('webextract_fetch_too_many_redirects', $e->getErrorCode());
		$this->assertSame(302, $e->getHttpStatus());
		$this->assertFalse($e->getIsTransient());
	}

	public function testAPrivateNetworkCanBeAllowed()
	{
		$fetcher = WebExtractTestTools::createFetcher([WebExtractTestTools::page('intranet')]);
		$fetcher->setAllowPrivateNetworks(true);

		$this->assertTrue($fetcher->getAllowPrivateNetworks());
		$this->assertSame('intranet', $fetcher->fetch('http://10.0.0.5/wiki')->getBody());
	}

	public function testRefusesABodyDeclaredTooLarge()
	{
		$fetcher = WebExtractTestTools::createFetcher([new MockResponse(str_repeat('x', 5000),
			['response_headers' => ['Content-Type: text/html', 'Content-Length: 5000']])]);
		$fetcher->setMaxBytes(1000);
		$e = $this->failure($fetcher, 'https://news.example/huge');

		$this->assertSame('webextract_fetch_too_large', $e->getErrorCode());
		$this->assertFalse($e->getIsTransient());
	}

	public function testStopsABodyThatGrowsTooLargeWhileDownloading()
	{
		$fetcher = WebExtractTestTools::createFetcher([new MockResponse([str_repeat('x', 600), str_repeat('y', 600)],
			['response_headers' => ['Content-Type: text/html']])]);
		$fetcher->setMaxBytes(1000);

		$this->assertSame('webextract_fetch_too_large', $this->failure($fetcher, 'https://news.example/stream')->getErrorCode());
	}

	public function testDoesNotDownloadWhatIsNotText()
	{
		$fetcher = WebExtractTestTools::createFetcher([WebExtractTestTools::page('%PDF-1.7 ...', 'application/pdf')]);
		$page = $fetcher->fetch('https://news.example/report.pdf');

		$this->assertSame('application/pdf', $page->getContentType());
		$this->assertSame('', $page->getBody());
		$this->assertFalse($page->getIsHtml());
	}

	public function testAClientErrorIsPermanent()
	{
		$fetcher = WebExtractTestTools::createFetcher([new MockResponse('Not here', ['http_code' => 404])]);
		$e = $this->failure($fetcher, 'https://news.example/gone');

		$this->assertSame('webextract_fetch_http_status', $e->getErrorCode());
		$this->assertSame(404, $e->getHttpStatus());
		$this->assertFalse($e->getIsTransient());
	}

	public function testAServerErrorOrBeingAskedToSlowDownIsTransient()
	{
		foreach ([500, 502, 503, 408, 429] as $status) {
			$fetcher = WebExtractTestTools::createFetcher([new MockResponse('', ['http_code' => $status])]);
			$e = $this->failure($fetcher, 'https://news.example/busy');

			$this->assertSame($status, $e->getHttpStatus());
			$this->assertTrue($e->getIsTransient(), (string) $status);
		}
	}

	public function testANetworkFailureIsTransient()
	{
		$fetcher = WebExtractTestTools::createFetcher([new MockResponse('', ['error' => 'Connection timed out'])]);
		$e = $this->failure($fetcher, 'https://news.example/slow');

		$this->assertSame('webextract_fetch_failed', $e->getErrorCode());
		$this->assertStringContainsString('Connection timed out', $e->getMessage());
		$this->assertTrue($e->getIsTransient());
	}

	public function testConvertsTheCharacterSetTheHeaderNames()
	{
		$fetcher = WebExtractTestTools::createFetcher([WebExtractTestTools::page("<p>caf\xE9</p>", 'text/html; charset=ISO-8859-1')]);
		$page = $fetcher->fetch('https://news.example/');

		$this->assertSame('iso-8859-1', $page->getCharset());
		$this->assertSame('<p>café</p>', $page->getBody());
	}

	public function testConvertsTheCharacterSetAMetaTagNames()
	{
		$html = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=windows-1251\"></head><body>\xCF\xF0\xE8\xE2\xE5\xF2</body></html>";
		$page = WebExtractTestTools::createFetcher([WebExtractTestTools::page($html, 'text/html')])->fetch('https://news.example/');

		$this->assertSame('windows-1251', $page->getCharset());
		$this->assertStringContainsString('Привет', $page->getBody());
	}

	public function testToUtf8()
	{
		$this->assertSame(['hi', 'utf-8'], TWebFetcher::toUtf8("\xEF\xBB\xBFhi", 'iso-8859-1'));
		$this->assertSame(['café', 'utf-8'], TWebFetcher::toUtf8('café'));
		// Unlabelled and not valid UTF-8: what a browser would assume.
		$this->assertSame(['café', 'windows-1252'], TWebFetcher::toUtf8("caf\xE9"));
		// A meta tag is only looked for in HTML.
		$this->assertSame(['<meta charset="koi8-r">', 'utf-8'], TWebFetcher::toUtf8('<meta charset="koi8-r">', '', false));
		// A character set PHP does not know keeps the ASCII and replaces the rest.
		[$body, $charset] = TWebFetcher::toUtf8("abc\xFF", 'x-unheard-of');
		$this->assertSame('x-unheard-of', $charset);
		$this->assertTrue(mb_check_encoding($body, 'UTF-8'));
		$this->assertStringStartsWith('abc', $body);
	}

	public function testParseContentType()
	{
		$this->assertSame(['text/html', 'utf-8'], TWebFetcher::parseContentType('text/html; charset=UTF-8'));
		$this->assertSame(['text/html', 'iso-8859-1'], TWebFetcher::parseContentType('Text/HTML;Charset="ISO-8859-1"'));
		$this->assertSame(['application/pdf', ''], TWebFetcher::parseContentType('application/pdf'));
		$this->assertSame(['application/octet-stream', ''], TWebFetcher::parseContentType(''));
	}

	public function testThePsr18ClientHasTheSameChecks()
	{
		$fetcher = WebExtractTestTools::createFetcher(fn () => $this->fail('No request should have been made.'));
		$client = $fetcher->getPsr18Client();

		$this->expectException(\Psr\Http\Client\ClientExceptionInterface::class);
		$client->sendRequest($client->createRequest('GET', 'http://127.0.0.1/admin'));
	}

	public function testLimitsMustBePositive()
	{
		$fetcher = new TWebFetcher();
		$fetcher->setTimeout('2.5');
		$fetcher->setMaxDuration(20);
		$fetcher->setMaxBytes('4096');
		$fetcher->setMaxRedirects(0);

		$this->assertSame(2.5, $fetcher->getTimeout());
		$this->assertSame(20.0, $fetcher->getMaxDuration());
		$this->assertSame(4096, $fetcher->getMaxBytes());
		$this->assertSame(0, $fetcher->getMaxRedirects());

		foreach (['setTimeout' => 0, 'setMaxDuration' => -1, 'setMaxBytes' => 0, 'setMaxRedirects' => -1] as $setter => $value) {
			try {
				$fetcher->{$setter}($value);
				$this->fail($setter . '(' . $value . ') should have been refused.');
			} catch (TInvalidDataValueException $e) {
				$this->assertSame('webextract_fetcher_limit_invalid', $e->getErrorCode());
			}
		}
	}

	public function testTheTransportCanBeReplaced()
	{
		$fetcher = new TWebFetcher();
		$this->assertNull($fetcher->getTransport());

		$first = $fetcher->getHttpClient();
		$this->assertSame($first, $fetcher->getHttpClient());

		$transport = new \Symfony\Component\HttpClient\MockHttpClient();
		$fetcher->setTransport($transport);
		$this->assertSame($transport, $fetcher->getTransport());
		$this->assertNotSame($first, $fetcher->getHttpClient());
	}
}
