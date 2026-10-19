<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Orientation;
use Darkroom\Readers\Exif;
use Darkroom\Readers\Header;
use Darkroom\Readers\Png;
use Darkroom\Readers\Reader;
use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Png::class)]
class PngTest extends ReaderTestCase
{
	protected const string READER = Png::class;

	public function testExif(): void
	{
		$data   = self::fixture('png/exif-6.png');
		$exif   = self::exif($data);
		$handle = self::handle($exif);
		$header = Exif::read($handle);

		$this->assertStringStartsWith("MM\0*", $exif);
		$this->assertSame(Orientation::Rotate90, $header->orientation);

		$ihdr   = self::ihdr(30, 20);
		$three  = self::tiff(3);
		$six    = self::tiff(6);
		$marked = self::chunk('eXIf', "Exif\0\0" . $three);
		$plain  = self::chunk('eXIf', $six);
		$data   = self::png($ihdr, $marked, $plain);

		$this->assertSame($six, self::exif($data));
	}

	public function testExifNone(): void
	{
		$plain = self::fixture('png/plain.png');

		$this->assertSame('', self::exif($plain));
		$this->assertSame('', self::exif(''));

		$ihdr  = self::ihdr(30, 20);
		$idat  = self::chunk('IDAT');
		$tiff  = self::tiff(6);
		$exif  = self::chunk('eXIf', $tiff);
		$after = self::png($ihdr, $idat, $exif);

		$this->assertSame('', self::exif($after));
	}

	public function testIcc(): void
	{
		$data = self::fixture('png/icc.png');
		$p3   = self::fixture('p3.icc');

		$this->assertSame($p3, self::profile($data));

		$ihdr   = self::ihdr(30, 20);
		$icc    = self::icc(5000);
		$first  = self::chunk('iCCP', "Display P3\0\0" . gzcompress($icc));
		$second = self::chunk('iCCP', "icc\0\0" . gzcompress($p3));
		$data   = self::png($ihdr, $first, $second);

		$this->assertSame($icc, self::profile($data));
	}

	public function testIccNone(): void
	{
		$plain = self::fixture('png/plain.png');

		$this->assertSame('', self::profile($plain));
		$this->assertSame('', self::profile(''));

		$ihdr    = self::ihdr(30, 20);
		$idat    = self::chunk('IDAT');
		$icc     = self::icc(5000);
		$large   = self::icc(2_000_000);
		$cut     = substr($icc, 0, 4999);
		$iccp    = self::chunk('iCCP', "icc\0\0" . gzcompress($icc));
		$unnamed = self::chunk('iCCP', gzcompress($icc));
		$raw     = self::chunk('iCCP', "icc\0\0" . gzdeflate($icc));
		$huge    = self::chunk('iCCP', "icc\0\0" . gzcompress($large));
		$partial = self::chunk('iCCP', "icc\0\0" . gzcompress($cut));
		$cases   = [
			[$idat, $iccp],
			[$unnamed],
			[$raw],
			[$huge],
			[$partial],
		];

		foreach ($cases as $chunks) {
			$data = self::png($ihdr, ...$chunks);

			$this->assertSame('', self::profile($data));
		}
	}

	public function testIccTruncated(): void
	{
		$data = self::fixture('png/icc.png');
		$p3   = self::fixture('p3.icc');

		$chunk = strpos($data, 'iCCP');
		$end   = $chunk + 4 + unpack('N', $data, $chunk - 4)[1];

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);

			$this->assertSame($length >= $end ? $p3 : '', self::profile($truncated));
		}

		$this->assertTruncated($data);
	}

	public static function readProvider(): array
	{
		return [
			'plain' => ['png/plain.png', 30, 20, Orientation::None],
			'exif'  => ['png/exif-6.png', 30, 20, Orientation::Rotate90],
			'apng'  => ['png/animated.png', 30, 20, Orientation::None],
		];
	}

	#[DataProvider('readProvider')]
	public function testRead(
		string $file,
		int $width,
		int $height,
		Orientation $orientation
	): void {
		$data   = self::fixture($file);
		$size   = new Size($width, $height);
		$header = new Header($size, $orientation);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadExif(): void
	{
		$ihdr   = self::ihdr(30, 20);
		$idat   = self::chunk('IDAT');
		$tiff   = self::tiff(6);
		$other  = self::tiff(8);
		$six    = self::chunk('eXIf', $tiff);
		$eight  = self::chunk('eXIf', $other);
		$marked = self::chunk('eXIf', "Exif\0\0" . $other);

		$only  = self::png($ihdr, $marked);
		$first = self::png($ihdr, $marked, $six);

		$this->assertSame(Orientation::None, self::read($only)->orientation);
		$this->assertSame(Orientation::Rotate90, self::read($first)->orientation);

		$after = self::png($ihdr, $idat, $six);

		$this->assertSame(Orientation::None, self::read($after)->orientation);

		$both = self::png($ihdr, $six, $eight);

		$this->assertSame(Orientation::Rotate90, self::read($both)->orientation);

		$broken = self::chunk('eXIf', "MM\0*\xFF\xFF\xFF\xFF");
		$data   = self::png($ihdr, $broken);
		$size   = new Size(30, 20);

		$this->assertEquals(new Header($size), self::read($data));
	}

	public function testReadGarbage(): void
	{
		$exif     = self::fixture('png/exif-6.png');
		$animated = self::fixture('png/animated.png');

		$this->assertGarbage($exif, keep: 8);
		$this->assertGarbage($animated, keep: 8);
	}

	public static function readInvalidProvider(): array
	{
		$ihdr  = self::ihdr(30, 20);
		$cut   = substr($ihdr, 0, 15);
		$short = self::chunk('IHDR', pack('N', 30));
		$idat  = self::chunk('IDAT');

		return [
			'empty'           => [''],
			'no PNG'          => ['GIF89a' . $ihdr],
			'signature only'  => [self::png()],
			'cut off header'  => [self::png($cut)],
			'short header'    => [self::png($short)],
			'after the image' => [self::png($idat, $ihdr)],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadLimit(): void
	{
		$ihdr = self::ihdr(30, 20);
		$tiff = self::tiff(6);

		$text   = self::chunk('tEXt', "a\0b");
		$empty  = self::chunk('tEXt');
		$texts  = str_repeat($text, Reader::STEPS - 1);
		$last   = self::png($texts, $ihdr);
		$beyond = self::png($texts, $empty, $ihdr);

		$this->assertSame(30, self::read($last)->size->width);
		$this->assertSame(0, self::read($beyond)->size->width);

		$long   = self::chunk('tEXt', str_repeat("\0", 10_000_000));
		$data   = self::png($long, $ihdr);
		$handle = self::handle($data);

		$this->assertSame(30, Png::read($handle)->size->width);

		$exif   = self::sized(0xFFFFFFFF, 'eXIf', $tiff);
		$data   = self::png($ihdr, $exif);
		$size   = new Size(30, 20);
		$header = new Header($size, Orientation::Rotate90);

		$this->assertReads($data);
		$this->assertSame($tiff, self::exif($data));
		$this->assertEquals($header, self::read($data));
	}

	public function testReadTruncated(): void
	{
		$data = self::fixture('png/exif-6.png');

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);
			$header    = self::read($truncated);

			$this->assertSame($length >= 24 ? 30 : 0, $header->size->width);
		}

		$animated = self::fixture('png/animated.png');

		$this->assertTruncated($data);
		$this->assertTruncated($animated);
	}
}
