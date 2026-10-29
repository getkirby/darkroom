<?php

namespace Darkroom\Tests\Tools;

use Darkroom\Format;
use Darkroom\Tests\TestCase;
use Darkroom\Tools\Quality;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Quality::class)]
class QualityTest extends TestCase
{
	public static function forProvider(): array
	{
		return [
			'jpeg'       => [Format::Jpeg, 85, true, [90, false]],
			'between'    => [Format::Jpeg, 87, true, [93, false]],
			'jpeg whole' => [Format::Jpeg, 90, true, [93, true]],
			'kept half'  => [Format::Jpeg, 90, false, [98, false]],
			'avif'       => [Format::Avif, 85, true, [74, true]],
			'heic half'  => [Format::Heic, 70, true, [41, false]],
			'heic whole' => [Format::Heic, 85, true, [57, true]],
			'webp'       => [Format::Webp, 85, true, [92, false]],
			'jxl'        => [Format::Jxl, 85, true, [87, false]],
			'no scale'   => [Format::Png, 85, true, [85, false]],
			'lowest'     => [Format::Jpeg, 1, true, [10, false]],
			'highest'    => [Format::Webp, 100, true, [100, false]],
		];
	}

	#[DataProvider('forProvider')]
	public function testFor(
		Format $format,
		int $score,
		bool $whole,
		array $expected
	): void {
		$this->assertSame($expected, Quality::for($format, $score, $whole));
	}

	public static function forRisingProvider(): array
	{
		return [
			'avif' => [Format::Avif],
			'heic' => [Format::Heic],
			'jpeg' => [Format::Jpeg],
			'jxl'  => [Format::Jxl],
			'webp' => [Format::Webp],
		];
	}

	#[DataProvider('forRisingProvider')]
	public function testForRising(Format $format): void
	{
		foreach ([true, false] as $whole) {
			$previous = [0, $whole];

			for ($score = 0; $score <= 100; $score++) {
				$current = Quality::for($format, $score, $whole);

				// whole chroma has a scale of its own
				if ($current[1] === $previous[1]) {
					$this->assertGreaterThanOrEqual($previous[0], $current[0]);
				}

				$previous = $current;
			}
		}
	}

	public function testLibgd(): void
	{
		$this->assertSame(82, Quality::libgd(85));
		$this->assertSame(100, Quality::libgd(100));
	}
}
