<?php

namespace Darkroom\Tests\Tools;

use Darkroom\Tests\TestCase;
use Darkroom\Tools\Hdr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Hdr::class)]
class HdrTest extends TestCase
{
	public static function lightProvider(): array
	{
		// BT.2408's reference white of 203 cd/m² is 58 % PQ and 75 % HLG
		return [
			'pq black'      => [Hdr::PQ, 0.0, 0.0],
			'pq white'      => [Hdr::PQ, 0.5807, 0.203],
			'pq peak'       => [Hdr::PQ, 0.7518, 1.0],
			'pq above peak' => [Hdr::PQ, 0.9, 1.0],
			'hlg black'     => [Hdr::HLG, 0.0, 0.0],
			'hlg knee'      => [Hdr::HLG, 0.5, (1 / 12) ** 1.2],
			'hlg white'     => [Hdr::HLG, 0.75, 0.203],
			'hlg peak'      => [Hdr::HLG, 1.0, 1.0],
		];
	}

	#[DataProvider('lightProvider')]
	public function testLight(
		int $transfer,
		float $signal,
		float $expected
	): void {
		$this->assertEqualsWithDelta($expected, Hdr::light($transfer, $signal), 0.001);
	}

	public static function matrixProvider(): array
	{
		$bt2020 = [
			[1.6605, -0.5876, -0.0728],
			[-0.1246, 1.1329, -0.0083],
			[-0.0182, -0.1006, 1.1187],
		];

		$bt709 = [
			[1.0, 0.0, 0.0],
			[0.0, 1.0, 0.0],
			[0.0, 0.0, 1.0],
		];

		return [
			'bt.2020'     => [9, $bt2020],
			'bt.709'      => [1, $bt709],
			'unspecified' => [2, $bt2020],
		];
	}

	#[DataProvider('matrixProvider')]
	public function testMatrix(int $primaries, array $expected): void
	{
		$this->assertEqualsWithDelta($expected, Hdr::matrix($primaries), 0.0001);
	}

	public static function matrixWhiteProvider(): array
	{
		return [
			'bt.709'     => [1],
			'bt.2020'    => [9],
			'display p3' => [12],
		];
	}

	#[DataProvider('matrixWhiteProvider')]
	public function testMatrixWhite(int $primaries): void
	{
		$matrix = Hdr::matrix($primaries);
		$sums   = array_map(array_sum(...), $matrix);

		$this->assertEqualsWithDelta([1.0, 1.0, 1.0], $sums, 1e-9);
	}

	public static function srgbProvider(): array
	{
		// Core Image's 8-bit sRGB of grey PQ levels (`toneMapHDRtoSDR`, macOS 27)
		return [
			'0.1 cd/m²'  => [0.1, 2],
			'1 cd/m²'    => [1, 15],
			'5 cd/m²'    => [5, 43],
			'20 cd/m²'   => [20, 88],
			'50 cd/m²'   => [50, 135],
			'90 cd/m²'   => [90, 176],
			'120 cd/m²'  => [120, 198],
			'160 cd/m²'  => [160, 216],
			'203 cd/m²'  => [203, 228],
			'300 cd/m²'  => [300, 242],
			'500 cd/m²'  => [500, 251],
			'1000 cd/m²' => [1000, 254],
		];
	}

	#[DataProvider('srgbProvider')]
	public function testSrgb(float $light, int $expected): void
	{
		$srgb = Hdr::srgb($light / 1000);

		$this->assertEqualsWithDelta($expected, 255 * $srgb, 2);
	}

	public function testSrgbRange(): void
	{
		$this->assertSame(0.0, Hdr::srgb(0.0));
		$this->assertEqualsWithDelta(1.0, Hdr::srgb(1.0), 1e-9);
		$this->assertEqualsWithDelta(1.0, Hdr::srgb(2.0), 1e-9);
	}
}
