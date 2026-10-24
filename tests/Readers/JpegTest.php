<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Orientation;
use Darkroom\Readers\Exif;
use Darkroom\Readers\Header;
use Darkroom\Readers\Jpeg;
use Darkroom\Readers\Reader;
use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Jpeg::class)]
class JpegTest extends ReaderTestCase
{
	protected const string READER = Jpeg::class;

	protected static function app2(string $profile, int $size = 65519): array
	{
		$parts    = str_split($profile, $size);
		$segments = [];

		foreach ($parts as $index => $part) {
			$head       = "ICC_PROFILE\0" . chr($index + 1) . chr(count($parts));
			$segments[] = self::segment(0xE2, $head . $part);
		}

		return $segments;
	}

	protected static function frame(
		int $width,
		int $height,
		int $marker = 0xC0
	): string {
		$size       = pack('nn', $height, $width);
		$components = "\x03\x01\x22\0\x02\x11\x01\x03\x11\x01";

		return self::segment($marker, "\x08" . $size . $components);
	}

	protected static function jpeg(string ...$segments): string
	{
		return "\xFF\xD8" . implode('', $segments);
	}

	public function testExif(): void
	{
		$data   = self::fixture('exif.jpg');
		$exif   = self::exif($data);
		$handle = self::handle($exif);
		$header = Exif::read($handle);

		$this->assertStringStartsWith("II*\0", $exif);
		$this->assertSame(Orientation::Rotate270, $header->orientation);

		$data   = self::fixture('orientation/Landscape_6.jpg');
		$exif   = self::exif($data);
		$handle = self::handle($exif);
		$header = Exif::read($handle);

		$this->assertStringStartsWith("MM\0*", $exif);
		$this->assertSame(Orientation::Rotate90, $header->orientation);
	}

	public function testExifAfterOtherSegments(): void
	{
		$jfif  = self::segment(0xE0, 'JFIF');
		$xmp   = self::xmp();
		$three = self::app1(3);
		$six   = self::app1(6);
		$data  = self::jpeg($jfif, $xmp, $three, $six);
		$tiff  = self::tiff(3);

		$this->assertSame($tiff, self::exif($data));
	}

	public function testExifNone(): void
	{
		$cmyk   = self::fixture('cmyk.jpg');
		$marker = self::segment(0xE1, "Exif\0\0");
		$empty  = self::jpeg($marker);

		$this->assertSame('', self::exif($cmyk));
		$this->assertSame('', self::exif(''));
		$this->assertSame('', self::exif($empty));
	}

	public function testIcc(): void
	{
		$data = self::fixture('icc.jpg');
		$p3   = self::fixture('p3.icc');

		$this->assertSame($p3, self::profile($data));

		$icc   = self::icc(150_000);
		$fpxr  = self::segment(0xE2, "FPXR\0");
		$frame = self::frame(640, 480);

		[$first, $second, $third] = self::app2($icc);

		$data = self::jpeg($fpxr, $frame, $third, $first, $second);

		$this->assertSame($icc, self::profile($data));
	}

	public function testIccGarbage(): void
	{
		$data     = self::fixture('icc.jpg');
		$scan     = strpos($data, "\xFF\xDA") + 2;
		$segments = substr($data, 0, $scan);

		$this->assertGarbage($segments, keep: 2);
	}

	public function testIccLimit(): void
	{
		$icc      = self::icc(1_048_576);
		$segments = self::app2($icc);
		$data     = self::jpeg(...$segments);

		$this->assertSame($icc, self::profile($data));

		$icc      = self::icc(1_048_577);
		$segments = self::app2($icc);
		$data     = self::jpeg(...$segments);

		$this->assertSame('', self::profile($data));
	}

