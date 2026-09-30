<?php

use Belisoful\Prado\Util\WebExtract\TWebExtract;
use Belisoful\Prado\Util\WebExtract\TWebFetchResult;
use Belisoful\Prado\Util\WebExtract\TWebNoRequestClient;
use Belisoful\Prado\Util\WebExtract\TWebFetchException;
use Belisoful\Prado\Util\WebExtract\TWebPageReader;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once(__DIR__ . '/../test_tools/WebExtractTestTools.php');

/**
 * Stands in for PHP's http and https stream wrappers, recording every address opened through them
 * and refusing it, so a test can show that nothing reached the network behind the fetcher's back.
 */
class TWebPageReaderTestStreamSpy
{
	/** @var string[] every address opened */
	public static array $opened = [];

	/** @var mixed the stream context PHP hands every wrapper */
	public $context;

	public function stream_open($path, $mode, $options, &$openedPath)
	{
		self::$opened[] = $path;

		return false;
	}
}

class TWebPageReaderTest extends PHPUnit\Framework\TestCase
{
	/**
	 * @param string $body the page
	 * @param string $contentType its media type
	 * @param string $url where it was found
	 * @return TWebFetchResult the page as the fetcher would return it
	 */
	private function page(string $body, string $contentType = 'text/html', string $url = 'https://news.example/2026/09/bridge-closes'): TWebFetchResult
	{
		return new TWebFetchResult($url, $url, 200, $contentType, 'utf-8', $body);
	}

	/**
	 * @param TWebFetchResult $page the page
	 * @param bool $readMetadata whether to read metadata through Embed
	 * @param null|\Psr\Http\Client\ClientInterface $client the client Embed may use
	 * @return TWebExtract what the reader made of it
	 */
	private function read(TWebFetchResult $page, bool $readMetadata = true, $client = null): TWebExtract
	{
		$reader = new TWebPageReader();
		$reader->setReadMetadata($readMetadata);
		$extract = new TWebExtract();
		$reader->read($page, $extract, $client);

		return $extract;
	}

	public function testReadsAnArticle()
	{
		$extract = $this->read($this->page(WebExtractTestTools::articleHtml()));

		$this->assertSame('Old bridge closes after council vote', $extract->getTitle());
		$this->assertSame('The council has closed the old bridge after engineers found cracks.', $extract->getDescription());
		$this->assertSame('Riverside Gazette', $extract->getSiteName());
		$this->assertSame('https://news.example/images/bridge.jpg', $extract->getImageUrl());
		$this->assertSame('en-GB', $extract->getLanguage());
		$this->assertSame(strtotime('2026-09-01T09:30:00+00:00'), $extract->getPublishedTime());
		$this->assertSame(['bridge', 'council', 'transport'], $extract->getMeta()['keywords']);
		$this->assertSame('https://news.example/2026/09/bridge-closes', $extract->getFinalUrl());
		$this->assertSame(200, $extract->getHttpStatus());
		$this->assertSame('text/html', $extract->getContentType());
	}

	public function testTheTextIsTheArticleAlone()
	{
		$text = $this->read($this->page(WebExtractTestTools::articleHtml()))->getText();

		$this->assertStringContainsString('The council voted on Tuesday to close the old bridge to traffic.', $text);
		$this->assertStringContainsString("- Detour: eleven kilometres\n- Reopening: not planned", $text);
		$this->assertMatchesRegularExpression('/traffic\.\s*\n\nEngineers|traffic\. .*\n\nEngineers/s', $text);
		foreach (['Bob\'s Carpets', 'Cookie settings', 'trackEverything', 'Sport'] as $furniture) {
			$this->assertStringNotContainsString($furniture, $text);
		}
	}

	public function testCountsTheWords()
	{
		$extract = $this->read($this->page(WebExtractTestTools::articleHtml()));

		$this->assertSame(TWebPageReader::countWords($extract->getText()), $extract->getWordCount());
		$this->assertGreaterThan(150, $extract->getWordCount());
	}

