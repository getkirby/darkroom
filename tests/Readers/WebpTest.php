<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Orientation;
use Darkroom\Readers\Header;
use Darkroom\Readers\Reader;
use Darkroom\Readers\Webp;
use Darkroom\Size;
use Darkroom\Tools\Exif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Webp::class)]
class WebpTest extends ReaderTestCase
{
	protected const string READER = Webp::class;

	protected static function extended(
		int $flags,
		int $width = 30,
		int $height = 20
	): string {
		$width  = substr(pack('V', $width - 1), 0, 3);
		$height = substr(pack('V', $height - 1), 0, 3);

		return self::riff('VP8X', chr($flags) . "\0\0\0" . $width . $height);
	}

	protected static function lossless(int $bits): string
	{
		return self::riff('VP8L', "\x2F" . pack('V', $bits));
	}

	protected static function lossy(int $width = 30, int $height = 20): string
	{
		$frame = "\x50\x02\0\x9D\x01\x2A" . pack('vv', $width, $height) . str_repeat("\0", 8);

		return self::riff('VP8 ', $frame);
	}

	public function testExif(): void
	{
		$data   = self::fixture('webp/exif-6.webp');
		$exif   = self::exif($data);
		$handle      = self::handle($exif);
		$orientation = Exif::orientation($handle);

		$this->assertStringStartsWith("MM\0*", $exif);
		$this->assertSame(Orientation::Rotate90, $orientation);

		$vp8x = self::extended(0x08);
		$vp8  = self::lossy();
		$tiff = self::tiff(3);
		$exif = self::riff('EXIF', "Exif\0\0" . $tiff);
		$data = self::webp($vp8x, $vp8, $exif);

		$this->assertSame($tiff, self::exif($data));
	}

	public function testExifNone(): void
	{
		$lossy = self::fixture('webp/lossy.webp');
		$alpha = self::fixture('webp/alpha.webp');

		$this->assertSame('', self::exif($lossy));
		$this->assertSame('', self::exif($alpha));
		$this->assertSame('', self::exif(''));

		$vp8x   = self::extended(0);
		$vp8    = self::lossy();
		$tiff   = self::tiff(6);
		$exif   = self::riff('EXIF', $tiff);
		$flag   = self::webp($vp8x, $vp8, $exif);
		$simple = self::webp($vp8, $exif);

		$this->assertSame('', self::exif($flag));
		$this->assertSame('', self::exif($simple));
	}

	public function testIcc(): void
	{
		$data = self::fixture('webp/icc.webp');
		$p3   = self::fixture('p3.icc');

		$this->assertSame($p3, self::profile($data));

		$vp8x = self::extended(0x20);
		$vp8  = self::lossy();
		$icc  = self::icc(301);
		$iccp = self::riff('ICCP', $icc);
		$data = self::webp($vp8x, $iccp, $vp8);

		$this->assertSame($icc, self::profile($data));
	}

	public function testIccNone(): void
	{
		$lossy = self::fixture('webp/lossy.webp');
		$alpha = self::fixture('webp/alpha.webp');

		$this->assertSame('', self::profile($lossy));
		$this->assertSame('', self::profile($alpha));
		$this->assertSame('', self::profile(''));

		$vp8x   = self::extended(0);
		$vp8    = self::lossy();
		$icc    = self::icc(300);
		$iccp   = self::riff('ICCP', $icc);
		$flag   = self::webp($vp8x, $iccp, $vp8);
		$simple = self::webp($iccp, $vp8);

		$this->assertSame('', self::profile($flag));
		$this->assertSame('', self::profile($simple));

		$vp8x = self::extended(0x20);
		$cut  = self::riff('ICCP', substr($icc, 0, 299));
		$data = self::webp($vp8x, $cut, $vp8);

		$this->assertSame('', self::profile($data));
	}

	public function testIccTruncated(): void
	{
		$data = self::fixture('webp/icc.webp');
		$p3   = self::fixture('p3.icc');

		$end = strpos($data, 'ICCP') + 8 + strlen($p3);

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);

