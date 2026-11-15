<?php

namespace Darkroom\Tests\Formats\Png;

use Darkroom\Containers\Bytes;
use Darkroom\Formats\Jpeg\Reader as Jpeg;
use Darkroom\Formats\Png\Writer;
use Darkroom\Meta\Exif;
use Darkroom\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Writer::class)]
class WriterTest extends PngTestCase
{
	public function testStrip(): void
	{
		$apple      = self::chunk('CgBI', "\x50\0\x20\x06");
		$ihdr       = self::ihdr(30, 20);
		$tiff       = self::tiff([[0x0112, 3, 6], [0x8825, 4, 26]]);
		$camera     = self::chunk('eXIf', $tiff);
		$six        = self::tiff(6);
		$rotated    = self::chunk('eXIf', $six);
		$text       = self::chunk('tEXt', "Comment\0" . 'hello');
		$compressed = self::chunk('zTXt', "Comment\0\0" . 'deflated');
		$xmp        = self::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . '<x:xmpmeta/>');
		$time       = self::chunk('tIME', pack('nC5', 2025, 2, 8, 12, 0, 0));
		$density    = self::chunk('pHYs', pack('NNC', 2835, 2835, 1));
		$c2pa       = self::chunk('caBX', 'manifest');
		$icc        = self::chunk('iCCP', "icc\0\0" . 'profile');
		$alpha      = self::chunk('tRNS', "\0\0");
		$control    = self::chunk('acTL', pack('NN', 2, 0));
		$frame      = self::chunk('fcTL', str_repeat("\0", 26));
		$pixels     = self::chunk('IDAT', 'pixels');
		$next       = self::chunk('fdAT', pack('N', 1) . 'pixels');
		$end        = self::chunk('IEND');
		$expected   = self::png(
			$apple,
			$ihdr,
			$rotated,
			$density,
			$icc,
			$alpha,
			$control,
			$frame,
			$pixels,
			$next,
			$end
		);
		$data       = self::png(
			$apple,
			$ihdr,
			$text,
			$camera,
			$time,
			$density,
			$icc,
			$c2pa,
			$alpha,
			$control,
			$frame,
			$pixels,
			$xmp,
			$next,
			$compressed,
			$end
		);

		$stripped = self::strip($data . 'cropped pixels');

		$this->assertSame($expected, $stripped);
	}

	public function testStripChunks(): void
	{
		$ihdr   = self::ihdr(30, 20);
		$pixels = self::chunk('IDAT', 'pixels');
		$chunks = str_repeat($pixels, Bytes::STEPS + 1);
		$end    = self::chunk('IEND');
		$data   = self::png($ihdr, $chunks, $end);

		$stripped = self::strip($data);

		$this->assertSame($data, $stripped);
	}

	public static function stripFixtureProvider(): array
	{
		return [
			'animated' => ['png/animated.png', Orientation::None],
			'exif'     => ['png/exif-6.png', Orientation::Rotate90],
			'icc'      => ['png/icc.png', Orientation::None],
		];
	}

	#[DataProvider('stripFixtureProvider')]
	public function testStripFixture(string $name, Orientation $orientation): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);
		$again    = self::strip($stripped);
		$exif     = self::exif($stripped);
		$handle   = self::handle($exif);

		// from the length of the first `IDAT` chunk on
		$image = substr($stripped, strpos($stripped, 'IDAT') - 4);

		$this->assertEquals(self::read($data), self::read($stripped));
		$this->assertSame(self::profile($data), self::profile($stripped));
		$this->assertSame($orientation, Exif::orientation($handle));
		$this->assertSame($orientation === Orientation::None, $exif === '');
		$this->assertStringEndsWith($image, $data);
		$this->assertSame($stripped, $again);
	}

	public function testStripGarbage(): void
	{
		$data = self::fixture('png/exif-6.png');

		$this->assertStrips($data, 8);
	}

	public function testStripTags(): void
	{
		$camera   = self::fixture('exif.jpg');
		$handle   = self::handle($camera);
		$jpeg     = new Jpeg($handle);
		$tiff     = $jpeg->exif();
		$tags     = [0x010F, 0x0110];
		$kept     = Exif::filter($tiff, $tags, Orientation::Rotate270);
		$ihdr     = self::ihdr(30, 20);
		$exif     = self::chunk('eXIf', $tiff);
		$filtered = self::chunk('eXIf', $kept);
		$pixels   = self::chunk('IDAT', 'pixels');
		$end      = self::chunk('IEND');
		$data     = self::png($ihdr, $exif, $pixels, $end);
		$expected = self::png($ihdr, $filtered, $pixels, $end);

		$stripped = self::strip($data, $tags);

		$this->assertSame($expected, $stripped);
	}

	public static function stripUnchangedProvider(): array
	{
		return [
			['png/16-bit.png'],
			['png/alpha.png'],
			['png/animated.png'],
			['png/icc.png'],
			['png/plain.png'],
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
