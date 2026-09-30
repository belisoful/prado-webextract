<?php

/**
 * TWebFetchResult class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-webextract
 * @license https://github.com/belisoful/prado-webextract/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\WebExtract;

use Prado\TComponent;

/**
 * TWebFetchResult class.
 *
 * What came back from fetching a page: where it ended up after redirects, what the server said
 * it was, and the body, already converted to UTF-8 when it is text.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TWebFetchResult extends TComponent
{
	/**
	 * @param string $url the address that was asked for
	 * @param string $finalUrl the address the page was found at, after redirects
	 * @param int $httpStatus the HTTP status of the final response
	 * @param string $contentType the media type, lower case and without parameters
	 * @param string $charset the character set the body arrived in, '' when it is not text
	 * @param string $body the body; UTF-8 when the media type is text
	 */
	public function __construct(
		private string $url,
		private string $finalUrl,
		private int $httpStatus,
		private string $contentType,
		private string $charset,
		private string $body
	) {
		parent::__construct();
	}

	/**
	 * @return string the address that was asked for
	 */
	public function getUrl(): string
	{
		return $this->url;
	}

	/**
	 * @return string the address the page was found at, after redirects
	 */
	public function getFinalUrl(): string
	{
		return $this->finalUrl;
	}

	/**
	 * @return int the HTTP status of the final response
	 */
	public function getHttpStatus(): int
	{
		return $this->httpStatus;
	}

	/**
	 * @return string the media type, lower case and without parameters, such as 'text/html'
	 */
	public function getContentType(): string
	{
		return $this->contentType;
	}

	/**
	 * @return string the character set the body arrived in, '' when it is not text
	 */
	public function getCharset(): string
	{
		return $this->charset;
	}

	/**
	 * @return string the body; UTF-8 when the media type is text
	 */
	public function getBody(): string
	{
		return $this->body;
	}

	/**
	 * @return bool whether the body is an HTML document
	 */
	public function getIsHtml(): bool
	{
		return in_array($this->contentType, ['text/html', 'application/xhtml+xml'], true);
	}
}
