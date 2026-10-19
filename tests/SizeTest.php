<?php

namespace Darkroom\Tests;

use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Size::class)]
class SizeTest extends TestCase
{
	public function testConstruct(): void
	{
		$size = new Size(1200, 768);

		$this->assertSame(1200, $size->width);
		$this->assertSame(768, $size->height);
	}

	public static function fitProvider(): array
	{
		return [
			'wider than tall'  => [[1200, 768], [500, 500], [500, 320]],
			'taller than wide' => [[768, 1200], [500, 500], [320, 500]],
			'square'           => [[1200, 1200], [500, 500], [500, 500]],
			'smaller'          => [[300, 200], [500, 500], [300, 200]],
			'empty'            => [[0, 0], [500, 500], [0, 0]],

			'width 0'         => [[1200, 768], [0, null], [1200, 768]],
			'width'           => [[1200, 768], [500, null], [500, 320]],
			'width, smaller'  => [[1200, 768], [2000, null], [1200, 768]],
			'height 0'        => [[1200, 768], [null, 0], [1200, 768]],
			'height'          => [[1200, 768], [null, 500], [781, 500]],
			'height, smaller' => [[1200, 768], [null, 2000], [1200, 768]],

			'landscape, height limits' => [[1200, 768], [1000, 500], [781, 500]],
			'portrait, width limits'   => [[768, 1200], [500, 1000], [500, 781]],
			'bigger, other ratio'      => [[1200, 768], [2000, 800], [1200, 768]],

			'no box'     => [[1200, 768], [null, null], [1200, 768]],
			'exact box'  => [[1200, 768], [600, 384], [600, 384]],
			'one pixel'  => [[1, 1], [500, 500], [1, 1]],
			'empty side' => [[100, 0], [50, 50], [50, 0]],

			'never 0, landscape' => [[6000, 10], [100, null], [100, 1]],
			'never 0, portrait'  => [[10, 6000], [null, 100], [1, 100]],

			'both sides limit, rounded once' => [[1000, 333], [100, 20], [60, 20]],

			// 142 × 286.13
			'the width limits' => [[200, 403], [142, 287], [142, 286]],

			// 302 × 300 / 400 is exactly 226.5
			'rounded from the sizes' => [[400, 302], [300, null], [300, 227]],
		];
	}

	#[DataProvider('fitProvider')]
	public function testFit(
		array $size,
		array $box,
		array $expected
	): void {
		$size = new Size(...$size);

		$fit = $size->fit(...$box);

		$this->assertEquals(new Size(...$expected), $fit);
		$this->assertNotSame($size, $fit);
	}

	public function testIsLandscape(): void
	{
		$landscape = new Size(1200, 768);
		$portrait  = new Size(768, 1200);
		$square    = new Size(1200, 1200);
		$empty     = new Size(0, 0);

		$this->assertTrue($landscape->isLandscape());
		$this->assertFalse($portrait->isLandscape());
		$this->assertFalse($square->isLandscape());
		$this->assertFalse($empty->isLandscape());
	}

	public function testIsPortrait(): void
	{
		$landscape = new Size(1200, 768);
		$portrait  = new Size(768, 1200);
		$square    = new Size(1200, 1200);
		$empty     = new Size(0, 0);

		$this->assertFalse($landscape->isPortrait());
		$this->assertTrue($portrait->isPortrait());
		$this->assertFalse($square->isPortrait());
		$this->assertFalse($empty->isPortrait());
	}

	public function testIsSquare(): void
	{
		$landscape = new Size(1200, 768);
		$portrait  = new Size(768, 1200);
		$square    = new Size(1200, 1200);
		$empty     = new Size(0, 0);

		$this->assertFalse($landscape->isSquare());
		$this->assertFalse($portrait->isSquare());
		$this->assertTrue($square->isSquare());
		$this->assertTrue($empty->isSquare());
	}

	public static function pixelsProvider(): array
	{
		return [
			'landscape'      => [1200, 768, 921600],
			'empty side'     => [1200, 0, 0],
			'empty'          => [0, 0, 0],
			'one row'        => [PHP_INT_MAX, 1, PHP_INT_MAX],
			'largest square' => [3037000499, 3037000499, 9223372030926249001],
			'header'         => [4294967295, 4294967295, PHP_INT_MAX],
			'two rows'       => [PHP_INT_MAX, 2, PHP_INT_MAX],
		];
	}

	#[DataProvider('pixelsProvider')]
	public function testPixels(
		int $width,
		int $height,
		int $expected
	): void {
		$size = new Size($width, $height);

		$this->assertSame($expected, $size->pixels());
	}

	public static function ratioProvider(): array
	{
		return [
			'landscape' => [1200, 768, 1.5625],
			'portrait'  => [768, 1200, 0.64],
			'half'      => [200, 400, 0.5],
			'double'    => [400, 200, 2.0],
			'quarter'   => [100, 400, 0.25],
			'empty'     => [0, 0, 0.0],
			'no height' => [100, 0, 0.0],
			'no width'  => [0, 100, 0.0],
		];
	}

	#[DataProvider('ratioProvider')]
	public function testRatio(
		int $width,
		int $height,
		float $expected
	): void {
		$size = new Size($width, $height);

		$this->assertSame($expected, $size->ratio());
	}
}