	public function testWithoutMetadataTheArticleIsStillRead()
	{
		$extract = $this->read($this->page(WebExtractTestTools::articleHtml()), false);

		$this->assertNotSame('', $extract->getTitle());
		$this->assertStringContainsString('close the old bridge', $extract->getText());
		$this->assertSame(0, $extract->getPublishedTime());
		$this->assertArrayNotHasKey('keywords', $extract->getMeta());
	}

	public function testAPageWithNoArticleKeepsItsMetadata()
	{
		$html = '<html><head><title>Home</title><meta property="og:title" content="Riverside Gazette"></head><body><nav><a href="/">Home</a></nav></body></html>';
		$extract = $this->read($this->page($html, 'text/html', 'https://news.example/'));

		$this->assertSame('Riverside Gazette', $extract->getTitle());
		$this->assertLessThan(5, $extract->getWordCount());
	}

	public function testReadsPlainText()
	{
		$extract = $this->read($this->page("First line.   \r\n\r\n\r\n\r\nSecond   paragraph here.\n", 'text/plain', 'https://news.example/notes.txt'));

		$this->assertSame("First line.\n\nSecond paragraph here.", $extract->getText());
		$this->assertSame(5, $extract->getWordCount());
		$this->assertSame('', $extract->getTitle());
	}

	public function testReadsNothingFromAMediaTypeItDoesNotKnow()
	{
		$extract = $this->read(new TWebFetchResult('https://news.example/a.pdf', 'https://news.example/a.pdf', 200, 'application/pdf', '', ''));

		$this->assertSame('application/pdf', $extract->getContentType());
		$this->assertSame('', $extract->getText());
	}

	public function testHtmlToText()
	{
		$document = new DOMDocument();
		$document->loadHTML('<?xml encoding="UTF-8"><body>'
			. '<h2>Heading</h2><p>One   two<br>three</p>'
			. '<ul><li>first</li><li>second</li></ul>'
			. '<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>'
			. "<pre>line one\nline two</pre>"
			. '<script>var hidden = 1;</script><style>p { color: red }</style>'
			. '<p>Café <b>bold</b> end.</p>'
			. '</body>');

		$this->assertSame(
			"Heading\n\nOne two\nthree\n\n- first\n- second\n\na b\nc d\n\nline one\nline two\n\nCafé bold end.",
			TWebPageReader::htmlToText($document)
		);
	}

	public function testCountWords()
	{
		$this->assertSame(0, TWebPageReader::countWords(''));
		$this->assertSame(4, TWebPageReader::countWords("It's a well-known fact."));
		$this->assertSame(3, TWebPageReader::countWords('Привет мир 2026'));
		$this->assertSame(2, TWebPageReader::countWords('naïve café'));
	}

	public function testAnAuthorGivenAsALinkIsNotTakenForAName()
	{
		$html = '<html><head><meta property="og:title" content="A story"><meta name="author" content="https://news.example/profile/jane"></head><body><p>Short.</p></body></html>';
		$extract = $this->read($this->page($html));

		$this->assertSame('', $extract->getAuthor());
		$this->assertSame('https://news.example/profile/jane', $extract->getMeta()['author_url']);
	}

	public function testInlineJsonLdContexts()
	{
		$page = '<p>x</p><script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle",'
			. '"headline":"H <\\/script> x","author":{"@context":["http://schema.org/",{"x":"http://example.com/x"},"http://10.0.0.1/ctx"],"name":"A"},'
			. '"publisher":{"@context":"http://10.0.0.1/private.jsonld","name":"P"}}</script>'
			. "<script type='application/ld+json'>not json</script>";
		$html = TWebPageReader::inlineJsonLdContexts($page);

		preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
		$data = json_decode($match[1], true);
		$this->assertSame(['@vocab' => 'http://schema.org/'], $data['@context']);
		$this->assertSame([['@vocab' => 'http://schema.org/'], ['x' => 'http://example.com/x']], $data['author']['@context']);
		$this->assertArrayNotHasKey('@context', $data['publisher']);
		$this->assertSame('H </script> x', $data['headline']);
		$this->assertStringNotContainsString('10.0.0.1', $html);
		$this->assertStringEndsWith("<script type='application/ld+json'>not json</script>", $html);
	}

