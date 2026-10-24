<?php

namespace Darkroom\Tests;

use Darkroom\Orientation;
use Darkroom\Rect;
use Darkroom\Size;
use GdImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[CoversClass(Orientation::class)]
class OrientationTest extends TestCase
{
	protected static function colors(GdImage $image, Rect $rect): array
	{
		$colors = [];

		for ($x = $rect->x; $x < $rect->x + $rect->width; $x++) {
			for ($y = $rect->y; $y < $rect->y + $rect->height; $y++) {
				$colors[] = imagecolorat($image, $x, $y);
			}
		}

		sort($colors);
		return $colors;
	}

	protected static function image(Size $size): GdImage
	{
		$image = imagecreatetruecolor($size->width, $size->height);

		for ($x = 0; $x < $size->width; $x++) {
			for ($y = 0; $y < $size->height; $y++) {
				imagesetpixel($image, $x, $y, $x << 8 | $y);
			}
		}

		return $image;
	}

	protected static function orient(
		GdImage $image,
		Orientation $orientation
	): GdImage {
		$flip = function (int $mode) use ($image): GdImage {
			imageflip($image, $mode);
			return $image;
		};

		// `imagerotate()` turns counterclockwise
		return match ($orientation) {
			Orientation::None           => $image,
			Orientation::FlipHorizontal => $flip(IMG_FLIP_HORIZONTAL),
			Orientation::Rotate180      => imagerotate($image, 180, 0),
			Orientation::FlipVertical   => $flip(IMG_FLIP_VERTICAL),
			Orientation::Transpose      => imagerotate($flip(IMG_FLIP_VERTICAL), -90, 0),
			Orientation::Rotate90       => imagerotate($image, -90, 0),
			Orientation::Transverse     => imagerotate($flip(IMG_FLIP_HORIZONTAL), -90, 0),
			Orientation::Rotate270      => imagerotate($image, 90, 0),
		};
	}

	protected static function pixels(GdImage $image): array
	{
		$pixels = [];

		for ($y = 0; $y < imagesy($image); $y++) {
			for ($x = 0; $x < imagesx($image); $x++) {
				$pixels[] = imagecolorat($image, $x, $y);
			}
		}

		return $pixels;
	}

	public function testApply(): void
	{
		$size = new Size(3, 2);

		$this->assertEquals(new Size(3, 2), Orientation::None->apply($size));
		$this->assertEquals(new Size(3, 2), Orientation::FlipHorizontal->apply($size));
		$this->assertEquals(new Size(3, 2), Orientation::Rotate180->apply($size));
		$this->assertEquals(new Size(3, 2), Orientation::FlipVertical->apply($size));
		$this->assertEquals(new Size(2, 3), Orientation::Transpose->apply($size));
		$this->assertEquals(new Size(2, 3), Orientation::Rotate90->apply($size));
		$this->assertEquals(new Size(2, 3), Orientation::Transverse->apply($size));
		$this->assertEquals(new Size(2, 3), Orientation::Rotate270->apply($size));
	}

	public function testIsFlipped(): void
	{
		$this->assertFalse(Orientation::None->isFlipped());
		$this->assertTrue(Orientation::FlipHorizontal->isFlipped());
		$this->assertFalse(Orientation::Rotate180->isFlipped());
		$this->assertTrue(Orientation::FlipVertical->isFlipped());
		$this->assertTrue(Orientation::Transpose->isFlipped());
		$this->assertFalse(Orientation::Rotate90->isFlipped());
		$this->assertTrue(Orientation::Transverse->isFlipped());
		$this->assertFalse(Orientation::Rotate270->isFlipped());
	}

