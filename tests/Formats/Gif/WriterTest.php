<?php

namespace Darkroom\Tests\Formats\Gif;

use Darkroom\Formats\Gif\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Writer::class)]
class WriterTest extends GifTestCase
{
	public function testStrip(): void
	{
		$loop     = self::netscape();
		$blocks   = self::blocks('made with a camera');
		$comment  = "!\xFE" . $blocks;
		$blocks   = self::blocks('<x:xmpmeta/>');
		$xmp      = "!\xFF\x0BXMP DataXMP" . $blocks;
		$blocks   = self::blocks('profile');
		$icc      = "!\xFF\x0BICCRGBG1012" . $blocks;
		$control  = self::control();
		$blocks   = self::blocks('hello');
		$text     = "!\x01\x0C" . str_repeat("\0", 12) . $blocks;
		$blocks   = self::blocks('other');
		$unknown  = "!\x99" . $blocks;
		$empty    = "!\xFE\0";
		$image    = self::image();
		$data     = self::gif($loop, $comment, $xmp, $icc, $empty, $control, $text, $unknown, $image);
		$expected = self::gif($loop, $icc, $control, $text, $image);

		$stripped = self::strip($data . 'trailing');

		$this->assertSame($expected, $stripped);
	}

	public function testStripEnd(): void
	{
		$image    = self::image();
		$expected = self::gif($image);
		$early    = substr($expected, 0, -1);

		$this->assertSame($expected, self::strip($early));
		$this->assertSame($expected, self::strip($early . 'other'));
	}

	public function testStripGarbage(): void
	{
		$data = self::fixture('gif/animated.gif');

		$this->assertStrips($data, 13);
	}

	public static function stripUnchangedProvider(): array
	{
		return [
			['gif/animated.gif'],
			['gif/optimized.gif'],
			['gif/partial.gif'],
			['gif/transparent.gif'],
		];
	}

	#[DataProvider('stripUnchangedProvider')]
	public function testStripUnchanged(string $name): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);

		$this->assertSame($data, $stripped);
	}
}
