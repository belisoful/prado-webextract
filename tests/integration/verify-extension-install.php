<?php

/**
 * Composer-extension integration checks.
 *
 * The unit suite registers the extension's error messages and class map by hand, so it cannot
 * prove that a real `composer require` wires them up. This script runs inside a throwaway
 * consumer project that installed the extension through Composer and asserts the three things
 * `composer.json`'s `extra.prado` section promises:
 *
 * - `error-messages` registers `config/errorMessages.txt`, so the package's codes resolve to text.
 * - `class-map` registers the Prado3 short names, so `TWebExtractor` resolves to its FQN.
 * - `bootstrap` names the module, so `<module id="belisoful/prado-webextract"/>` boots it.
 *
 * Each mode runs in its own process because a Prado application is a per-process singleton.
 *
 *     php verify-extension-install.php <capture|boot> <consumer-dir>
 *
 * Exits non-zero with a message on the first failed check.
 */

use Belisoful\Prado\Util\WebExtract\TWebExtract;
use Belisoful\Prado\Util\WebExtract\TWebExtractor;
use Belisoful\Prado\Util\WebExtract\TWebFetchResult;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TException;
use Prado\Prado;
use Prado\TApplication;
use Prado\TApplicationConfiguration;
use Prado\Util\TComposerReflection;

$mode = $argv[1] ?? '';
$dir = $argv[2] ?? '';
if ($mode === '' || $dir === '' || !is_file($dir . '/vendor/autoload.php')) {
	fwrite(STDERR, "usage: verify-extension-install.php <capture|boot> <consumer-dir>\n");
	exit(2);
}
require $dir . '/vendor/autoload.php';
chdir($dir);

$package = 'belisoful/prado-webextract';
$checks = 0;
$check = function (bool $ok, string $what) use (&$checks): void {
	$checks++;
	if (!$ok) {
		fwrite(STDERR, "FAIL: {$what}\n");
		exit(1);
	}
	fwrite(STDERR, "  ok: {$what}\n");
};

$application = new TApplication($dir . '/protected', false);
$configuration = new TApplicationConfiguration();
$configuration->captureComposerExtensions();

if ($mode === 'capture') {
	$messages = $configuration->getErrorMessages();
	// Asking Composer where the package landed, rather than matching the path as text: every
	// installed extension registers a message file, so a package with an extension among its
	// dependencies finds several, and a path repository installs a package as a symlink whose
	// real path is the checkout -- named whatever the checkout happens to be named, which need
	// not be the package name.
	$check(
		in_array(
			TComposerReflection::getPackagePath($package) . DIRECTORY_SEPARATOR . 'config/errorMessages.txt',
			$messages,
			true
		),
		'extra.prado.error-messages registered this package\'s message file'
	);

	$map = $configuration->getClassMap();
	$declared = json_decode(
		(string) file_get_contents($dir . '/vendor/' . $package . '/config/classes.json'),
		true,
		512,
		JSON_THROW_ON_ERROR
	);
	$check($map !== [], 'extra.prado.class-map registered a class map');
	foreach ($declared as $short => $fqn) {
		if (($map[$short] ?? null) !== $fqn) {
			$check(false, "class map entry {$short} => {$fqn}");
		}
	}
	$check(true, 'every class-map entry maps to its declared FQN');

	$check(
		$configuration->getComposerExtensionClass($package) === TWebExtractor::class,
		'extra.prado.bootstrap names TWebExtractor'
	);
	// The consumer requires only this extension; the framework has to arrive through it.
	$check(
		is_dir($dir . '/vendor/pradosoft/prado'),
		'pradosoft/prado was installed transitively, without the consumer requiring it'
	);
	$check(class_exists(TApplication::class), 'the transitively-installed framework autoloads');

	foreach ($messages as $file) {
		TException::addMessageFile($file);
	}
	Prado::registerClassMap($map);

	$exception = new TConfigurationException('webextract_table_nonexistent');
	$check(
		$exception->getMessage() !== 'webextract_table_nonexistent',
		'an extension error code resolves to its message text'
	);
	$check(
		Prado::usingClass('TWebExtractor') === TWebExtractor::class,
		'the Prado3 short name TWebExtractor resolves through the class map'
	);
} else {
	$configuration->loadFromFile($dir . '/protected/application.xml');
	$application->applyConfiguration($configuration);

	$module = $application->getModule($package);
	$check($module instanceof TWebExtractor, 'the bootstrap module booted under its package id');
	$check($module->getMaxAttempts() === 2, 'the module element applied MaxAttempts');

	// The queue, against the database the consumer configured.
	$extract = $module->queue('https://news.example/story?utm_source=feed');
	$check($extract->getId() > 0 && $extract->getIsPending(), 'an address is queued');
	$check($extract->getUrl() === 'https://news.example/story', 'and stored with its tracking parameters taken off');
	$check($module->countByStatus(TWebExtractor::STATUS_PENDING) === 1, 'the extracts table was created and holds it');

	// The real HTTP stack, as installed: symfony/http-client with the private network checks from
	// symfony/http-foundation. A loopback address is refused before any connection is made.
	$refused = $module->extract('http://127.0.0.1/');
	$check($refused->getStatus() === TWebExtractor::STATUS_FAILED, 'a loopback address is refused by the installed HTTP stack');
	$check(str_contains($refused->getError(), '127.0.0.1'), 'and the refusal says why');

	// Readability and Embed, as installed, reading a page without the network.
	$html = '<html lang="en"><head><meta property="og:title" content="Installed title"><meta property="og:site_name" content="Gazette"></head>'
		. '<body><article>' . str_repeat('<p>' . str_repeat('A sentence about the bridge closing. ', 8) . '</p>', 4) . '</article></body></html>';
	$read = new TWebExtract();
	$module->getReader()->read(new TWebFetchResult('https://news.example/a', 'https://news.example/a', 200, 'text/html', 'utf-8', $html), $read);
	$check($read->getTitle() === 'Installed title' && $read->getSiteName() === 'Gazette', 'Embed reads the page\'s own metadata');
	$check(str_contains($read->getText(), 'A sentence about the bridge closing.') && $read->getWordCount() > 100, 'Readability finds the article text');
}

fwrite(STDERR, "{$checks} checks passed ({$mode})\n");
