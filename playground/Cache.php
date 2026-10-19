<?php

namespace Darkroom\Playground;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Cache
{
	public readonly string $dir;
	public readonly string $fingerprint;

	/**
	 * @param iterable<string> $files
	 */
	public function __construct(
		private readonly string $root,
		iterable $files
	) {
		$hashes = [];

		foreach ($files as $file) {
			$hashes[(string)$file] = md5_file($file);
		}

		// directories list their files in no fixed order
		ksort($hashes);

		$this->fingerprint = substr(md5(implode($hashes)), 0, 10);
		$this->dir         = $root . '/' . $this->fingerprint;
	}

	public function clear(): void
	{
		static::remove($this->dir);
	}

	public function prune(): void
	{
		foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			if ($dir !== $this->dir) {
				static::remove($dir);
			}
		}
	}

	/**
	 * The data stored as `$key`, else made by `$make` and stored;
	 * one request makes it while the others wait for it
	 */
	public function remember(string $key, Closure $make): array
	{
		$file = $this->dir . '/' . $key . '.json';

		if (is_file($file) === false) {
			@mkdir(dirname($file), 0777, true);

			$lock = fopen($file . '.lock', 'c');
			flock($lock, LOCK_EX);

			// made by another request while this one waited
			if (is_file($file) === false) {
				$temp = $file . '.' . bin2hex(random_bytes(4));

				// renamed into place, so a request
				// that doesn't wait never reads half a file
				file_put_contents($temp, json_encode($make()));
				rename($temp, $file);
			}

			fclose($lock);
		}

		return json_decode((string)file_get_contents($file), true);
	}

	private static function remove(string $dir): void
	{
		if (is_dir($dir) === false) {
			return;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $file) {
			$file->isDir() === true ? rmdir($file) : unlink($file);
		}

		rmdir($dir);
	}
}