			$this->assertSame($length >= $end ? $p3 : '', self::profile($truncated));
		}

		$this->assertTruncated($data);
	}

	public static function fixtureProvider(): array
	{
		return [
			'lossy'        => ['webp/lossy.webp', 30, 20, Orientation::None, false],
			'lossless'     => ['webp/lossless.webp', 30, 20, Orientation::None, false],
			'odd lossy'    => ['webp/odd-lossy.webp', 31, 21, Orientation::None, false],
			'odd lossless' => ['webp/odd-lossless.webp', 31, 21, Orientation::None, false],
			'alpha'        => ['webp/alpha.webp', 30, 20, Orientation::None, false],
			'exif'         => ['webp/exif-6.webp', 30, 20, Orientation::Rotate90, false],
			'animated'     => ['webp/animated.webp', 30, 20, Orientation::None, true],
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

	public function testIsAnimatedFlag(): void
	{
		$flag     = self::extended(0x02);
		$all      = self::extended(0xFF);
		$others   = self::extended(0xFD);
		$vp8      = self::lossy();
		$anim     = self::riff('ANIM');
		$animated = self::webp($flag);
		$every    = self::webp($all);
		$still    = self::webp($others);
		$simple   = self::webp($vp8, $anim);

		$this->assertTrue(self::isAnimated($animated));
		$this->assertTrue(self::isAnimated($every));
		$this->assertFalse(self::isAnimated($still));
		$this->assertFalse(self::isAnimated($simple));
		$this->assertFalse(self::isAnimated(''));
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

	public function testReadExif(): void
	{
		$vp8x = self::extended(0x08);
		$vp8  = self::lossy();
		$six  = self::tiff(6);
		$exif = self::riff('EXIF', $six);
		$size = new Size(30, 20);

		$eight  = self::tiff(8);
		$marked = self::riff('EXIF', "Exif\0\0" . $eight);
		$data   = self::webp($vp8x, $vp8, $marked);

		$this->assertSame(Orientation::Rotate270, self::read($data)->orientation);

		$none   = self::extended(0);
		$flag   = self::webp($none, $vp8, $exif);
		$simple = self::webp($vp8, $exif);

		$this->assertSame(Orientation::None, self::read($flag)->orientation);
		$this->assertSame(Orientation::None, self::read($simple)->orientation);

		$animated = self::extended(0x0A);
		$anim     = self::riff('ANIM', str_repeat("\0", 6));
		$frame    = self::riff('ANMF', str_repeat("\0", 16) . $vp8);
		$data     = self::webp($animated, $anim, $frame, $frame, $exif);
		$header   = new Header($size, Orientation::Rotate90);

		$this->assertEquals($header, self::read($data));

		$broken = self::riff('EXIF', "MM\0*\xFF\xFF\xFF\xFF");
		$data   = self::webp($vp8x, $vp8, $broken);

		$this->assertEquals(new Header($size), self::read($data));
	}

	public function testReadGarbage(): void
	{
		$exif     = self::fixture('webp/exif-6.webp');
		$lossless = self::fixture('webp/lossless.webp');

		$this->assertGarbage($exif, keep: 12);
		$this->assertGarbage($lossless, keep: 12);
	}

	public static function readInvalidProvider(): array
	{
		$lossy     = self::lossy();
		$webp      = self::webp($lossy);
		$riff      = substr($webp, 4);
		$iccp      = self::riff('ICCP', 'abcdefghij');
		$code      = self::riff('VP8 ', "\x50\x02\0\x9D\x01\x2B" . pack('vv', 30, 20));
		$signature = self::riff('VP8L', "\x2E" . pack('V', 29 | 19 << 14));
		$vp8       = self::riff('VP8 ', "\x50\x02\0\x9D\x01\x2A" . pack('v', 30));
		$vp8l      = self::riff('VP8L', "\x2F\x1D\0");
		$vp8x      = self::riff('VP8X', "\0\0\0\0\x1D\0\0");

		return [
			'empty'          => [''],
			'no RIFF'        => ['RIFX' . $riff],
			'no WebP'        => ['RIFF' . pack('V', 4) . 'WAVE' . $lossy],
			'header only'    => [self::webp()],
			'other chunk'    => [self::webp($iccp, $lossy)],
			'no start code'  => [self::webp($code)],
			'no signature'   => [self::webp($signature)],
			'short lossy'    => [self::webp($vp8, $lossy)],
			'short lossless' => [self::webp($vp8l, $lossy)],
			'short extended' => [self::webp($vp8x, $lossy)],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadLimit(): void
	{
		$tiff = self::tiff(6);
		$exif = self::riff('EXIF', $tiff);

		$vp8x   = self::extended(0x0A);
		$frame  = self::riff('ANMF');
		$frames = str_repeat($frame, Reader::STEPS - 2);
		$last   = self::webp($vp8x, $frames, $exif);
		$beyond = self::webp($vp8x, $frames, $frame, $exif);

		$this->assertSame(Orientation::Rotate90, self::read($last)->orientation);
		$this->assertSame(Orientation::None, self::read($beyond)->orientation);

		$vp8x = self::extended(0x08);
		$long = self::riff('VP8 ', str_repeat("\0", 10_000_000));
		$data = self::webp($vp8x, $long, $exif);

		$this->assertSame(Orientation::Rotate90, self::read($data)->orientation);

		$absurd = 'EXIF' . pack('V', 0xFFFFFFFF) . $tiff;
		$data   = self::webp($vp8x, $absurd);

		$this->assertReads($data);
		$this->assertSame($tiff, self::exif($data));
	}

	public function testReadSizes(): void
	{
		$vp8      = self::lossy(0xFFFF, 0x3FFF);
		$wide     = self::lossless(16383);
		$tall     = self::lossless(16383 << 14);
		$vp8x     = self::extended(0, 16777216, 2);
		$lossy    = self::webp($vp8);
		$across   = self::webp($wide);
		$down     = self::webp($tall);
		$extended = self::webp($vp8x);

		$this->assertEquals(new Size(16383, 16383), self::read($lossy)->size);
		$this->assertEquals(new Size(16384, 1), self::read($across)->size);
		$this->assertEquals(new Size(1, 16384), self::read($down)->size);
		$this->assertEquals(new Size(16777216, 2), self::read($extended)->size);
	}

	public function testReadTruncated(): void
	{
		$data = self::fixture('webp/lossy.webp');

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);

			$this->assertSame($length >= 30 ? 30 : 0, self::read($truncated)->size->width);
		}

		$exif     = self::fixture('webp/exif-6.webp');
		$animated = self::fixture('webp/animated.webp');

		$this->assertTruncated($exif);
		$this->assertTruncated($animated);
	}

	protected static function webp(string ...$chunks): string
	{
		$data = 'WEBP' . implode('', $chunks);

		return 'RIFF' . pack('V', strlen($data)) . $data;
	}
}
