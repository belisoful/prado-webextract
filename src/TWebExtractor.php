<?php

/**
 * TWebExtractor class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-webextract
 * @license https://github.com/belisoful/prado-webextract/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\WebExtract;

use PDO;
use Prado\Data\TDbDriver;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TPropertyValue;
use Prado\Util\TDbModule;
use Prado\Util\Traits\TInitializedTrait;

/**
 * TWebExtractor class.
 *
 * Turns web addresses into what they say: fetches each page safely, reads the article out of it
 * as plain text, and keeps what the page says about itself -- title, summary, author, site,
 * language, image, and publication date -- in a table, one row per address.
 *
 * ```xml
 * <module id="webextract" class="TWebExtractor" ConnectionID="db" />
 * ```
 *
 * Fetching takes seconds and sometimes fails, so it does not belong in a page request. The usual
 * shape is to {@see queue} an address when it arrives and let {@see processQueue} fetch what is
 * waiting, from PRADO's cron:
 *
 * ```xml
 * <module id="cron" class="Prado\Util\Cron\TCronModule">
 *     <job Name="webextract" Schedule="* * * * *" Task="webextract->processQueue(10)" />
 * </module>
 * ```
 *
 * A host with no cron can set {@see setProcessOnEndRequest}, which fetches a few waiting pages
 * after each response has gone to the browser. {@see extract} fetches one address immediately,
 * for a caller that can wait.
 *
 * An address that fails in a way that could go differently later -- a timeout, a 503 -- stays
 * waiting and is tried again after {@see getRetryDelay}, doubling each time, up to
 * {@see getMaxAttempts}. One that can never succeed -- a private network address, a 404, a page
 * too large -- fails at once.
 *
 * The safety rules for fetching belong to {@see TWebFetcher}, and reading a page to
 * {@see TWebPageReader}; the fetcher's limits are also properties here so they can be set from
 * the module configuration.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TWebExtractor extends TDbModule
{
	use TInitializedTrait;

	/** Waiting to be fetched, for the first time or again. */
	public const STATUS_PENDING = 0;

	/** Fetched and read. */
	public const STATUS_EXTRACTED = 1;

	/** Could not be fetched, and will not be tried again unless asked. */
	public const STATUS_FAILED = 2;

	/** Fetched, but not something this reads -- a PDF, an image, a video file. */
	public const STATUS_UNSUPPORTED = 3;

	/** The query parameters taken off an address by default: tracking, not content. */
	public const DEFAULT_STRIP_PARAMETERS = 'utm_*,fbclid,gclid,dclid,gbraid,wbraid,msclkid,mc_cid,mc_eid,igshid,_ga,_gl';

	/** @var string the table holding the extracts */
	private string $_tableName = 'web_extracts';

	/** @var bool whether a missing table is created on first use */
	private bool $_autoCreateTables = true;

	/** @var int how many times an address is tried before it fails for good */
	private int $_maxAttempts = 3;

	/** @var int seconds before the first retry; each later one waits twice as long */
	private int $_retryDelay = 3600;

	/** @var int seconds a queue worker holds an address before another may take it */
	private int $_leaseTime = 300;

	/** @var null|string[] the query parameters taken off an address; a trailing '*' matches a prefix */
	private ?array $_stripParameters = null;

	/** @var int how many waiting addresses to fetch after each response, 0 for none */
	private int $_processOnEndRequest = 0;

	/** @var null|TWebFetcher the fetcher, made on first use */
	private ?TWebFetcher $_fetcher = null;

	/** @var null|TWebPageReader the reader, made on first use */
	private ?TWebPageReader $_reader = null;

	/** @var bool whether the table has been checked for */
	private bool $_tablesEnsured = false;

	/**
	 * Initializes the module, and when {@see getProcessOnEndRequest} asks for it, arranges to
	 * fetch waiting addresses after each response.
	 * @param null|array|\Prado\Xml\TXmlElement $config the module configuration
	 */
	public function init($config)
	{
		parent::init($config);
		if ($this->_processOnEndRequest > 0 && ($application = $this->getApplication()) !== null) {
			$application->attachEventHandler('OnEndRequest', [$this, 'processAfterResponse']);
		}
		$this->markInitialized();
	}

	/**
	 * @return string the table holding the extracts, 'web_extracts' by default
	 */
	public function getTableName(): string
	{
		return $this->_tableName;
	}

	/**
	 * @param string $value the table holding the extracts
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setTableName($value): void
	{
		$this->assertUninitialized('TableName');
		$this->_tableName = TPropertyValue::ensureString($value);
	}

	/**
	 * @return bool whether a missing table is created on first use, true by default
	 */
	public function getAutoCreateTables(): bool
	{
		return $this->_autoCreateTables;
	}

	/**
	 * @param bool|string $value whether a missing table is created on first use
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 */
	public function setAutoCreateTables($value): void
	{
		$this->assertUninitialized('AutoCreateTables');
		$this->_autoCreateTables = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return int how many times an address is tried before it fails for good, 3 by default
	 */
	public function getMaxAttempts(): int
	{
		return $this->_maxAttempts;
	}

	/**
	 * @param int|string $value how many times an address is tried before it fails for good
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is less than 1.
	 */
	public function setMaxAttempts($value): void
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 1) {
			throw new TInvalidDataValueException('webextract_setting_invalid', 'MaxAttempts', $value);
		}
		$this->_maxAttempts = $value;
	}

	/**
	 * @return int seconds before the first retry, 3600 by default; each later one waits twice as
	 *   long as the one before
	 */
	public function getRetryDelay(): int
	{
		return $this->_retryDelay;
	}

	/**
	 * @param int|string $value seconds before the first retry
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is negative.
	 */
	public function setRetryDelay($value): void
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 0) {
			throw new TInvalidDataValueException('webextract_setting_invalid', 'RetryDelay', $value);
		}
		$this->_retryDelay = $value;
	}

	/**
	 * @return int seconds a queue worker holds an address before another may take it, 300 by
	 *   default; longer than a fetch can take, so two workers never fetch the same page
	 */
	public function getLeaseTime(): int
	{
		return $this->_leaseTime;
	}

	/**
	 * @param int|string $value seconds a queue worker holds an address
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is less than 1.
	 */
	public function setLeaseTime($value): void
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 1) {
			throw new TInvalidDataValueException('webextract_setting_invalid', 'LeaseTime', $value);
		}
		$this->_leaseTime = $value;
	}

	/**
	 * @return string the query parameters taken off an address before it is stored, comma
	 *   separated; a trailing '*' matches every parameter starting with what comes before it
	 */
	public function getStripParameters(): string
	{
		return implode(',', $this->getStripParameterList());
	}

	/**
	 * Sets the query parameters taken off an address before it is stored, so that the same
	 * article shared from two places is one address. The default takes off the tracking
	 * parameters that analytics and advertising add, and nothing that changes the page.
	 * @param string $value comma separated parameter names; '' takes off none
	 */
	public function setStripParameters($value): void
	{
		$names = array_map('trim', explode(',', strtolower(TPropertyValue::ensureString($value))));
		$this->_stripParameters = array_values(array_filter($names, fn ($name) => $name !== ''));
	}

	/**
	 * @return int how many waiting addresses are fetched after each response, 0 (none) by default
	 */
	public function getProcessOnEndRequest(): int
	{
		return $this->_processOnEndRequest;
	}

	/**
	 * Fetches waiting addresses at the end of each request, after the response has gone to the
	 * browser, for a host with no cron. On PHP-FPM and LiteSpeed the browser does not wait for
	 * it; elsewhere it does, so keep it small. The session is closed first so that the visitor's
	 * next request is not held up behind it.
	 * @param int|string $value how many waiting addresses to fetch, 0 for none
	 * @throws \Prado\Exceptions\TInvalidOperationException when the module is already initialized.
	 * @throws \Prado\Exceptions\TInvalidDataValueException when it is negative.
	 */
	public function setProcessOnEndRequest($value): void
	{
		$this->assertUninitialized('ProcessOnEndRequest');
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 0) {
			throw new TInvalidDataValueException('webextract_setting_invalid', 'ProcessOnEndRequest', $value);
		}
		$this->_processOnEndRequest = $value;
	}

	/**
	 * @return TWebFetcher the fetcher pages are fetched with
	 */
	public function getFetcher(): TWebFetcher
	{
		return $this->_fetcher ??= new TWebFetcher();
	}

	/**
	 * @param TWebFetcher $value the fetcher pages are fetched with
	 */
	public function setFetcher(TWebFetcher $value): void
	{
		$this->_fetcher = $value;
	}

	/**
	 * @return TWebPageReader the reader pages are read with
	 */
	public function getReader(): TWebPageReader
	{
		return $this->_reader ??= new TWebPageReader();
	}

	/**
	 * @param TWebPageReader $value the reader pages are read with
	 */
	public function setReader(TWebPageReader $value): void
	{
		$this->_reader = $value;
	}

	/**
	 * @return float seconds to wait for a server to send anything; {@see TWebFetcher::getTimeout}
	 */
	public function getTimeout(): float
	{
		return $this->getFetcher()->getTimeout();
	}

	/**
	 * @param float|string $value seconds to wait for a server to send anything
	 */
	public function setTimeout($value): void
	{
		$this->getFetcher()->setTimeout($value);
	}

	/**
	 * @return float seconds a whole fetch may take; {@see TWebFetcher::getMaxDuration}
	 */
	public function getMaxDuration(): float
	{
		return $this->getFetcher()->getMaxDuration();
	}

	/**
	 * @param float|string $value seconds a whole fetch may take
	 */
	public function setMaxDuration($value): void
	{
		$this->getFetcher()->setMaxDuration($value);
	}

	/**
	 * @return int the largest page read, in bytes; {@see TWebFetcher::getMaxBytes}
	 */
	public function getMaxBytes(): int
	{
		return $this->getFetcher()->getMaxBytes();
	}

	/**
	 * @param int|string $value the largest page read, in bytes
	 */
	public function setMaxBytes($value): void
	{
		$this->getFetcher()->setMaxBytes($value);
	}

	/**
	 * @return int the most redirects followed; {@see TWebFetcher::getMaxRedirects}
	 */
	public function getMaxRedirects(): int
	{
		return $this->getFetcher()->getMaxRedirects();
	}

	/**
	 * @param int|string $value the most redirects followed
	 */
	public function setMaxRedirects($value): void
	{
		$this->getFetcher()->setMaxRedirects($value);
	}

	/**
	 * @return string the User-Agent header sent; {@see TWebFetcher::getUserAgent}
	 */
	public function getUserAgent(): string
	{
		return $this->getFetcher()->getUserAgent();
	}

	/**
	 * @param string $value the User-Agent header sent
	 */
	public function setUserAgent($value): void
	{
		$this->getFetcher()->setUserAgent($value);
	}

	/**
	 * @return bool whether private network addresses may be fetched;
	 *   {@see TWebFetcher::getAllowPrivateNetworks}
	 */
	public function getAllowPrivateNetworks(): bool
	{
		return $this->getFetcher()->getAllowPrivateNetworks();
	}

	/**
	 * @param bool|string $value whether private network addresses may be fetched; never for
	 *   addresses the public submits
	 */
	public function setAllowPrivateNetworks($value): void
	{
		$this->getFetcher()->setAllowPrivateNetworks($value);
	}

	/**
	 * @return bool whether to read what pages say about themselves; {@see TWebPageReader::getReadMetadata}
	 */
	public function getReadMetadata(): bool
	{
		return $this->getReader()->getReadMetadata();
	}

	/**
	 * @param bool|string $value whether to read what pages say about themselves
	 */
	public function setReadMetadata($value): void
	{
		$this->getReader()->setReadMetadata($value);
	}

	/**
	 * Puts an address in its stored form: scheme and host in lower case, no default port, no
	 * fragment, and none of the query parameters in {@see getStripParameters}. Two ways of writing
	 * the same page become one address, and so one extract.
	 * @param string $url the address
	 * @throws TWebFetchException when it is not an http or https address with a host, carries a
	 *   user name or password, or is longer than 2048 characters.
	 * @return string the address in its stored form
	 */
	public function normalizeUrl(string $url): string
	{
		$url = trim($url);
		$parts = parse_url($url);
		if ($url === '' || strlen($url) > 2048 || $parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
			throw new TWebFetchException('webextract_url_invalid', $url);
		}
		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'http' && $scheme !== 'https') {
			throw new TWebFetchException('webextract_url_scheme_refused', $parts['scheme']);
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new TWebFetchException('webextract_url_credentials_refused', $url);
		}
		$result = $scheme . '://' . strtolower($parts['host']);
		if (isset($parts['port']) && !($scheme === 'http' && $parts['port'] === 80) && !($scheme === 'https' && $parts['port'] === 443)) {
			$result .= ':' . $parts['port'];
		}
		$result .= $parts['path'] ?? '/';
		if (isset($parts['query'])) {
			$kept = array_filter(explode('&', $parts['query']), fn ($pair) => $pair !== '' && !$this->isStrippedParameter($pair));
			if ($kept !== []) {
				$result .= '?' . implode('&', $kept);
			}
		}

		return $result;
	}

	/**
	 * @param string $url an address, in any form {@see normalizeUrl} accepts
	 * @return null|TWebExtract the extract for it, null when there is none or the address is not
	 *   one that could have one
	 */
	public function find(string $url): ?TWebExtract
	{
		try {
			$url = $this->normalizeUrl($url);
		} catch (TWebFetchException $e) {
			return null;
		}

		return $this->findOne('url_hash = :hash', [':hash' => self::hashUrl($url)]);
	}

	/**
	 * @param int $id the id of an extract
	 * @return null|TWebExtract the extract, null when there is none
	 */
	public function findById(int $id): ?TWebExtract
	{
		return $this->findOne('id = :id', [':id' => $id]);
	}

	/**
	 * Finds the extracts for several addresses at once, as a page showing a post's links needs.
	 * @param string[] $urls addresses, in any form {@see normalizeUrl} accepts
	 * @return TWebExtract[] the extracts there are, keyed by the address as it was given
	 */
	public function findAll(array $urls): array
	{
		$hashes = [];
		foreach ($urls as $url) {
			try {
				$hashes[self::hashUrl($this->normalizeUrl((string) $url))][] = (string) $url;
			} catch (TWebFetchException $e) {
			}
		}
		if ($hashes === []) {
			return [];
		}
		$this->ensureTables();
		$names = [];
		foreach (array_keys($hashes) as $i => $hash) {
			$names[':h' . $i] = $hash;
		}
		$command = $this->getDbConnection()->createCommand(
			'SELECT * FROM ' . $this->getTableName() . ' WHERE url_hash IN (' . implode(', ', array_keys($names)) . ')'
		);
		foreach ($names as $name => $hash) {
			$command->bindValue($name, $hash, PDO::PARAM_STR);
		}
		$found = [];
		foreach ($command->query()->readAll() as $row) {
			$extract = $this->populate($row);
			foreach ($hashes[$row['url_hash']] as $url) {
				$found[$url] = $extract;
			}
		}

		return $found;
	}

	/**
	 * Asks for an address to be fetched later, by {@see processQueue}. An address already known
	 * is left as it is.
	 * @param string $url the address
	 * @throws TWebFetchException when the address is not one that can be fetched.
	 * @return TWebExtract its extract, waiting or already done
	 */
	public function queue(string $url): TWebExtract
	{
		$url = $this->normalizeUrl($url);

		return $this->findOne('url_hash = :hash', [':hash' => self::hashUrl($url)]) ?? $this->insert($url);
	}

	/**
	 * Queues several addresses, skipping any that cannot be fetched.
	 * @param string[] $urls the addresses
	 * @return TWebExtract[] their extracts, keyed by the address as it was given
	 */
	public function queueAll(array $urls): array
	{
		$extracts = [];
		foreach ($urls as $url) {
			try {
				$extracts[(string) $url] = $this->queue((string) $url);
			} catch (TWebFetchException $e) {
			}
		}

		return $extracts;
	}

	/**
	 * Fetches an address now and returns what was learned, for a caller that can wait. An address
	 * already fetched, or already given up on, is returned as it is unless $refresh asks again.
	 * @param string $url the address
	 * @param bool $refresh whether to fetch it again even when it has been fetched
	 * @throws TWebFetchException when the address is not one that can be fetched.
	 * @return TWebExtract its extract; a failure is recorded on it rather than thrown
	 */
	public function extract(string $url, bool $refresh = false): TWebExtract
	{
		$extract = $this->queue($url);
		if ($refresh || $extract->getIsPending()) {
			$extract = $this->run($extract);
		}

		return $extract;
	}

	/**
	 * Fetches waiting addresses, oldest first. Several workers can run at once: each address is
	 * leased to the worker that takes it, for {@see getLeaseTime} seconds.
	 * @param int $limit the most addresses to fetch
	 * @return int how many were fetched, whether or not they succeeded
	 */
	public function processQueue(int $limit = 10): int
	{
		$done = 0;
		foreach ($this->findDue($limit) as $id) {
			if (!$this->lease($id)) {
				continue;	// another worker took it first
			}
			$extract = $this->findById($id);
			if ($extract !== null) {
				$this->run($extract);
				$done++;
			}
		}

		return $done;
	}

	/**
	 * Handles the application's OnEndRequest when {@see getProcessOnEndRequest} is set: finishes
	 * the response, closes the session, and fetches a few waiting addresses.
	 * @param mixed $sender the application
	 * @param mixed $param unused
	 */
	public function processAfterResponse($sender, $param): void
	{
		if ($this->findDue(1) === []) {
			return;
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} elseif (function_exists('litespeed_finish_request')) {
			litespeed_finish_request();
		}
		$this->processQueue($this->_processOnEndRequest);
	}

	/**
	 * @param int $status one of the STATUS_* constants
	 * @return int how many extracts are in that state
	 */
	public function countByStatus(int $status): int
	{
		$this->ensureTables();
		$command = $this->getDbConnection()->createCommand(
			'SELECT COUNT(*) FROM ' . $this->getTableName() . ' WHERE status = :status'
		);
		$command->bindValue(':status', $status, PDO::PARAM_INT);

		return (int) $command->queryScalar();
	}

	/**
	 * Deletes the extract for an address.
	 * @param string $url the address
	 * @return bool whether there was one to delete
	 */
	public function remove(string $url): bool
	{
		$extract = $this->find($url);
		if ($extract === null) {
			return false;
		}
		$command = $this->getDbConnection()->createCommand('DELETE FROM ' . $this->getTableName() . ' WHERE id = :id');
		$command->bindValue(':id', $extract->getId(), PDO::PARAM_INT);

		return $command->execute() > 0;
	}

	/**
	 * Checks for the table, creating it if it is missing and {@see getAutoCreateTables} allows,
	 * for an installer that wants the storage in place before the first address arrives.
	 * @throws \Prado\Exceptions\TConfigurationException when the table is missing and cannot be created.
	 */
	public function ensureStorage(): void
	{
		$this->ensureTables();
	}

	/**
	 * Raised after a page is read and before the extract is stored, while it can still be changed.
	 * A handler can add to it -- a video's transcript as its text, say -- and what it sets is
	 * stored.
	 * @param TWebExtract $extract the extract
	 */
	public function onExtracting(TWebExtract $extract): void
	{
		$this->raiseEvent('onExtracting', $this, $extract);
	}

	/**
	 * Raised after a page is read and stored.
	 * @param TWebExtract $extract the extract
	 */
	public function onExtracted(TWebExtract $extract): void
	{
		$this->raiseEvent('onExtracted', $this, $extract);
	}

	/**
	 * Raised when an address fails for good, or turns out to be something this does not read.
	 * Not raised for a failure that will be tried again.
	 * @param TWebExtract $extract the extract, with the reason in {@see TWebExtract::getError}
	 */
	public function onExtractFailed(TWebExtract $extract): void
	{
		$this->raiseEvent('onExtractFailed', $this, $extract);
	}

	/**
	 * @param string $url an address in its stored form
	 * @return string the key it is found by
	 */
	public static function hashUrl(string $url): string
	{
		return hash('sha256', $url);
	}

	/**
	 * Fetches and reads one address, and stores the outcome.
	 * @param TWebExtract $extract the extract to fetch
	 * @return TWebExtract the extract as it now stands
	 */
	protected function run(TWebExtract $extract): TWebExtract
	{
		$now = time();
		$result = new TWebExtract();
		$result->setId($extract->getId());
		$result->setUrl($extract->getUrl());
		$result->setCreatedTime($extract->getCreatedTime());
		$result->setAttempts($extract->getAttempts() + 1);
		$result->setFetchedTime($now);
		try {
			$page = $this->getFetcher()->fetch($extract->getUrl());
			$this->getReader()->read($page, $result, $this->getFetcher()->getPsr18Client());
			$result->setStatus(in_array($page->getContentType(), TWebFetcher::TEXT_TYPES, true) ? self::STATUS_EXTRACTED : self::STATUS_UNSUPPORTED);
			if ($result->getIsExtracted()) {
				$this->onExtracting($result);
			}
		} catch (TWebFetchException $e) {
			$result->setError($e->getMessage());
			$result->setHttpStatus($e->getHttpStatus());
			if ($e->getIsTransient() && $result->getAttempts() < $this->_maxAttempts) {
				$result->setStatus(self::STATUS_PENDING);
				$result->setNextAttemptTime($now + $this->_retryDelay * (2 ** ($result->getAttempts() - 1)));
			} else {
				$result->setStatus(self::STATUS_FAILED);
			}
		} catch (\Exception $e) {
			// A page that breaks the reader would break it every time; it is not tried again.
			$result->setError($e->getMessage());
			$result->setStatus(self::STATUS_FAILED);
		}
		$this->save($result);

		if ($result->getIsExtracted()) {
			$this->onExtracted($result);
		} elseif (!$result->getIsPending()) {
			$this->onExtractFailed($result);
		}

		return $result;
	}

	/**
	 * @param int $limit the most to find
	 * @return int[] the ids of waiting extracts whose time has come and that no worker holds
	 */
	protected function findDue(int $limit): array
	{
		$this->ensureTables();
		$now = time();
		$command = $this->getDbConnection()->createCommand(
			'SELECT id FROM ' . $this->getTableName()
			. ' WHERE status = :status AND next_attempt_time <= :now AND locked_until <= :now2'
			. ' ORDER BY next_attempt_time ASC, id ASC LIMIT ' . max(0, $limit)
		);
		$command->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_INT);
		$command->bindValue(':now', $now, PDO::PARAM_INT);
		$command->bindValue(':now2', $now, PDO::PARAM_INT);

		return array_map('intval', $command->queryColumn());
	}

	/**
	 * Takes an extract for this worker, unless another worker holds it.
	 * @param int $id the extract
	 * @return bool whether this worker has it
	 */
	protected function lease(int $id): bool
	{
		$now = time();
		$command = $this->getDbConnection()->createCommand(
			'UPDATE ' . $this->getTableName() . ' SET locked_until = :until WHERE id = :id AND locked_until <= :now'
		);
		$command->bindValue(':until', $now + $this->_leaseTime, PDO::PARAM_INT);
		$command->bindValue(':id', $id, PDO::PARAM_INT);
		$command->bindValue(':now', $now, PDO::PARAM_INT);

		return $command->execute() > 0;
	}

	/**
	 * Stores a new waiting extract. When another request stored the same address first, that one
	 * is returned instead.
	 * @param string $url the address, in its stored form
	 * @return TWebExtract the extract
	 */
	protected function insert(string $url): TWebExtract
	{
		$this->ensureTables();
		$now = time();
		$hash = self::hashUrl($url);
		$command = $this->getDbConnection()->createCommand(
			'INSERT INTO ' . $this->getTableName() . ' (url_hash, url, status, next_attempt_time, created_time, updated_time)'
			. ' VALUES (:hash, :url, :status, :next, :created, :updated)'
		);
		$command->bindValue(':hash', $hash, PDO::PARAM_STR);
		$command->bindValue(':url', $url, PDO::PARAM_STR);
		$command->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_INT);
		$command->bindValue(':next', $now, PDO::PARAM_INT);
		$command->bindValue(':created', $now, PDO::PARAM_INT);
		$command->bindValue(':updated', $now, PDO::PARAM_INT);
		try {
			$command->execute();
		} catch (\Exception $e) {
			$existing = $this->findOne('url_hash = :hash', [':hash' => $hash]);
			if ($existing === null) {
				throw $e;
			}

			return $existing;
		}

		$extract = new TWebExtract();
		$extract->setId((int) $this->getDbConnection()->getLastInsertID());
		$extract->setUrl($url);
		$extract->setNextAttemptTime($now);
		$extract->setCreatedTime($now);
		$extract->setUpdatedTime($now);

		return $extract;
	}

	/**
	 * Writes an extract over its row, and releases the lease on it.
	 * @param TWebExtract $extract the extract
	 */
	protected function save(TWebExtract $extract): void
	{
		$now = time();
		$extract->setUpdatedTime($now);
		$published = $extract->getPublishedTime();
		$columns = [
			'final_url' => self::clipUrl($extract->getFinalUrl()),
			'status' => $extract->getStatus(),
			'http_status' => $extract->getHttpStatus(),
			'content_type' => self::clip($extract->getContentType(), 128),
			'title' => self::clip($extract->getTitle(), 512),
			'description' => $extract->getDescription(),
			'author' => self::clip($extract->getAuthor(), 255),
			'site_name' => self::clip($extract->getSiteName(), 255),
			'language' => self::clip($extract->getLanguage(), 35),
			'image_url' => self::clipUrl($extract->getImageUrl()),
			// The column is a 32-bit integer; a date past 2038 is taken for a mistake.
			'published_time' => $published > 0 && $published <= 2147483647 ? $published : 0,
			'body_text' => $extract->getText(),
			'word_count' => $extract->getWordCount(),
			'meta' => $extract->getMeta() === [] ? '' : (string) json_encode($extract->getMeta(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			'error' => self::clip($extract->getError(), 1024),
			'attempts' => $extract->getAttempts(),
			'next_attempt_time' => $extract->getNextAttemptTime(),
			'locked_until' => 0,
			'fetched_time' => $extract->getFetchedTime(),
			'updated_time' => $now,
		];
		$assignments = [];
		foreach (array_keys($columns) as $column) {
			$assignments[] = $column . ' = :' . $column;
		}
		$command = $this->getDbConnection()->createCommand(
			'UPDATE ' . $this->getTableName() . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id'
		);
		foreach ($columns as $column => $value) {
			$command->bindValue(':' . $column, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
		}
		$command->bindValue(':id', $extract->getId(), PDO::PARAM_INT);
		$command->execute();
	}

	/**
	 * @param string $where the condition
	 * @param array $parameters its parameters, as name => value
	 * @return null|TWebExtract the first extract matching, null when none does
	 */
	protected function findOne(string $where, array $parameters): ?TWebExtract
	{
		$this->ensureTables();
		$command = $this->getDbConnection()->createCommand(
			'SELECT * FROM ' . $this->getTableName() . ' WHERE ' . $where
		);
		foreach ($parameters as $name => $value) {
			$command->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
		}
		$row = $command->queryRow();

		return is_array($row) ? $this->populate($row) : null;
	}

	/**
	 * @param array $row a row of the table
	 * @return TWebExtract the extract it holds
	 */
	protected function populate(array $row): TWebExtract
	{
		$extract = new TWebExtract();
		$extract->setId((int) $row['id']);
		$extract->setUrl((string) $row['url']);
		$extract->setFinalUrl((string) $row['final_url']);
		$extract->setStatus((int) $row['status']);
		$extract->setHttpStatus((int) $row['http_status']);
		$extract->setContentType((string) $row['content_type']);
		$extract->setTitle((string) $row['title']);
		$extract->setDescription((string) $row['description']);
		$extract->setAuthor((string) $row['author']);
		$extract->setSiteName((string) $row['site_name']);
		$extract->setLanguage((string) $row['language']);
		$extract->setImageUrl((string) $row['image_url']);
		$extract->setPublishedTime((int) $row['published_time']);
		$extract->setText((string) $row['body_text']);
		$extract->setWordCount((int) $row['word_count']);
		$meta = (string) $row['meta'] === '' ? [] : json_decode((string) $row['meta'], true);
		$extract->setMeta(is_array($meta) ? $meta : []);
		$extract->setError((string) $row['error']);
		$extract->setAttempts((int) $row['attempts']);
		$extract->setNextAttemptTime((int) $row['next_attempt_time']);
		$extract->setFetchedTime((int) $row['fetched_time']);
		$extract->setCreatedTime((int) $row['created_time']);
		$extract->setUpdatedTime((int) $row['updated_time']);

		return $extract;
	}

	/**
	 * Checks for the table, creating it when it is missing and {@see getAutoCreateTables} allows.
	 * @throws \Prado\Exceptions\TConfigurationException when the table is missing and cannot be created.
	 */
	protected function ensureTables(): void
	{
		if ($this->_tablesEnsured) {
			return;
		}
		$this->_tablesEnsured = true;
		$table = $this->getTableName();
		try {
			$this->getDbConnection()->createCommand('SELECT * FROM ' . $table . ' WHERE 0=1')->query()->close();
		} catch (\Exception $e) {
			if (!$this->getAutoCreateTables()) {
				throw new TConfigurationException('webextract_table_nonexistent', $table);
			}
			$this->createTable();
		}
	}

	/**
	 * Creates the extracts table.
	 */
	protected function createTable(): void
	{
		$db = $this->getDbConnection();
		$table = $this->getTableName();
		[$autoType, $autoAttributes, $textType] = $this->getDriverTypes();

		$db->createCommand('CREATE TABLE ' . $table . ' ('
			. 'id ' . $autoType . ' PRIMARY KEY' . $autoAttributes . ', '
			. 'url_hash CHAR(64) NOT NULL, '
			. 'url VARCHAR(2048) NOT NULL, '
			. "final_url VARCHAR(2048) NOT NULL DEFAULT '', "
			. 'status INTEGER NOT NULL DEFAULT ' . self::STATUS_PENDING . ', '
			. 'http_status INTEGER NOT NULL DEFAULT 0, '
			. "content_type VARCHAR(128) NOT NULL DEFAULT '', "
			. "title VARCHAR(512) NOT NULL DEFAULT '', "
			. 'description ' . $textType . ', '
			. "author VARCHAR(255) NOT NULL DEFAULT '', "
			. "site_name VARCHAR(255) NOT NULL DEFAULT '', "
			. "language VARCHAR(35) NOT NULL DEFAULT '', "
			. "image_url VARCHAR(2048) NOT NULL DEFAULT '', "
			. 'published_time INTEGER NOT NULL DEFAULT 0, '
			. 'body_text ' . $textType . ', '
			. 'word_count INTEGER NOT NULL DEFAULT 0, '
			. 'meta ' . $textType . ', '
			. "error VARCHAR(1024) NOT NULL DEFAULT '', "
			. 'attempts INTEGER NOT NULL DEFAULT 0, '
			. 'next_attempt_time INTEGER NOT NULL DEFAULT 0, '
			. 'locked_until INTEGER NOT NULL DEFAULT 0, '
			. 'fetched_time INTEGER NOT NULL DEFAULT 0, '
			. 'created_time INTEGER NOT NULL DEFAULT 0, '
			. 'updated_time INTEGER NOT NULL DEFAULT 0'
			. ')')->execute();
		// An address is found by its hash: an index on the address itself would be too long for
		// MySQL. The queue reads waiting rows by when they are due.
		$db->createCommand('CREATE UNIQUE INDEX ' . $table . '_url ON ' . $table . ' (url_hash)')->execute();
		$db->createCommand('CREATE INDEX ' . $table . '_due ON ' . $table . ' (status, next_attempt_time)')->execute();
	}

	/**
	 * @return array the auto-increment type, its attributes, and the long text type for this driver
	 */
	protected function getDriverTypes(): array
	{
		switch ($this->getDbConnection()->getDriverName()) {
			case TDbDriver::DRIVER_SQLITE:
				return ['INTEGER', ' AUTOINCREMENT', 'MEDIUMTEXT'];
			case TDbDriver::DRIVER_PGSQL:
				return ['SERIAL', '', 'TEXT'];
			default:	// mysql
				return ['INTEGER', ' AUTO_INCREMENT', 'MEDIUMTEXT'];
		}
	}

	/**
	 * @return string[] the parameters {@see normalizeUrl} takes off
	 */
	protected function getStripParameterList(): array
	{
		if ($this->_stripParameters === null) {
			$this->setStripParameters(self::DEFAULT_STRIP_PARAMETERS);
		}

		return $this->_stripParameters ?? [];
	}

	/**
	 * @param string $pair a query parameter as it appears in the address, such as 'utm_source=x'
	 * @return bool whether {@see normalizeUrl} takes it off
	 */
	protected function isStrippedParameter(string $pair): bool
	{
		$name = strtolower(urldecode(explode('=', $pair, 2)[0]));
		foreach ($this->getStripParameterList() as $stripped) {
			if (str_ends_with($stripped, '*') ? str_starts_with($name, substr($stripped, 0, -1)) : $name === $stripped) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $value the text
	 * @param int $length the most characters kept
	 * @return string the text, cut to the length
	 */
	private static function clip(string $value, int $length): string
	{
		return mb_strlen($value, 'UTF-8') > $length ? mb_substr($value, 0, $length, 'UTF-8') : $value;
	}

	/**
	 * @param string $url an address
	 * @return string the address, or '' when it is too long to store; a cut address is a wrong one
	 */
	private static function clipUrl(string $url): string
	{
		return strlen($url) > 2048 ? '' : $url;
	}
}
