<?php

/**
 * TWebNoRequestClient class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-webextract
 * @license https://github.com/belisoful/prado-webextract/blob/master/LICENSE
 */

namespace Belisoful\Prado\Util\WebExtract;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * TWebNoRequestClient class.
 *
 * A PSR-18 client that makes no requests. {@see TWebPageReader} hands it to Embed when it was
 * given no client of its own, so that Embed reads the page in front of it and asks nobody else.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TWebNoRequestClient implements ClientInterface
{
	/**
	 * Refuses the request.
	 * @param RequestInterface $request the request
	 * @throws TWebFetchException always.
	 * @return ResponseInterface never
	 */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		throw new TWebFetchException('webextract_fetch_not_allowed', (string) $request->getUri());
	}
}