	public function testIccNone(): void
	{
		$cmyk = self::fixture('cmyk.jpg');
		$exif = self::fixture('exif.jpg');

		$this->assertSame('', self::profile($cmyk));
		$this->assertSame('', self::profile($exif));
		$this->assertSame('', self::profile(''));

		$icc = self::icc(300);

		[$first, $second, $third] = self::app2($icc, 100);

		$zero     = substr_replace($first, "\0", 16, 1);
		$fourth   = substr_replace($third, "\x04", 16, 1);
		$disagree = substr_replace($third, "\x04", 17, 1);
		$scan     = self::segment(0xDA, '');
		$cases    = [
			'missing'         => [$first, $third],
			'twice'           => [$first, $second, $second, $third],
			'number 0'        => [$zero, $second, $third],
			'number 4'        => [$first, $second, $fourth],
			'counts disagree' => [$first, $second, $disagree],
			'after the scan'  => [$first, $second, $scan, $third],
		];

		foreach ($cases as $segments) {
			$data = self::jpeg(...$segments);

			$this->assertSame('', self::profile($data));
		}

		$part     = substr($icc, 0, 299);
		$segments = self::app2($part);
		$cut      = self::jpeg(...$segments);
		$segments = self::app2($icc . 'more');
		$longer   = self::jpeg(...$segments);

		$this->assertSame('', self::profile($cut));
		$this->assertSame($icc, self::profile($longer));
	}

	public function testIccTruncated(): void
	{
		$data = self::fixture('icc.jpg');
		$p3   = self::fixture('p3.icc');

		$end = strpos($data, "\xFF\xE2") + 4 + 14 + strlen($p3);

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);

