<?php

namespace Darkroom\Tests;

use Darkroom\Driver;
use Darkroom\Format;
use ReflectionClass;

trait Required
{
	protected static function required(string $driver, Format|null $format): bool
	{
		$list    = (string)getenv('DARKROOM_REQUIRE');
		$entries = preg_split('/\s+/', $list, flags: PREG_SPLIT_NO_EMPTY);

		foreach ($entries as $entry) {
			$parts = explode(':', $entry, 2);

			if ($parts[0] !== $driver) {
				continue;
			}

			if ($format === null) {
				return true;
			}

			$names   = explode(',', $parts[1] ?? '');
			$names   = array_filter($names);
			$formats = array_map(Format::from(...), $names);

			if (in_array($format, $formats, true) === true) {
				return true;
			}
		}

		return false;
	}

	protected function skip(
		string $message,
		Driver|string $driver,
		Format|null $format = null
	): never {
		if ($driver instanceof Driver) {
			$class  = new ReflectionClass($driver);
			$driver = strtolower($class->getShortName());
		}

		if (static::required($driver, $format) === true) {
			$this->fail($message . ', which DARKROOM_REQUIRE requires');
		}

		$this->markTestSkipped($message);
	}
}
