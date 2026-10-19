<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Readers\Header;
use Darkroom\Readers\Reader;
use Darkroom\Rect;
use Darkroom\Tests\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

abstract class ReaderTestCase extends TestCase
{
	protected const string FIXTURES = __DIR__ . '/../fixtures';
	protected const string READER = Reader::class;

	protected function assertGarbage(string $data, int $keep): void
	{
		$engine = new Mt19937(42);
		$random = new Randomizer($engine);

		for ($run = 0; $run < 300; $run++) {
			$changed = $data;

			for ($change = 0; $change < 4; $change++) {
				$offset           = $random->getInt($keep, strlen($data) - 1);
				$changed[$offset] = $random->getBytes(1);
			}

			$this->assertReads($changed);

			$length  = $random->getInt(1, 1000);
			$garbage = substr($data, 0, $keep) . $random->getBytes($length);

			$this->assertReads($garbage);
		}
	}

	protected function assertReads(string $data): void
	{
		memory_reset_peak_usage();
		$memory = memory_get_usage();

		$header   = self::read($data);
		$cicp     = self::cicp($data);
		$exif     = self::exif($data);
		$profile  = self::profile($data);
		$animated = self::isAnimated($data);
		$peak     = memory_get_peak_usage();

		// a copy of the data, and at most 1 MB read at once
		$this->assertLessThan($memory + strlen($data) + 2_000_000, $peak);
		$this->assertGreaterThanOrEqual(0, $header->size->width);
		$this->assertGreaterThanOrEqual(0, $header->size->height);
		$this->assertCount(4, $cicp);
		$this->assertLessThanOrEqual(1_048_576, strlen($exif));
		$this->assertLessThanOrEqual(1_048_576, strlen($profile));
		$this->assertIsBool($animated);

		$this->assertSame($profile, Reader::profile($profile));
	}

	protected function assertTruncated(string $data): void
	{
		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);

			$this->assertReads($truncated);
		}
	}

	protected static function canvas(string $data): Rect|null
	{
		$handle = static::handle($data);

		return (static::READER)::canvas($handle);
	}

	protected static function cicp(string $data): array
	{
		$handle = static::handle($data);

		return (static::READER)::cicp($handle);
	}

	protected static function exif(string $data): string
	{
		$handle = static::handle($data);

		return (static::READER)::exif($handle);
	}

	protected static function fixture(string $name): string
	{
		return file_get_contents(static::FIXTURES . '/' . $name);
	}

	protected static function handle(string $data)
	{
		$handle = fopen('php://memory', 'r+b');
		fwrite($handle, $data);
		rewind($handle);

		return $handle;
	}

	protected static function icc(int $length): string
	{
		$bytes = implode('', array_map(chr(...), range(0, 255)));
		$data  = str_repeat($bytes, intdiv($length, 256) + 1);
		$data  = substr_replace($data, pack('N', $length), 0, 4);
		$data  = substr_replace($data, 'acsp', 36, 4);

		return substr($data, 0, $length);
	}

	protected static function isAnimated(string $data): bool
	{
		$handle = static::handle($data);

		return (static::READER)::isAnimated($handle);
	}

	protected static function profile(string $data): string
	{
		$handle = static::handle($data);

		return (static::READER)::icc($handle);
	}

	protected static function read(string $data): Header
	{
		$handle = static::handle($data);

		return (static::READER)::read($handle);
	}
}