			$this->assertSame($length >= $end ? $p3 : '', self::profile($truncated));
		}
	}

	public static function readProvider(): array
	{
		return [
			'cmyk'        => ['cmyk.jpg', 40, 30, Orientation::None],
			'exif'        => ['exif.jpg', 64, 48, Orientation::Rotate270],
			'progressive' => ['progressive.jpg', 40, 30, Orientation::None],
			'landscape 0' => ['orientation/Landscape_0.jpg', 180, 120, Orientation::None],
			'landscape 1' => ['orientation/Landscape_1.jpg', 180, 120, Orientation::None],
			'landscape 2' => ['orientation/Landscape_2.jpg', 180, 120, Orientation::FlipHorizontal],
			'landscape 3' => ['orientation/Landscape_3.jpg', 180, 120, Orientation::Rotate180],
			'landscape 4' => ['orientation/Landscape_4.jpg', 180, 120, Orientation::FlipVertical],
			'landscape 5' => ['orientation/Landscape_5.jpg', 120, 180, Orientation::Transpose],
			'landscape 6' => ['orientation/Landscape_6.jpg', 120, 180, Orientation::Rotate90],
			'landscape 7' => ['orientation/Landscape_7.jpg', 120, 180, Orientation::Transverse],
			'landscape 8' => ['orientation/Landscape_8.jpg', 120, 180, Orientation::Rotate270],
			'portrait 0'  => ['orientation/Portrait_0.jpg', 120, 180, Orientation::None],
			'portrait 1'  => ['orientation/Portrait_1.jpg', 120, 180, Orientation::None],
			'portrait 2'  => ['orientation/Portrait_2.jpg', 120, 180, Orientation::FlipHorizontal],
			'portrait 3'  => ['orientation/Portrait_3.jpg', 120, 180, Orientation::Rotate180],
			'portrait 4'  => ['orientation/Portrait_4.jpg', 120, 180, Orientation::FlipVertical],
			'portrait 5'  => ['orientation/Portrait_5.jpg', 180, 120, Orientation::Transpose],
			'portrait 6'  => ['orientation/Portrait_6.jpg', 180, 120, Orientation::Rotate90],
			'portrait 7'  => ['orientation/Portrait_7.jpg', 180, 120, Orientation::Transverse],
			'portrait 8'  => ['orientation/Portrait_8.jpg', 180, 120, Orientation::Rotate270],
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

	public function testReadBetweenSegments(): void
	{
		$frame = self::frame(640, 480);
		$jfif  = self::segment(0xE0, 'JFIF');
		$app0  = self::segment(0xE0, '');

		$fill  = self::jpeg("\xFF\xFF\xFF", $frame);
		$fills = str_repeat("\xFF\xFF" . $app0, Reader::STEPS - 1);
		$many  = self::jpeg($fills, "\xFF", $frame);

		$this->assertSame(640, self::read($fill)->size->width);
		$this->assertSame(640, self::read($many)->size->width);

		$stray = self::jpeg($jfif, "\0\x12", $frame);
		$zero  = self::jpeg($jfif, "\xFF\0", $frame);

		$this->assertSame(0, self::read($stray)->size->width);
		$this->assertSame(0, self::read($zero)->size->width);
	}

	public function testReadExif(): void
	{
		$frame = self::frame(640, 480);
		$three = self::app1(3);
		$six   = self::app1(6);

		$xmp  = self::xmp();
		$data = self::jpeg($xmp, $six, $three, $frame);

		$this->assertSame(Orientation::Rotate90, self::read($data)->orientation);

		$after  = self::jpeg($frame, $six, $three);
		$around = self::jpeg($six, $frame, $three);

		$this->assertSame(Orientation::Rotate90, self::read($after)->orientation);
		$this->assertSame(Orientation::Rotate90, self::read($around)->orientation);

		$scan = self::segment(0xDA, '');
		$data = self::jpeg($frame, $scan, $six);

		$this->assertSame(Orientation::None, self::read($data)->orientation);

		$broken = self::segment(0xE1, "Exif\0\0MM\0*\xFF\xFF\xFF\xFF");
		$data   = self::jpeg($broken, $frame);
		$size   = new Size(640, 480);

		$this->assertEquals(new Header($size), self::read($data));
	}

	public function testReadFrames(): void
	{
		$first  = self::frame(640, 480);
		$second = self::frame(320, 240);
		$data   = self::jpeg($first, $second);

		$this->assertSame(640, self::read($data)->size->width);

		// SOF0–SOF15, except DHT (C4), JPG (C8) and DAC (CC)
		for ($marker = 0xC0; $marker <= 0xCF; $marker++) {
			$frame = self::frame(640, 480, $marker);
			$data  = self::jpeg($frame);
			$width = in_array($marker, [0xC4, 0xC8, 0xCC], true) === true ? 0 : 640;

			$this->assertSame($width, self::read($data)->size->width);
		}
	}

	public function testReadGarbage(): void
	{
		foreach (['orientation/Landscape_6.jpg', 'exif.jpg'] as $file) {
			$data     = self::fixture($file);
			$scan     = strpos($data, "\xFF\xDA") + 2;
			$segments = substr($data, 0, $scan);

			$this->assertGarbage($segments, keep: 2);
		}
	}

	public static function readInvalidProvider(): array
	{
		$frame = self::frame(640, 480);
		$scan  = self::segment(0xDA, '');
		$exif  = "\xFF\xE1\xFF\xFFExif\0\0MM\0*\0\0\0\x08";

		return [
			'empty'                 => [''],
			'no JPEG'               => ['GIF89a' . $frame],
			'start of image only'   => ["\xFF\xD8"],
			'scan before the frame' => ["\xFF\xD8" . $scan . $frame],
			'end of image'          => ["\xFF\xD8\xFF\xD9" . $frame],
			'no length'             => ["\xFF\xD8\xFF\xE0\0\0" . $frame],
			'length 1'              => ["\xFF\xD8\xFF\xE0\0\x01" . $frame],
			'beyond the end'        => ["\xFF\xD8\xFF\xE0\xFF\xFF" . $frame],
			'short frame header'    => ["\xFF\xD8\xFF\xC0\0\x06\x08\x01\xE0\x02" . $frame],
			'exif beyond the end'   => ["\xFF\xD8" . $exif . $frame],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadLimit(): void
	{
		$frame = self::frame(640, 480);
		$app0  = self::segment(0xE0, '');

		$segments = str_repeat($app0, Reader::STEPS - 1);
		$last     = self::jpeg($segments, $frame);
		$beyond   = self::jpeg($segments, $app0, $frame);

		$this->assertSame(640, self::read($last)->size->width);
		$this->assertSame(0, self::read($beyond)->size->width);

		$fills = str_repeat("\xFF", 1_000_000);
		$data  = self::jpeg($fills, $frame);

		$this->assertSame(640, self::read($data)->size->width);

		$fills  = str_repeat("\xFF", 10_000_000);
		$data   = self::jpeg($fills, $frame);
		$handle = self::handle($data);

		$this->assertSame(0, Jpeg::read($handle)->size->width);
		$this->assertLessThan(1_100_000, ftell($handle));
	}

	public function testReadTruncated(): void
	{
		$data = self::fixture('orientation/Landscape_6.jpg');

		$end = strpos($data, "\xFF\xC0\0\x11") + 9;

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);
			$header    = self::read($truncated);

			$this->assertSame($length >= $end ? 120 : 0, $header->size->width);
			$this->assertSame($length >= $end ? 180 : 0, $header->size->height);
		}

		$exif = self::fixture('exif.jpg');

		$this->assertTruncated($data);
		$this->assertTruncated($exif);
	}

	protected static function xmp(): string
	{
		return self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta/>");
	}
}
