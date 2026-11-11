<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Orientation;
use Darkroom\Readers\Exif;
use Darkroom\Readers\Header;
use Darkroom\Readers\Jxl;
use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Jxl::class)]
class JxlTest extends ReaderTestCase
{
	protected const string READER = Jxl::class;
	protected const array SIZE = [[0, 1], [0, 2], [89, 9], [1, 3]];

	protected static function container(string ...$boxes): string
	{
		$signature = self::box('JXL ', "\r\n\x87\n");
		$ftyp      = self::ftyp('jxl ', 'jxl ');

		return $signature . $ftyp . implode('', $boxes);
	}

	protected static function jxl(array ...$fields): string
	{
		$bits = '';

		foreach ($fields as [$value, $count]) {
			for ($bit = 0; $bit < $count; $bit++) {
				$bits .= ($value >> $bit) & 1;
			}
		}

		$bytes = '';

		foreach (str_split(str_pad($bits, 256, '0'), 8) as $byte) {
			$bytes .= chr(bindec(strrev($byte)));
		}

		return "\xFF\x0A" . $bytes;
	}

	protected static function jxlp(int $index, string $part): string
	{
		return self::box('jxlp', pack('N', $index) . $part);
	}

	public function testExif(): void
	{
		$data   = self::fixture('jxl/exif-6.jxl');
		$exif   = self::exif($data);
		$handle      = self::handle($exif);
		$orientation = Exif::orientation($handle);

		$this->assertStringStartsWith("MM\0*", $exif);
		$this->assertSame(Orientation::Rotate90, $orientation);

		$tiff       = self::tiff(3);
		$codestream = self::jxl(...self::SIZE);
		$jxlc       = self::box('jxlc', $codestream);
		$marked     = self::box('Exif', pack('N', 6) . "Exif\0\0" . $tiff);
		$plain      = self::box('Exif', pack('N', 0) . $tiff);
		$before     = self::container($marked, $jxlc);
		$after      = self::container($jxlc, $plain);

		$this->assertSame($tiff, self::exif($before));
		$this->assertSame($tiff, self::exif($after));
	}

	public function testExifNone(): void
	{
		$brotli = self::fixture('jxl/brob.jxl');

		$this->assertSame('', self::exif($brotli));

		$container = self::fixture('jxl/container.jxl');
		$plain     = self::fixture('jxl/plain.jxl');

		$this->assertSame('', self::exif($container));
		$this->assertSame('', self::exif($plain));
		$this->assertSame('', self::exif(''));
	}

	public static function fixtureProvider(): array
	{
		return [
			'plain'     => ['jxl/plain.jxl', 30, 20, Orientation::None, false],
			'container' => ['jxl/container.jxl', 30, 20, Orientation::None, false],
			'exif'      => ['jxl/exif-6.jxl', 30, 20, Orientation::Rotate90, false],
			'brob'      => ['jxl/brob.jxl', 30, 20, Orientation::Rotate90, false],
			'animated'  => ['jxl/animated.jxl', 30, 20, Orientation::None, true],
			'small'     => ['jxl/small.jxl', 32, 24, Orientation::None, false],
			'odd'       => ['jxl/odd.jxl', 31, 21, Orientation::None, false],
			'wide'      => ['jxl/wide.jxl', 300, 7, Orientation::None, false],
		];
	}

	#[DataProvider('fixtureProvider')]
	public function testIsAnimated(
		string $file,
		int $width,
		int $height,
		Orientation $orientation,
		bool $animated
	): void {
		$data = self::fixture($file);

		$this->assertSame($animated, self::isAnimated($data));
	}

	public static function isAnimatedProvider(): array
	{
		return [
			'animated'        => [true, [0, 1], [1, 1], [0, 3], [0, 1], [0, 1], [1, 1]],
			'still'           => [false, [0, 1], [1, 1], [0, 3], [0, 1], [0, 1], [0, 1]],
			'all default'     => [false, [1, 1], [1, 1], [0, 3], [0, 1], [0, 1], [1, 1]],
			'no extra fields' => [false, [0, 1], [0, 1], [0, 3], [0, 1], [0, 1], [1, 1]],
			'after an intrinsic size' => [
				true,
				[0, 1], [1, 1], [0, 3],
				[1, 1], [0, 1], [3, 2], [99999, 30], [0, 3], [2, 2], [199999, 18],
				[0, 1], [1, 1]
			],
			'after a preview' => [
				true,
				[0, 1], [1, 1], [0, 3], [0, 1],
				[1, 1], [0, 1], [3, 2], [4095, 12], [0, 3], [2, 2], [1023, 10],
				[1, 1]
			],
			'after a preview of 8s' => [
				true,
				[0, 1], [1, 1], [0, 3], [0, 1],
				[1, 1], [1, 1], [0, 2], [3, 3],
				[1, 1]
			],
			'after a preview of 8s, 33+' => [
				true,
				[0, 1], [1, 1], [0, 3], [0, 1],
				[1, 1], [1, 1], [3, 2], [511, 9], [0, 3], [2, 2], [31, 5],
				[1, 1]
			],
		];
	}

