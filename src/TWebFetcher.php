<?php

/**
 * TWebFetcher class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-webextract
 * @license https://github.com/belisoful/prado-webextract/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\WebExtract;

use Nyholm\Psr7\Factory\Psr17Factory;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\TPropertyValue;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TWebFetcher class.
 *
 * Fetches a web page that somebody else chose, which is the whole difficulty. A site that reads
 * the links its users post is a machine anyone can point at any address, so the address has to
 * be treated as hostile:
 *
 * - Only `http` and `https`, and no user name or password in the address.
 * - No private, loopback, or link-local network, checked on the address the name resolves to --
 *   not the name -- and checked again at every redirect, so a public page cannot bounce the
 *   request to `127.0.0.1` or to a cloud provider's metadata service. The resolved address is
 *   the one connected to, so the name cannot be re-pointed between the check and the connection.
 * - A cap on the size of the body, enforced while it downloads rather than after, and caps on the
 *   time spent waiting and the number of redirects followed.
 * - Only text is downloaded. Anything else is identified by its headers and not read.
 *
 * The network checks are Symfony's {@see \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient};
 * this class sets the limits around it and reports failures as a {@see TWebFetchException} that
 * says whether trying again later could help.
 *
 * {@see setTransport} replaces the client underneath -- a Symfony `MockHttpClient` in tests, or a
 * client with a proxy configured -- and the private network checks still wrap it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TWebFetcher extends TComponent
{
	/** The User-Agent a fetch identifies itself with unless another is set. */
	public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; PradoWebExtract/1.0; +https://github.com/belisoful/prado-webextract)';

	/** The media types whose bodies are downloaded; anything else is identified and left. */
	public const TEXT_TYPES = ['text/html', 'application/xhtml+xml', 'text/plain'];

	/** @var float seconds to wait for the server to send anything */
	private float $_timeout = 10.0;

	/** @var float seconds a whole fetch may take, redirects included */
	private float $_maxDuration = 30.0;

	/** @var int the largest body read, in bytes */
	private int $_maxBytes = 2097152;

	/** @var int the most redirects followed */
	private int $_maxRedirects = 5;

	/** @var string the User-Agent header sent */
	private string $_userAgent = self::DEFAULT_USER_AGENT;

	/** @var bool whether private, loopback, and link-local addresses may be fetched */
	private bool $_allowPrivateNetworks = false;

	/** @var null|HttpClientInterface the client the checks and limits wrap, null for the default */
	private ?HttpClientInterface $_transport = null;

	/** @var null|HttpClientInterface the client with checks and limits, built on first use */
	private ?HttpClientInterface $_client = null;

	/**
	 * @return float seconds to wait for the server to send anything, 10 by default
	 */
	public function getTimeout(): float
	{
		return $this->_timeout;
	}

	/**
	 * @param float|string $value seconds to wait for the server to send anything
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is not positive.
	 */
	public function setTimeout($value): void
	{
		$this->_timeout = $this->ensurePositive(TPropertyValue::ensureFloat($value), 'Timeout');
		$this->_client = null;
	}

	/**
	 * @return float seconds a whole fetch may take, redirects included, 30 by default
	 */
	public function getMaxDuration(): float
	{
		return $this->_maxDuration;
	}

	/**
	 * @param float|string $value seconds a whole fetch may take
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is not positive.
	 */
	public function setMaxDuration($value): void
	{
		$this->_maxDuration = $this->ensurePositive(TPropertyValue::ensureFloat($value), 'MaxDuration');
		$this->_client = null;
	}

	/**
	 * @return int the largest body read, in bytes, 2 MiB by default
	 */
	public function getMaxBytes(): int
	{
		return $this->_maxBytes;
	}

	/**
	 * @param int|string $value the largest body read, in bytes
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is not positive.
	 */
	public function setMaxBytes($value): void
	{
		$this->_maxBytes = (int) $this->ensurePositive(TPropertyValue::ensureInteger($value), 'MaxBytes');
		$this->_client = null;
	}

	/**
	 * @return int the most redirects followed, 5 by default
	 */
	public function getMaxRedirects(): int
	{
		return $this->_maxRedirects;
	}

	/**
	 * @param int|string $value the most redirects followed; 0 follows none
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is negative.
	 */
	public function setMaxRedirects($value): void
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 0) {
			throw new TInvalidDataValueException('webextract_fetcher_limit_invalid', 'MaxRedirects', $value);
		}
		$this->_maxRedirects = $value;
		$this->_client = null;
	}

	/**
	 * @return string the User-Agent header sent
	 */
	public function getUserAgent(): string
	{
		return $this->_userAgent;
	}

	/**
	 * @param string $value the User-Agent header sent; '' restores the default
	 */
	public function setUserAgent($value): void
	{
		$value = trim(TPropertyValue::ensureString($value));
		$this->_userAgent = $value === '' ? self::DEFAULT_USER_AGENT : $value;
		$this->_client = null;
	}

	/**
	 * @return bool whether private, loopback, and link-local addresses may be fetched, false by
	 *   default
	 */
	public function getAllowPrivateNetworks(): bool
	{
		return $this->_allowPrivateNetworks;
	}

	/**
	 * Lets pages on private networks be fetched. Only for a site whose addresses come from people
	 * it trusts -- an intranet, a development machine -- never for addresses the public submits.
	 * @param bool|string $value whether private, loopback, and link-local addresses may be fetched
	 */
	public function setAllowPrivateNetworks($value): void
	{
		$this->_allowPrivateNetworks = TPropertyValue::ensureBoolean($value);
		$this->_client = null;
	}

	/**
	 * @return null|HttpClientInterface the client the checks and limits wrap, null for Symfony's
	 *   default for this machine
	 */
	public function getTransport(): ?HttpClientInterface
	{
		return $this->_transport;
	}

	/**
	 * @param null|HttpClientInterface $value the client the checks and limits wrap, null for
	 *   Symfony's default for this machine
	 */
	public function setTransport(?HttpClientInterface $value): void
	{
		$this->_transport = $value;
		$this->_client = null;
	}

	/**
	 * @return HttpClientInterface the client every fetch goes through, with the network checks and
	 *   the limits applied
	 */
	public function getHttpClient(): HttpClientInterface
	{
		if ($this->_client === null) {
			$client = $this->_transport ?? HttpClient::create();
			if (!$this->_allowPrivateNetworks) {
				$client = new NoPrivateNetworkHttpClient($client);
			}
			$this->_client = $client->withOptions($this->getRequestOptions());
		}

		return $this->_client;
	}

	/**
	 * @return Psr18Client the same client as a PSR-18 client, with the same checks and limits,
	 *   for libraries that make their own requests
	 */
	public function getPsr18Client(): Psr18Client
	{
		$factory = new Psr17Factory();

		return new Psr18Client($this->getHttpClient(), $factory, $factory);
	}

	/**
	 * Fetches a page.
	 *
	 * A body is downloaded only for the media types in {@see TEXT_TYPES}; for anything else the
	 * result carries the media type and an empty body.
	 * @param string $url the address to fetch
	 * @throws TWebFetchException when the address is refused, the server cannot be reached, it
	 *   answers with an error, or the body is too large.
	 * @return TWebFetchResult what came back
	 */
	public function fetch(string $url): TWebFetchResult
	{
		$this->assertFetchable($url);
		$client = $this->getHttpClient();
		try {
			$response = $client->request('GET', $url);
			$status = $response->getStatusCode();
			$headers = $response->getHeaders(false);
			$finalUrl = (string) $response->getInfo('url');

			if ($status >= 300) {
				$response->cancel();

				throw $this->statusException($status);
			}

			[$contentType, $charset] = self::parseContentType($headers['content-type'][0] ?? '');
			if (!in_array($contentType, self::TEXT_TYPES, true)) {
				$response->cancel();

				return new TWebFetchResult($url, $finalUrl, $status, $contentType, '', '');
			}

			$declared = (int) ($headers['content-length'][0] ?? 0);
			if ($declared > $this->_maxBytes) {
				$response->cancel();

				throw new TWebFetchException('webextract_fetch_too_large', $this->_maxBytes);
			}

			$body = '';
			foreach ($client->stream($response) as $chunk) {
				$body .= $chunk->getContent();
				if (strlen($body) > $this->_maxBytes) {
					$response->cancel();

					throw new TWebFetchException('webextract_fetch_too_large', $this->_maxBytes);
				}
			}
		} catch (ExceptionInterface $e) {
			throw $this->transportException($e);
		}

		[$body, $charset] = self::toUtf8($body, $charset, $contentType !== 'text/plain');

		return new TWebFetchResult($url, $finalUrl, $status, $contentType, $charset, $body);
	}

	/**
	 * Splits a Content-Type header into its media type and character set.
	 * @param string $header the header value, such as 'text/html; charset=ISO-8859-1'
	 * @return array{0: string, 1: string} the media type and character set, both lower case; the
	 *   media type is 'application/octet-stream' and the character set '' when not given
	 */
	public static function parseContentType(string $header): array
	{
		$parts = explode(';', $header);
		$type = strtolower(trim(array_shift($parts)));
		$charset = '';
		foreach ($parts as $part) {
			$pair = explode('=', $part, 2);
			if (count($pair) === 2 && strtolower(trim($pair[0])) === 'charset') {
				$charset = strtolower(trim($pair[1], " \t\"'"));
			}
		}

		return [$type === '' ? 'application/octet-stream' : $type, $charset];
	}

	/**
	 * Converts a body to UTF-8.
	 *
	 * The character set comes from, in order: a byte order mark, the Content-Type header, a
	 * `<meta>` tag near the top of an HTML document, and then a guess -- UTF-8 when the bytes are
	 * valid UTF-8, and Windows-1252, what browsers assume for an unlabelled Western page,
	 * otherwise. Bytes that are still invalid afterwards are replaced rather than kept.
	 * @param string $body the body as it arrived
	 * @param string $charset the character set the Content-Type header named, '' for none
	 * @param bool $isHtml whether to look for a `<meta>` tag naming the character set
	 * @return array{0: string, 1: string} the body in UTF-8, and the character set it arrived in
	 */
	public static function toUtf8(string $body, string $charset = '', bool $isHtml = true): array
	{
		if (str_starts_with($body, "\xEF\xBB\xBF")) {
			return [mb_scrub(substr($body, 3), 'UTF-8'), 'utf-8'];
		}
		if ($charset === '' && $isHtml) {
			$head = substr($body, 0, 4096);
			if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_:.\-]+)/i', $head, $match)) {
				$charset = strtolower($match[1]);
			}
		}
		if ($charset === '') {
			$charset = mb_check_encoding($body, 'UTF-8') ? 'utf-8' : 'windows-1252';
		}
		if (in_array($charset, ['utf-8', 'utf8'], true)) {
			return [mb_scrub($body, 'UTF-8'), 'utf-8'];
		}
		// A page that declares a character set PHP does not know is read as UTF-8, which keeps
		// the ASCII -- the markup and most of the words -- and replaces the rest.
		try {
			$converted = mb_convert_encoding($body, 'UTF-8', $charset);
		} catch (\ValueError $e) {
			return [mb_scrub($body, 'UTF-8'), $charset];
		}

		return [mb_scrub($converted, 'UTF-8'), $charset];
	}

	/**
	 * @return array the default options for every request through {@see getHttpClient}
	 */
	protected function getRequestOptions(): array
	{
		$maxBytes = $this->_maxBytes;

		return [
			'timeout' => $this->_timeout,
			'max_duration' => $this->_maxDuration,
			'max_redirects' => $this->_maxRedirects,
			'headers' => [
				'User-Agent' => $this->_userAgent,
				'Accept' => 'text/html,application/xhtml+xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
			],
			// fetch() stops a large body itself; this also stops the requests that other
			// libraries make through getPsr18Client(), which read their bodies whole.
			'on_progress' => static function (int $downloaded, int $expected) use ($maxBytes): void {
				if ($downloaded > $maxBytes || $expected > $maxBytes) {
					throw new TWebFetchException('webextract_fetch_too_large', $maxBytes);
				}
			},
		];
	}

	/**
	 * @param string $url the address to check
	 * @throws TWebFetchException when the address is not an http or https address with a host,
	 *   or carries a user name or password.
	 */
	protected function assertFetchable(string $url): void
	{
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
			throw new TWebFetchException('webextract_url_invalid', $url);
		}
		if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			throw new TWebFetchException('webextract_url_scheme_refused', $parts['scheme']);
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new TWebFetchException('webextract_url_credentials_refused', $url);
		}
	}

	/**
	 * @param int $status an HTTP status of 300 or more
	 * @return TWebFetchException the failure, transient for a server error, a timeout, or being
	 *   asked to slow down
	 */
	protected function statusException(int $status): TWebFetchException
	{
		if ($status < 400) {
			return (new TWebFetchException('webextract_fetch_too_many_redirects', $this->_maxRedirects))
				->setHttpStatus($status);
		}

		return (new TWebFetchException('webextract_fetch_http_status', $status))
			->setHttpStatus($status)
			->setIsTransient($status >= 500 || in_array($status, [408, 425, 429], true));
	}

	/**
	 * @param ExceptionInterface $e what the HTTP client threw
	 * @return TWebFetchException the failure; a refused address and a body too large are
	 *   permanent, and anything else the network did is transient
	 */
	protected function transportException(ExceptionInterface $e): TWebFetchException
	{
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof TWebFetchException) {
				return $cause;
			}
		}
		$message = $e->getMessage();
		// NoPrivateNetworkHttpClient reports a refused address only in its message.
		if (preg_match('/^Host "([^"]*)" is blocked/', $message, $match)) {
			return new TWebFetchException('webextract_fetch_host_blocked', $match[1]);
		}

		return (new TWebFetchException('webextract_fetch_failed', $message))->setIsTransient(true);
	}

	/**
	 * @param float|int $value the value to check
	 * @param string $name the property being set, for the message
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is not positive.
	 * @return float|int the value
	 */
	private function ensurePositive($value, string $name)
	{
		if ($value <= 0) {
			throw new TInvalidDataValueException('webextract_fetcher_limit_invalid', $name, $value);
		}

		return $value;
	}
}
