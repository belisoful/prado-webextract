<?php

/**
 * TWebExtract class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-webextract
 * @license https://github.com/belisoful/prado-webextract/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\WebExtract;

use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * TWebExtract class.
 *
 * What was learned from one web address: the article as plain text, and what the page says about
 * itself -- title, summary, author, site, language, image, and when it was published.
 *
 * An extract belongs to the address, not to whatever linked to it. Ten posts linking the same
 * article share one extract, fetched once.
 *
 * The text is plain text and safe to show escaped. {@see getMeta} can hold an `embed` entry with
 * a provider's embed HTML for a video or a post; that is third-party markup, and a page that
 * shows it has to decide to trust the provider first.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TWebExtract extends TComponent
{
	/** @var int the id of the extract, 0 for one that is not stored */
	private int $_id = 0;

	/** @var string the address as it was asked for, normalized */
	private string $_url = '';

	/** @var string the address the page was found at, after redirects; '' before it is fetched */
	private string $_finalUrl = '';

	/** @var int where the extract stands, one of the TWebExtractor::STATUS_* constants */
	private int $_status = TWebExtractor::STATUS_PENDING;

	/** @var int the HTTP status of the last fetch, 0 when there was no answer */
	private int $_httpStatus = 0;

	/** @var string the media type of the page, such as 'text/html' */
	private string $_contentType = '';

	/** @var string the title of the page */
	private string $_title = '';

	/** @var string the page's own summary of itself, or the opening of the article when it has none */
	private string $_description = '';

	/** @var string who wrote it, as the page says */
	private string $_author = '';

	/** @var string the name of the site, such as 'The Guardian' */
	private string $_siteName = '';

	/** @var string the language of the page, as a tag such as 'en' or 'en-GB' */
	private string $_language = '';

	/** @var string the address of the page's representative image */
	private string $_imageUrl = '';

	/** @var int when the page says it was published, as a unix timestamp, 0 when it does not say */
	private int $_publishedTime = 0;

	/** @var string the article as plain text, paragraphs separated by blank lines */
	private string $_text = '';

	/** @var int how many words the text has */
	private int $_wordCount = 0;

	/** @var array whatever else was learned about the page, such as 'canonical_url', 'keywords', 'embed' */
	private array $_meta = [];

	/** @var string why the last fetch failed, '' when it did not */
	private string $_error = '';

	/** @var int how many times fetching has been tried */
	private int $_attempts = 0;

	/** @var int when a pending extract may next be tried, as a unix timestamp */
	private int $_nextAttemptTime = 0;

	/** @var int when the page was last fetched, as a unix timestamp, 0 for never */
	private int $_fetchedTime = 0;

	/** @var int when the extract was first asked for, as a unix timestamp */
	private int $_createdTime = 0;

	/** @var int when the extract last changed, as a unix timestamp */
	private int $_updatedTime = 0;

	/**
	 * @return int the id of the extract, 0 for one that is not stored
	 */
	public function getId(): int
	{
		return $this->_id;
	}

	/**
	 * @param int $value the id of the extract, 0 for one that is not stored
	 */
	public function setId($value): void
	{
		$this->_id = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return string the address as it was asked for, normalized
	 */
	public function getUrl(): string
	{
		return $this->_url;
	}

	/**
	 * @param string $value the address as it was asked for, normalized
	 */
	public function setUrl($value): void
	{
		$this->_url = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the address the page was found at, after redirects; '' before it is fetched
	 */
	public function getFinalUrl(): string
	{
		return $this->_finalUrl;
	}

	/**
	 * @param string $value the address the page was found at, after redirects; '' before it is fetched
	 */
	public function setFinalUrl($value): void
	{
		$this->_finalUrl = TPropertyValue::ensureString($value);
	}

	/**
	 * @return int where the extract stands, one of the TWebExtractor::STATUS_* constants
	 */
	public function getStatus(): int
	{
		return $this->_status;
	}

	/**
	 * @param int $value where the extract stands, one of the TWebExtractor::STATUS_* constants
	 */
	public function setStatus($value): void
	{
		$this->_status = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return int the HTTP status of the last fetch, 0 when there was no answer
	 */
	public function getHttpStatus(): int
	{
		return $this->_httpStatus;
	}

	/**
	 * @param int $value the HTTP status of the last fetch, 0 when there was no answer
	 */
	public function setHttpStatus($value): void
	{
		$this->_httpStatus = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return string the media type of the page, such as 'text/html'
	 */
	public function getContentType(): string
	{
		return $this->_contentType;
	}

	/**
	 * @param string $value the media type of the page, such as 'text/html'
	 */
	public function setContentType($value): void
	{
		$this->_contentType = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the title of the page
	 */
	public function getTitle(): string
	{
		return $this->_title;
	}

	/**
	 * @param string $value the title of the page
	 */
	public function setTitle($value): void
	{
		$this->_title = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the page's own summary of itself, or the opening of the article when it has none
	 */
	public function getDescription(): string
	{
		return $this->_description;
	}

	/**
	 * @param string $value the page's own summary of itself, or the opening of the article when it has none
	 */
	public function setDescription($value): void
	{
		$this->_description = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string who wrote it, as the page says
	 */
	public function getAuthor(): string
	{
		return $this->_author;
	}

	/**
	 * @param string $value who wrote it, as the page says
	 */
	public function setAuthor($value): void
	{
		$this->_author = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the name of the site, such as 'The Guardian'
	 */
	public function getSiteName(): string
	{
		return $this->_siteName;
	}

	/**
	 * @param string $value the name of the site, such as 'The Guardian'
	 */
	public function setSiteName($value): void
	{
		$this->_siteName = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the language of the page, as a tag such as 'en' or 'en-GB'
	 */
	public function getLanguage(): string
	{
		return $this->_language;
	}

	/**
	 * @param string $value the language of the page, as a tag such as 'en' or 'en-GB'
	 */
	public function setLanguage($value): void
	{
		$this->_language = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the address of the page's representative image
	 */
	public function getImageUrl(): string
	{
		return $this->_imageUrl;
	}

	/**
	 * @param string $value the address of the page's representative image
	 */
	public function setImageUrl($value): void
	{
		$this->_imageUrl = TPropertyValue::ensureString($value);
	}

	/**
	 * @return int when the page says it was published, as a unix timestamp, 0 when it does not say
	 */
	public function getPublishedTime(): int
	{
		return $this->_publishedTime;
	}

	/**
	 * @param int $value when the page says it was published, as a unix timestamp, 0 when it does not say
	 */
	public function setPublishedTime($value): void
	{
		$this->_publishedTime = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return string the article as plain text, paragraphs separated by blank lines
	 */
	public function getText(): string
	{
		return $this->_text;
	}

	/**
	 * @param string $value the article as plain text, paragraphs separated by blank lines
	 */
	public function setText($value): void
	{
		$this->_text = TPropertyValue::ensureString($value);
	}

	/**
	 * @return int how many words the text has
	 */
	public function getWordCount(): int
	{
		return $this->_wordCount;
	}

	/**
	 * @param int $value how many words the text has
	 */
	public function setWordCount($value): void
	{
		$this->_wordCount = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return array whatever else was learned about the page, such as 'canonical_url', 'keywords', 'embed'
	 */
	public function getMeta(): array
	{
		return $this->_meta;
	}

	/**
	 * @param array $value whatever else was learned about the page, such as 'canonical_url', 'keywords', 'embed'
	 */
	public function setMeta($value): void
	{
		$this->_meta = TPropertyValue::ensureArray($value);
	}

	/**
	 * @return string why the last fetch failed, '' when it did not
	 */
	public function getError(): string
	{
		return $this->_error;
	}

	/**
	 * @param string $value why the last fetch failed, '' when it did not
	 */
	public function setError($value): void
	{
		$this->_error = TPropertyValue::ensureString($value);
	}

	/**
	 * @return int how many times fetching has been tried
	 */
	public function getAttempts(): int
	{
		return $this->_attempts;
	}

	/**
	 * @param int $value how many times fetching has been tried
	 */
	public function setAttempts($value): void
	{
		$this->_attempts = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return int when a pending extract may next be tried, as a unix timestamp
	 */
	public function getNextAttemptTime(): int
	{
		return $this->_nextAttemptTime;
	}

	/**
	 * @param int $value when a pending extract may next be tried, as a unix timestamp
	 */
	public function setNextAttemptTime($value): void
	{
		$this->_nextAttemptTime = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return int when the page was last fetched, as a unix timestamp, 0 for never
	 */
	public function getFetchedTime(): int
	{
		return $this->_fetchedTime;
	}

	/**
	 * @param int $value when the page was last fetched, as a unix timestamp, 0 for never
	 */
	public function setFetchedTime($value): void
	{
		$this->_fetchedTime = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return int when the extract was first asked for, as a unix timestamp
	 */
	public function getCreatedTime(): int
	{
		return $this->_createdTime;
	}

	/**
	 * @param int $value when the extract was first asked for, as a unix timestamp
	 */
	public function setCreatedTime($value): void
	{
		$this->_createdTime = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return int when the extract last changed, as a unix timestamp
	 */
	public function getUpdatedTime(): int
	{
		return $this->_updatedTime;
	}

	/**
	 * @param int $value when the extract last changed, as a unix timestamp
	 */
	public function setUpdatedTime($value): void
	{
		$this->_updatedTime = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return bool whether the page was fetched and read
	 */
	public function getIsExtracted(): bool
	{
		return $this->_status === TWebExtractor::STATUS_EXTRACTED;
	}

	/**
	 * @return bool whether it is still waiting to be fetched, for the first time or again
	 */
	public function getIsPending(): bool
	{
		return $this->_status === TWebExtractor::STATUS_PENDING;
	}

	/**
	 * @return string the host of the address the page was found at, without a leading 'www.'
	 */
	public function getHost(): string
	{
		$host = (string) parse_url($this->_finalUrl !== '' ? $this->_finalUrl : $this->_url, PHP_URL_HOST);

		return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
	}
}