	#[DataProvider('isAnimatedProvider')]
	public function testIsAnimatedMetadata(bool $animated, array ...$fields): void
	{
		$data = self::jxl(...self::SIZE, ...$fields);

		$this->assertSame($animated, self::isAnimated($data));
	}

	#[DataProvider('fixtureProvider')]
	public function testRead(
		string $file,
		int $width,
		int $height,
		Orientation $orientation,
		bool $animated
	): void {
		$data   = self::fixture($file);
		$size   = new Size($width, $height);
		$header = new Header($size, $orientation);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadContainer(): void
	{
		$metadata   = [[0, 1], [1, 1], [5, 3]];
		$codestream = self::jxl(...self::SIZE, ...$metadata);
		$size       = new Size(90, 90);
		$header     = new Header($size, Orientation::Rotate90);

		$jxlc = self::box('jxlc', $codestream);
		$data = self::container($jxlc);

		$this->assertEquals($header, self::read($data));

		$tiff   = self::tiff(3);
		$exif   = self::box('Exif', pack('N', 0) . $tiff);
		$first  = self::jxlp(0, substr($codestream, 0, 3));
		$second = self::jxlp(1, substr($codestream, 3, 1));
		$last   = self::jxlp(0x80000002, substr($codestream, 4));
		$data   = self::container($first, $exif, $second, $last);

		$this->assertEquals($header, self::read($data));

		$jxlc   = self::box('jxlc', $codestream . str_repeat("\0", 10_000_000));
		$data   = self::container($jxlc);
		$handle = self::handle($data);

		$this->assertEquals($size, Jxl::read($handle)->size);
	}

	public function testReadGarbage(): void
	{
		$animated = self::fixture('jxl/animated.jxl');
		$exif     = self::fixture('jxl/exif-6.jxl');

		$this->assertGarbage($animated, keep: 2);
		$this->assertGarbage($exif, keep: 12);
	}

	public static function readInvalidProvider(): array
	{
		$codestream = self::jxl(...self::SIZE);
		$cut        = substr($codestream, 0, 3);
		$tiff       = self::tiff(6);
		$exif       = self::box('Exif', pack('N', 0) . $tiff);
		$other      = self::box('jxll', $codestream);

		return [
			'empty'          => [''],
			'no JPEG XL'     => ['GIF89a' . $codestream],
			'signature only' => ["\xFF\x0A"],
			'cut off size'   => [$cut],
			'no codestream'  => [self::container($exif)],
			'other box'      => [self::container($other)],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadOrientation(): void
	{
		foreach (Orientation::cases() as $orientation) {
			$metadata = [[0, 1], [1, 1], [$orientation->value - 1, 3]];
			$data     = self::jxl(...self::SIZE, ...$metadata);

			$this->assertSame($orientation, self::read($data)->orientation);
		}

		$default = self::jxl(...self::SIZE, ...[[1, 1], [1, 1], [5, 3]]);
		$none    = self::jxl(...self::SIZE, ...[[0, 1], [0, 1], [5, 3]]);

		$this->assertSame(Orientation::None, self::read($default)->orientation);
		$this->assertSame(Orientation::None, self::read($none)->orientation);
	}

	public static function readRatioProvider(): array
	{
		return [
			'1:1'   => [1, 90],
			'12:10' => [2, 108],
			'4:3'   => [3, 120],
			'3:2'   => [4, 135],
			'16:9'  => [5, 160],
			'5:4'   => [6, 112],
			'2:1'   => [7, 180],
		];
	}

	#[DataProvider('readRatioProvider')]
	public function testReadRatio(int $ratio, int $width): void
	{
		$data = self::jxl([0, 1], [0, 2], [89, 9], [$ratio, 3]);

		$this->assertEquals(new Size($width, 90), self::read($data)->size);
	}

	public function testReadSizes(): void
	{
		$large = self::jxl([0, 1], [3, 2], [99999, 30], [0, 3], [2, 2], [199999, 18]);

		$wide = self::jxl([0, 1], [1, 2], [0, 13], [0, 3], [1, 2], [8191, 13]);

		$small = self::jxl([1, 1], [0, 5], [0, 3], [31, 5]);

		$this->assertEquals(new Size(200000, 100000), self::read($large)->size);
		$this->assertEquals(new Size(8192, 1), self::read($wide)->size);
		$this->assertEquals(new Size(256, 8), self::read($small)->size);
	}

	public function testReadTruncated(): void
	{
		$fields   = [[1, 1], [2, 5], [0, 3], [3, 5]];
		$metadata = [[0, 1], [1, 1], [5, 3], [0, 1], [0, 1], [1, 1]];
		$data     = self::jxl(...$fields, ...$metadata);
		$size     = new Size(32, 24);
		$header   = new Header($size, Orientation::Rotate90);

		$this->assertEquals($header, self::read($data));
		$this->assertTrue(self::isAnimated($data));

		$short = substr($data, 0, 3);
		$cut   = substr($data, 0, 4);

		$this->assertEquals(new Header(), self::read($short));
		$this->assertEquals(new Header($size), self::read($cut));
		$this->assertFalse(self::isAnimated($cut));

		$animated = self::fixture('jxl/animated.jxl');
		$exif     = self::fixture('jxl/exif-6.jxl');

		$this->assertTruncated($animated);
		$this->assertTruncated($exif);
	}
}