	public function testReadsJsonLdWithoutTouchingTheNetwork()
	{
		$html = '<html><head><title>x</title><script type="application/ld+json">'
			. '{"@context":"https://schema.org","@type":"NewsArticle","headline":"From the graph",'
			. '"datePublished":"2026-08-15T12:00:00Z","author":{"@type":"Person","name":"Ann Writer"},'
			. '"publisher":{"@context":"http://10.0.0.1/private.jsonld","name":"P"}}'
			. '</script></head><body><p>Body.</p></body></html>';

		TWebPageReaderTestStreamSpy::$opened = [];
		stream_wrapper_unregister('http');
		stream_wrapper_unregister('https');
		stream_wrapper_register('http', TWebPageReaderTestStreamSpy::class);
		stream_wrapper_register('https', TWebPageReaderTestStreamSpy::class);
		try {
			$extract = $this->read($this->page($html, 'text/html', 'https://news.example/story'));
		} finally {
			stream_wrapper_restore('http');
			stream_wrapper_restore('https');
		}

		$this->assertSame([], TWebPageReaderTestStreamSpy::$opened);
		$this->assertSame(strtotime('2026-08-15T12:00:00Z'), $extract->getPublishedTime());
		$this->assertSame('Ann Writer', $extract->getAuthor());
	}

	public function testAsksAnOEmbedServiceThroughTheClientItIsGiven()
	{
		$html = '<html><head><title>A clip</title>'
			. '<link rel="alternate" type="application/json+oembed" href="https://news.example/oembed?url=https%3A%2F%2Fnews.example%2Fclip">'
			. '</head><body><p>Watch this.</p></body></html>';
		$requested = [];
		$fetcher = WebExtractTestTools::createFetcher(function ($method, $url) use (&$requested) {
			$requested[] = $url;

			return new MockResponse(json_encode([
				'type' => 'video', 'version' => '1.0', 'title' => 'The clip itself', 'author_name' => 'Clip Maker',
				'provider_name' => 'NewsTube', 'html' => '<iframe src="https://news.example/embed/clip"></iframe>', 'width' => 640, 'height' => 360,
			]), ['response_headers' => ['Content-Type: application/json']]);
		});
		$extract = $this->read($this->page($html, 'text/html', 'https://news.example/clip'), true, $fetcher->getPsr18Client());

		$this->assertContains('https://news.example/oembed?url=https%3A%2F%2Fnews.example%2Fclip', $requested);
		$this->assertSame('The clip itself', $extract->getTitle());
		$this->assertSame('Clip Maker', $extract->getAuthor());
		$this->assertSame('<iframe src="https://news.example/embed/clip"></iframe>', $extract->getMeta()['embed']['html']);
		$this->assertSame(640, $extract->getMeta()['embed']['width']);
	}

	public function testWithoutAClientNothingElseIsAskedFor()
	{
		$html = '<html><head><meta property="og:title" content="A clip">'
			. '<link rel="alternate" type="application/json+oembed" href="https://news.example/oembed?url=x">'
			. '</head><body><p>Watch this.</p></body></html>';
		$extract = $this->read($this->page($html, 'text/html', 'https://news.example/clip'));

		$this->assertSame('A clip', $extract->getTitle());
		$this->assertArrayNotHasKey('embed', $extract->getMeta());
	}

	public function testTheNoRequestClientRefuses()
	{
		$factory = new Nyholm\Psr7\Factory\Psr17Factory();

		$this->expectException(TWebFetchException::class);
		(new TWebNoRequestClient())->sendRequest($factory->createRequest('GET', 'https://news.example/'));
	}

	public function testReadMetadataIsABoolean()
	{
		$reader = new TWebPageReader();
		$this->assertTrue($reader->getReadMetadata());
		$reader->setReadMetadata('false');
		$this->assertFalse($reader->getReadMetadata());
	}
}
