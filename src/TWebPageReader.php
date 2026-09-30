<?php

/**
 * TWebPageReader class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-webextract
 * @license https://github.com/belisoful/prado-webextract/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\WebExtract;

use DOMNode;
use Embed\Extractor;
use Embed\ExtractorFactory;
use Embed\Http\Crawler;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Nyholm\Psr7\Factory\Psr17Factory;
use Prado\TComponent;
use Prado\TPropertyValue;
use Psr\Http\Client\ClientInterface;

/**
 * TWebPageReader class.
 *
 * Reads a fetched page into a {@see TWebExtract}, using two libraries that each do half the job:
 *
 * - [Readability](https://github.com/fivefilters/readability.php), a port of the code behind
 *   Firefox's Reader View, finds the article in the page and throws away the navigation,
 *   advertising, and furniture around it. That is where the text comes from.
 * - [Embed](https://github.com/php-embed/Embed) reads what the page says about itself -- Open
 *   Graph, Twitter cards, JSON-LD, oEmbed, and plain `<meta>` tags -- which is where the title,
 *   summary, image, site name, language, and publication time mostly come from. It knows the
 *   particular ways of the large sites, and for some of them asks their oEmbed service, which is
 *   what gets a YouTube video its real title and author.
 *
 * Embed's own requests go through the PSR-18 client it is handed, which {@see TWebExtractor}
 * makes from the same {@see TWebFetcher} as the page itself, so they get the same private network
 * checks and limits. Without a client, Embed reads only the page it was given.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TWebPageReader extends TComponent
{
	/** Elements whose content is never text. */
	public const SKIPPED_ELEMENTS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'button', 'select', 'textarea'];

	/** Elements that stand as paragraphs of their own. */
	public const BLOCK_ELEMENTS = ['p', 'div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'nav', 'address',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'figure', 'table', 'ul', 'ol', 'dl', 'form', 'fieldset', 'details'];

	/** Elements that start a line of their own inside a paragraph. */
	public const LINE_ELEMENTS = ['tr', 'dt', 'dd', 'figcaption', 'caption', 'summary'];

	/** @var bool whether to read what the page says about itself, through Embed */
	private bool $_readMetadata = true;

	/**
	 * @return bool whether to read what the page says about itself through Embed, true by default
	 */
	public function getReadMetadata(): bool
	{
		return $this->_readMetadata;
	}

	/**
	 * @param bool|string $value whether to read what the page says about itself through Embed;
	 *   false leaves only what Readability finds, and makes no requests beyond the page itself
	 */
	public function setReadMetadata($value): void
	{
		$this->_readMetadata = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Reads a fetched page into an extract. Only the fields the page yields are set; the rest are
	 * left as they were.
	 * @param TWebFetchResult $page the fetched page
	 * @param TWebExtract $extract the extract to fill in
	 * @param null|ClientInterface $client the client Embed makes its own requests through, such
	 *   as a provider's oEmbed service; null makes none
	 */
	public function read(TWebFetchResult $page, TWebExtract $extract, ?ClientInterface $client = null): void
	{
		$extract->setFinalUrl($page->getFinalUrl());
		$extract->setHttpStatus($page->getHttpStatus());
		$extract->setContentType($page->getContentType());

		if ($page->getContentType() === 'text/plain') {
			$extract->setText(self::normalizeText($page->getBody()));
		} elseif ($page->getIsHtml()) {
			$this->readArticle($page, $extract);
			if ($this->_readMetadata) {
				$this->readMetadata($page, $extract, $client);
			}
		}
		$extract->setWordCount(self::countWords($extract->getText()));
	}

	/**
	 * Turns HTML into plain text: paragraphs separated by a blank line, list items and table rows
	 * on lines of their own, and runs of spaces collapsed, except inside `<pre>`.
	 * @param DOMNode $node the element or document to read
	 * @return string the text
	 */
	public static function htmlToText(DOMNode $node): string
	{
		$text = '';
		self::appendText($node, $text, false);

		return self::normalizeText($text);
	}

	/**
	 * @param string $text text with irregular spacing
	 * @return string the text with runs of spaces collapsed, spaces trimmed from the ends of lines,
	 *   and no more than one blank line in a row
	 */
	public static function normalizeText(string $text): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
		$text = (string) preg_replace('/ *\n */', "\n", $text);
		$text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

		return trim($text);
	}

	/**
	 * @param string $text the text to count
	 * @return int how many words it has, counting runs of letters and digits in any script, with
	 *   a hyphenated word or a contraction as one
	 */
	public static function countWords(string $text): int
	{
		return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*(?:[-\'’][\p{L}\p{N}\p{M}]+)*/u', $text);
	}

	/**
	 * Finds the article with Readability, and takes its text and whatever it learned about the
	 * page. A page with no article in it -- a home page, a search result -- keeps no text.
	 * @param TWebFetchResult $page the fetched page
	 * @param TWebExtract $extract the extract to fill in
	 */
	protected function readArticle(TWebFetchResult $page, TWebExtract $extract): void
	{
		$readability = new Readability(new Configuration([
			'FixRelativeURLs' => true,
			'OriginalURL' => $page->getFinalUrl(),
		]));
		try {
			$readability->parse($page->getBody());
		} catch (ParseException $e) {
			return;
		}

		$this->setIfEmpty($extract, 'Title', $readability->getTitle());
		$this->takeAuthor($extract, $readability->getAuthor());
		$this->setIfEmpty($extract, 'Description', $readability->getExcerpt());
		$this->setIfEmpty($extract, 'SiteName', $readability->getSiteName());
		$this->setIfEmpty($extract, 'ImageUrl', $readability->getImage());

		$document = $readability->getDOMDocument();
		if ($document !== null) {
			$extract->setText(self::htmlToText($document));
		}
	}

	/**
	 * Reads what the page says about itself with Embed. What Embed finds takes the place of what
	 * Readability found for the title, summary, site name, and image, since a page's own Open Graph
	 * and oEmbed data are written for exactly this; Readability's byline stays ahead of Embed's
	 * author, since it is read from the article itself.
	 * @param TWebFetchResult $page the fetched page
	 * @param TWebExtract $extract the extract to fill in
	 * @param null|ClientInterface $client the client Embed makes its own requests through
	 */
	protected function readMetadata(TWebFetchResult $page, TWebExtract $extract, ?ClientInterface $client): void
	{
		$factory = new Psr17Factory();
		$request = $factory->createRequest('GET', $page->getFinalUrl());
		// The body is already UTF-8, whatever the page said it was in; this tells Embed so.
		$response = $factory->createResponse($page->getHttpStatus())
			->withHeader('Content-Type', $page->getContentType() . '; charset=utf-8')
			->withBody($factory->createStream(self::inlineJsonLdContexts($page->getBody())));
		$crawler = new Crawler($client ?? new TWebNoRequestClient(), $factory, $factory);
		$extractor = (new ExtractorFactory())->createExtractor($request->getUri(), $request, $response, $crawler);

		$this->readDetected($page, $extract, $extractor);
	}

	/**
	 * Copies what Embed found onto the extract.
	 * @param TWebFetchResult $page the fetched page
	 * @param TWebExtract $extract the extract to fill in
	 * @param Extractor $extractor the Embed extractor for the page
	 */
	protected function readDetected(TWebFetchResult $page, TWebExtract $extract, Extractor $extractor): void
	{
		$this->setIfFound($extract, 'Title', $this->detect($extractor, 'title'));
		$this->setIfFound($extract, 'Description', $this->detect($extractor, 'description'));
		$this->setIfFound($extract, 'SiteName', $this->detect($extractor, 'providerName'));
		$this->setIfFound($extract, 'ImageUrl', $this->detect($extractor, 'image'));
		$this->setIfFound($extract, 'Language', $this->detect($extractor, 'language'));
		$this->takeAuthor($extract, $this->detect($extractor, 'authorName'));
		$meta = $extract->getMeta();

		$published = $this->detect($extractor, 'publishedTime');
		if ($published instanceof \DateTimeInterface && $published->getTimestamp() > 0) {
			$extract->setPublishedTime($published->getTimestamp());
		}

		$canonical = (string) $this->detect($extractor, 'url');
		if ($canonical !== '' && $canonical !== $page->getFinalUrl()) {
			$meta['canonical_url'] = $canonical;
		}
		$keywords = $this->detect($extractor, 'keywords');
		if (is_array($keywords) && $keywords !== []) {
			$meta['keywords'] = array_values(array_map('strval', $keywords));
		}
		foreach (['authorUrl' => 'author_url', 'providerUrl' => 'provider_url', 'icon' => 'icon', 'license' => 'license'] as $property => $key) {
			$value = (string) $this->detect($extractor, $property);
			if ($value !== '') {
				$meta[$key] = $value;
			}
		}
		$feeds = $this->detect($extractor, 'feeds');
		if (is_array($feeds) && $feeds !== []) {
			$meta['feeds'] = array_values(array_map('strval', $feeds));
		}
		$code = $this->detect($extractor, 'code');
		if ($code instanceof \Embed\EmbedCode && (string) $code->html !== '') {
			$meta['embed'] = array_filter([
				'html' => (string) $code->html,
				'width' => $code->width,
				'height' => $code->height,
			], fn ($value) => $value !== null);
		}
		$extract->setMeta($meta);
	}

	/**
	 * Rewrites the `@context` of every JSON-LD block in a page so that reading it needs nothing
	 * from the network.
	 *
	 * Embed reads JSON-LD through `ml/json-ld`, which fetches every `@context` given as an address
	 * with a bare `file_get_contents`: outside {@see TWebFetcher}, so with none of its checks, at an
	 * address the page chose, with a ten second timeout. A page could aim it at a private network
	 * address, and even an honest page costs ten seconds whenever schema.org is slow. The library
	 * has a setting for its document loader, but it is ignored when no options are passed, and
	 * Embed passes none.
	 *
	 * So the addresses are taken out before Embed sees them. A schema.org context -- nearly every
	 * page's -- becomes an inline one mapping every term into the schema.org vocabulary, which is
	 * all Embed reads; any other context given by address is dropped. Blocks that are not valid
	 * JSON are left alone, since neither library can read them.
	 * @param string $html the page
	 * @return string the page, with every JSON-LD context inline
	 */
	public static function inlineJsonLdContexts(string $html): string
	{
		return (string) preg_replace_callback(
			'#(<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json["\']?[^>]*>)(.*?)(</script\s*>)#is',
			function (array $match) {
				$data = json_decode($match[2], true);
				if (!is_array($data)) {
					return $match[0];
				}
				// JSON_HEX_TAG keeps a '</script>' inside a string from closing the block early.
				$json = json_encode(self::inlineContexts($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);

				return $match[1] . $json . $match[3];
			},
			$html
		);
	}

	/**
	 * @param array $data a decoded JSON-LD value
	 * @return array the value, with every `@context` inside it made inline
	 */
	protected static function inlineContexts(array $data): array
	{
		foreach ($data as $key => $value) {
			if ($key === '@context') {
				$context = self::inlineContext($value);
				if ($context === null) {
					unset($data[$key]);
				} else {
					$data[$key] = $context;
				}
			} elseif (is_array($value)) {
				$data[$key] = self::inlineContexts($value);
			}
		}

		return $data;
	}

	/**
	 * @param mixed $context a `@context` value: an address, an inline context, or a list of them
	 * @return mixed the context with no addresses in it, null when nothing of it is left
	 */
	protected static function inlineContext($context)
	{
		if (is_string($context)) {
			return preg_match('#^https?://schema\.org(/.*)?$#i', trim($context)) ? ['@vocab' => 'http://schema.org/'] : null;
		}
		if (!is_array($context)) {
			return null;
		}
		if (array_is_list($context)) {
			$kept = array_values(array_filter(array_map([self::class, 'inlineContext'], $context), fn ($item) => $item !== null));

			return $kept === [] ? null : $kept;
		}

		return self::inlineContexts($context);
	}

	/**
	 * Asks Embed for one thing. Embed works each one out when asked, sometimes with a request, so
	 * each is asked separately and one that fails leaves the rest.
	 * @param Extractor $extractor the Embed extractor for the page
	 * @param string $property the Embed property, such as 'title'
	 * @return mixed what Embed found, null when it found nothing or failed
	 */
	protected function detect(Extractor $extractor, string $property)
	{
		try {
			return $extractor->{$property};
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Takes an author, unless one was already found. Some sites give a link to the author's profile
	 * where a name belongs; that is kept as the `author_url` meta rather than shown as a name.
	 * @param TWebExtract $extract the extract
	 * @param mixed $value the author found
	 */
	private function takeAuthor(TWebExtract $extract, $value): void
	{
		$value = trim((string) $value);
		if (!preg_match('#^https?://#i', $value)) {
			$this->setIfEmpty($extract, 'Author', $value);
		} elseif (!isset($extract->getMeta()['author_url'])) {
			$extract->setMeta(['author_url' => $value] + $extract->getMeta());
		}
	}

	/**
	 * @param TWebExtract $extract the extract
	 * @param string $property the property, such as 'Title'
	 * @param mixed $value what was found
	 */
	private function setIfFound(TWebExtract $extract, string $property, $value): void
	{
		$value = trim((string) $value);
		if ($value !== '') {
			$extract->{'set' . $property}($value);
		}
	}

	/**
	 * @param TWebExtract $extract the extract
	 * @param string $property the property, such as 'Title'
	 * @param mixed $value what was found
	 */
	private function setIfEmpty(TWebExtract $extract, string $property, $value): void
	{
		if ($extract->{'get' . $property}() === '') {
			$this->setIfFound($extract, $property, $value);
		}
	}

	/**
	 * @param DOMNode $node the node to read
	 * @param string $text the text so far
	 * @param bool $preformatted whether the node is inside `<pre>`
	 */
	private static function appendText(DOMNode $node, string &$text, bool $preformatted): void
	{
		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
				$value = (string) $child->nodeValue;
				// Outside <pre>, a line break in the source is only a space; inside, it is a line.
				$text .= $preformatted ? $value : (string) preg_replace('/\s+/u', ' ', $value);

				continue;
			}
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			$tag = strtolower($child->nodeName);
			if (in_array($tag, self::SKIPPED_ELEMENTS, true)) {
				continue;
			}
			if ($tag === 'br') {
				$text .= "\n";
			} elseif ($tag === 'hr') {
				$text .= "\n\n";
			} elseif ($tag === 'li') {
				$text .= "\n- ";
				self::appendText($child, $text, $preformatted);
			} elseif ($tag === 'td' || $tag === 'th') {
				self::appendText($child, $text, $preformatted);
				$text .= ' ';
			} elseif (in_array($tag, self::BLOCK_ELEMENTS, true)) {
				$text .= "\n\n";
				self::appendText($child, $text, $preformatted || $tag === 'pre');
				$text .= "\n\n";
			} elseif (in_array($tag, self::LINE_ELEMENTS, true)) {
				$text .= "\n";
				self::appendText($child, $text, $preformatted);
			} else {
				self::appendText($child, $text, $preformatted);
			}
		}
	}
}
