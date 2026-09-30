<?php

/**
 * TWebFetchException class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-webextract
 * @license https://github.com/belisoful/prado-webextract/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\WebExtract;

use Prado\Exceptions\TNetworkException;

/**
 * TWebFetchException class.
 *
 * A page could not be fetched. It says whether trying again later could go differently: a server
 * that timed out or answered 503 might answer tomorrow, while an address on a private network or
 * a page too large to read will be refused the same way every time.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TWebFetchException extends TNetworkException
{
	/** @var bool whether trying again later could succeed */
	private bool $_transient = false;

	/** @var int the HTTP status the server answered with, 0 when there was no answer */
	private int $_httpStatus = 0;

	/**
	 * @return bool whether trying again later could succeed
	 */
	public function getIsTransient(): bool
	{
		return $this->_transient;
	}

	/**
	 * @param bool $value whether trying again later could succeed
	 * @return static this exception
	 */
	public function setIsTransient(bool $value): static
	{
		$this->_transient = $value;

		return $this;
	}

	/**
	 * @return int the HTTP status the server answered with, 0 when there was no answer
	 */
	public function getHttpStatus(): int
	{
		return $this->_httpStatus;
	}

	/**
	 * @param int $value the HTTP status the server answered with
	 * @return static this exception
	 */
	public function setHttpStatus(int $value): static
	{
		$this->_httpStatus = $value;

		return $this;
	}
}