	public function testIsSwapped(): void
	{
		$this->assertFalse(Orientation::None->isSwapped());
		$this->assertFalse(Orientation::FlipHorizontal->isSwapped());
		$this->assertFalse(Orientation::Rotate180->isSwapped());
		$this->assertFalse(Orientation::FlipVertical->isSwapped());
		$this->assertTrue(Orientation::Transpose->isSwapped());
		$this->assertTrue(Orientation::Rotate90->isSwapped());
		$this->assertTrue(Orientation::Transverse->isSwapped());
		$this->assertTrue(Orientation::Rotate270->isSwapped());
	}

	public function testMap(): void
	{
		$rect = new Rect(1, 2, 4, 3);
		$size = new Size(10, 6);

		$this->assertEquals(new Rect(1, 2, 4, 3), Orientation::None->map($rect, $size));
		$this->assertEquals(new Rect(5, 2, 4, 3), Orientation::FlipHorizontal->map($rect, $size));
		$this->assertEquals(new Rect(5, 1, 4, 3), Orientation::Rotate180->map($rect, $size));
		$this->assertEquals(new Rect(1, 1, 4, 3), Orientation::FlipVertical->map($rect, $size));
		$this->assertEquals(new Rect(2, 1, 3, 4), Orientation::Transpose->map($rect, $size));
		$this->assertEquals(new Rect(2, 5, 3, 4), Orientation::Rotate90->map($rect, $size));
		$this->assertEquals(new Rect(1, 5, 3, 4), Orientation::Transverse->map($rect, $size));
		$this->assertEquals(new Rect(1, 1, 3, 4), Orientation::Rotate270->map($rect, $size));
	}

	public static function mapPixelsProvider(): array
	{
		$cases = [];

		foreach (Orientation::cases() as $orientation) {
			$cases[$orientation->name] = [$orientation];
		}

		return $cases;
	}

	#[DataProvider('mapPixelsProvider')]
	#[RequiresPhpExtension('gd')]
	public function testMapPixels(Orientation $orientation): void
	{
		$size   = new Size(3, 2);
		$raw    = $orientation->apply($size);
		$stored = self::image($raw);

		// a second copy, as `imageflip()` works in place
		$copy      = self::image($raw);
		$displayed = self::orient($copy, $orientation);

		$this->assertSame($size->width, imagesx($displayed));
		$this->assertSame($size->height, imagesy($displayed));

		for ($x = 0; $x < $size->width; $x++) {
			for ($y = 0; $y < $size->height; $y++) {
				for ($width = 1; $x + $width <= $size->width; $width++) {
					for ($height = 1; $y + $height <= $size->height; $height++) {
						$rect     = new Rect($x, $y, $width, $height);
						$mapped   = $orientation->map($rect, $size);
						$expected = self::colors($displayed, $rect);
						$actual   = self::colors($stored, $mapped);

						$this->assertSame($expected, $actual);
					}
				}
			}
		}
	}

	public function testTurns(): void
	{
		$this->assertSame(0, Orientation::None->turns());
		$this->assertSame(0, Orientation::FlipHorizontal->turns());
		$this->assertSame(2, Orientation::Rotate180->turns());
		$this->assertSame(2, Orientation::FlipVertical->turns());
		$this->assertSame(1, Orientation::Transpose->turns());
		$this->assertSame(1, Orientation::Rotate90->turns());
		$this->assertSame(3, Orientation::Transverse->turns());
		$this->assertSame(3, Orientation::Rotate270->turns());
	}

	#[RequiresPhpExtension('gd')]
	public function testTurnsPixels(): void
	{
		$size = new Size(3, 2);

		foreach (Orientation::cases() as $orientation) {
			$stored   = $orientation->apply($size);
			$image    = self::image($stored);
			$copy     = self::image($stored);
			$oriented = self::orient($image, $orientation);
			$turned   = imagerotate($copy, -90 * $orientation->turns(), 0);

			if ($orientation->isFlipped() === true) {
				imageflip($turned, IMG_FLIP_HORIZONTAL);
			}

			$expected = self::pixels($oriented);
			$actual   = self::pixels($turned);

			$this->assertSame($expected, $actual, $orientation->name);
		}
	}
}
