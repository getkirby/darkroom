<?php

namespace Darkroom\Tests;

use Darkroom\Settings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

#[CoversClass(Settings::class)]
class SettingsTest extends TestCase
{
	public function testConstruct(): void
	{
		$settings = new Settings();

		$this->assertSame(200_000_000, $settings->limit);
		$this->assertSame([], $settings->other);
	}

	public function testConstructInvalid(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid limit: 0');

		new Settings(limit: 0);
	}

	public static function fromProvider(): array
	{
		return [
			'none'     => [[], 200_000_000, []],
			'limit'    => [['limit' => 1000], 1000, []],
			'no limit' => [['limit' => null], null, []],
			'other'    => [['threads' => 4, 'limit' => 5], 5, ['threads' => 4]],
		];
	}

	#[DataProvider('fromProvider')]
	public function testFrom(array $values, int|null $limit, array $other): void
	{
		$settings = Settings::from($values);

		$this->assertSame($limit, $settings->limit);
		$this->assertSame($other, $settings->other);
	}

	public static function fromInvalidProvider(): array
	{
		return [
			'string' => ['5', "Invalid limit: '5'"],
			'float'  => [1.5, 'Invalid limit: 1.5'],
			'true'   => [true, 'Invalid limit: true'],
			'object' => [new stdClass(), 'Invalid limit: stdClass'],
			'zero'   => [0, 'Invalid limit: 0'],
		];
	}

	#[DataProvider('fromInvalidProvider')]
	public function testFromInvalid(mixed $limit, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		Settings::from(['limit' => $limit]);
	}

	public function testFromSettings(): void
	{
		$settings = new Settings(limit: 5);

		$this->assertSame($settings, Settings::from($settings));
	}

	public function testToArray(): void
	{
		$settings = new Settings(1000, ['threads' => 4]);

		$this->assertSame(['threads' => 4, 'limit' => 1000], $settings->toArray());
	}
}
