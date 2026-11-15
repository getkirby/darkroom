<?php

namespace Darkroom\Tests\Formats\Gif;

use Darkroom\Containers\Bytes;
use Darkroom\Formats\Gif\Reader;
use Darkroom\Formats\Header;
use Darkroom\Orientation;
use Darkroom\Rect;
use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Reader::class)]
class ReaderTest extends GifTestCase
{
	public static function canvasProvider(): array
	{
		return [
			'the screen'         => [[30, 20], [0, 0, 30, 20], null],
			'within the screen'  => [[200, 100], [80, 30, 41, 41], new Rect(-80, -30, 200, 100)],
			'at the origin'      => [[200, 100], [0, 0, 41, 41], new Rect(0, 0, 200, 100)],
			'beyond the screen'  => [[50, 40], [10, 10, 50, 40], new Rect(-10, -10, 50, 40)],
			'larger than it'     => [[20, 20], [0, 0, 50, 40], new Rect(0, 0, 20, 20)],
			'on an empty screen' => [[0, 0], [0, 0, 50, 40], new Rect(0, 0, 0, 0)],
		];
	}

	#[DataProvider('canvasProvider')]
	public function testCanvas(
		array $screen,
		array $image,
		Rect|null $canvas
	): void {
		$head    = self::screen($screen);
		$control = self::control();
		$blocks  = self::blocks("\x44\x01");
		$first   = self::frame($image, $blocks);
		$second  = self::image();
		$data    = $head . $control . $first . $control . $second . ';';

		$this->assertEquals($canvas, self::canvas($data));
	}

	public function testCanvasNone(): void
	{
		$still    = self::fixture('gif/still.gif');
		$animated = self::fixture('gif/animated.gif');

		$this->assertNull(self::canvas($still));
		$this->assertNull(self::canvas($animated));
		$this->assertNull(self::canvas(''));
		$this->assertNull(self::canvas('GIF89a'));

		$empty = self::gif();
		$image = self::image();
		$gif   = self::gif($image);
		$cut   = substr($gif, 0, 25);

		$this->assertNull(self::canvas($empty));
		$this->assertNull(self::canvas($cut));
	}

	public static function fixtureProvider(): array
	{
		return [
			'still'    => ['gif/still.gif', 30, 20, false],
			'animated' => ['gif/animated.gif', 30, 20, true],
		];
	}

	#[DataProvider('fixtureProvider')]
	public function testIsAnimated(
		string $file,
		int $width,
		int $height,
		bool $animated
	): void {
		$data = self::fixture($file);

		$this->assertSame($animated, self::isAnimated($data));
	}

	public function testIsAnimatedBlocks(): void
	{
		$image    = self::image();
		$control  = self::control();
		$netscape = self::netscape();
		$two      = self::gif($netscape, $control, $image, $control, $image);
		$one      = self::gif($netscape, $control, $image);

		$this->assertTrue(self::isAnimated($two));
		$this->assertFalse(self::isAnimated($one));

		$data = str_repeat('a', 1_000_000);
		$long = self::image($data);
		$data = self::gif($long, $image);

		$this->assertTrue(self::isAnimated($data));

		$screen = self::screen([30, 20], version: '87a');
		$data   = $screen . $image . $image;

		$this->assertTrue(self::isAnimated($data));
	}

	public function testIsAnimatedInvalid(): void
	{
		$image = self::image();

		$this->assertFalse(self::isAnimated(''));
		$this->assertFalse(self::isAnimated('GIF89a'));

		$stray = self::gif($image, "\0", $image);

		$this->assertFalse(self::isAnimated($stray));

		$data = str_repeat('a', 1000);
		$long = self::image($data);
		$data = self::gif($long, $image);
		$cut  = substr($data, 0, 500);

		$this->assertFalse(self::isAnimated($cut));
	}

	public function testIsAnimatedLimit(): void
	{
		$image = self::image();

		// at most `LIMIT` blocks and sub-blocks
		$blocks = str_repeat("\x01a", Bytes::LIMIT - 2) . "\0";
		$frame  = self::frame([0, 0, 30, 20], $blocks);
		$data   = self::gif($frame, $image);

		$this->assertTrue(self::isAnimated($data));

		$blocks = str_repeat("\x01a", Bytes::LIMIT - 1) . "\0";
		$frame  = self::frame([0, 0, 30, 20], $blocks);
		$data   = self::gif($frame, $image);

		$this->assertFalse(self::isAnimated($data));
	}

	#[DataProvider('fixtureProvider')]
	public function testRead(
		string $file,
		int $width,
		int $height,
		bool $animated
	): void {
		$data   = self::fixture($file);
		$size   = new Size($width, $height);
		$header = new Header($size, Orientation::None);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadGarbage(): void
	{
		$data = self::fixture('gif/animated.gif');

		$this->assertGarbage($data, keep: 6);
	}

	public static function readInvalidProvider(): array
	{
		return [
			'empty'          => [''],
			'no GIF'         => ['GIF90a' . pack('vv', 30, 20)],
			'signature only' => ['GIF89a'],
			'cut off size'   => ['GIF89a' . pack('vC', 30, 20)],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadTruncated(): void
	{
		$data = self::fixture('gif/animated.gif');

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);
			$header    = self::read($truncated);

			$this->assertSame($length >= 10 ? 30 : 0, $header->size->width);
		}

		$this->assertTruncated($data);
	}
}
