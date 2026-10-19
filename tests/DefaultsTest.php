<?php

namespace Darkroom\Tests;

use Darkroom\Defaults;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Defaults::class)]
class DefaultsTest extends TestCase
{
	public function testConstruct(): void
	{
		$defaults = new Defaults();

		$this->assertSame(90, $defaults->quality);
		$this->assertFalse($defaults->interlace);
		$this->assertTrue($defaults->icc);
	}

	public static function fromProvider(): array
	{
		return [
			'none'       => [[], 90, false, true],
			'clamped'    => [['quality' => 150], 100, false, true],
			'other keys' => [['threads' => 4, 'format' => 'svg'], 90, false, true],
			'set' => [
				['quality' => 20, 'interlace' => true, 'icc' => false],
				20,
				true,
				false
			],
			'strings' => [
				['quality' => '80', 'interlace' => 'yes', 'icc' => 'off'],
				80,
				true,
				false
			],
			'false' => [
				['quality' => false, 'interlace' => false, 'icc' => false],
				90,
				false,
				false
			],
			'not set' => [
				['quality' => 0, 'interlace' => null, 'icc' => null],
				90,
				false,
				true
			],
			'empty strings' => [
				['quality' => '', 'interlace' => '', 'icc' => ''],
				90,
				false,
				true
			],
		];
	}

	#[DataProvider('fromProvider')]
	public function testFrom(
		array $settings,
		int $quality,
		bool $interlace,
		bool $icc
	): void {
		$defaults = Defaults::from($settings);

		$this->assertSame($quality, $defaults->quality);
		$this->assertSame($interlace, $defaults->interlace);
		$this->assertSame($icc, $defaults->icc);
	}

	public static function fromInvalidProvider(): array
	{
		return [
			'quality'   => [['quality' => 'high'], 'Invalid quality: \'high\''],
			'negative'  => [['quality' => -1], 'Invalid quality: -1'],
			'interlace' => [['interlace' => 'flase'], 'Invalid interlace: \'flase\''],
			'icc'       => [['icc' => 2], 'Invalid icc: 2'],
		];
	}

	#[DataProvider('fromInvalidProvider')]
	public function testFromInvalid(array $settings, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		Defaults::from($settings);
	}
}
