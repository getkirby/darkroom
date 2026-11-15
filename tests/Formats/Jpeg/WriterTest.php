<?php

namespace Darkroom\Tests\Formats\Jpeg;

use Darkroom\Formats\Jpeg\Writer;
use Darkroom\Meta;
use Darkroom\Meta\Exif;
use Darkroom\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;

#[CoversClass(Writer::class)]
class WriterTest extends JpegTestCase
{
	public function testStrip(): void
	{
		$jfif     = self::segment(0xE0, "JFIF\0\x01\x02\x01\0\x48\0\x48\x01\x01\xAA\xBB\xCC");
		$bare     = self::segment(0xE0, "JFIF\0\x01\x02\x01\0\x48\0\x48\0\0");
		$jfxx     = self::segment(0xE0, "JFXX\0\x10" . 'thumbnail');
		$tiff     = self::tiff([[0x0112, 3, 6], [0x8825, 4, 26]]);
		$camera   = self::segment(0xE1, "Exif\0\0" . $tiff);
		$rotated  = self::app1(6);
		$xmp      = self::xmp();
		$iptc     = self::segment(0xED, "Photoshop 3.0\0" . '8BIM');
		[$icc]    = self::app2('profile');
		$mpf      = self::segment(0xE2, "MPF\0" . 'index');
		$adobe    = self::segment(0xEE, "Adobe\0\x64\0\0\0\0\x01");
		$comment  = self::segment(0xFE, 'comment');
		$quant    = self::segment(0xDB, "\0" . str_repeat("\x01", 64));
		$frame    = self::frame(30, 20);
		$scan     = self::segment(0xDA, "\x01\x01\0\0\x3F\0") . "\x12\xFF\0\x34\xFF\xD0\x56";
		$end      = "\xFF\xD9";
		$trailer  = self::jpeg($frame, $scan, $end);
		$expected = self::jpeg($bare, $rotated, $icc, $adobe, $quant, $frame, $scan, $end);
		$data     = self::jpeg(
			$jfif,
			$jfxx,
			$camera,
			$xmp,
			$iptc,
			$icc,
			$mpf,
			$adobe,
			$comment,
			$quant,
			$frame,
			$scan,
			$end
		);

		$stripped = self::strip($data . $trailer);

		$this->assertSame($expected, $stripped);
	}

	public static function stripFixtureProvider(): array
	{
		return [
			'exif'     => ['exif.jpg', Orientation::Rotate270],
			'meta'     => ['meta.jpg', Orientation::None],
			'rotated'  => ['orientation/Portrait_6.jpg', Orientation::Rotate90],
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

		// the original's image from the first quantization table
		// on, which the original may have in its EXIF thumbnail too
		$image = substr($stripped, strpos($stripped, "\xFF\xDB"));

		$this->assertEquals(self::read($data), self::read($stripped));
		$this->assertSame(self::profile($data), self::profile($stripped));
		$this->assertSame($orientation, Exif::orientation($handle));
		$this->assertSame($orientation === Orientation::None, $exif === '');
		$this->assertStringEndsWith($image, $data);
		$this->assertSame($stripped, $again);
	}

	public function testStripGarbage(): void
	{
		$data = self::fixture('exif.jpg');

		$this->assertStrips($data, 2);
	}

	public function testStripMemory(): void
	{
		$frame  = self::frame(30, 20);
		$scan   = self::segment(0xDA, "\x01\x01\0\0\x3F\0") . str_repeat("\x12", 3_000_000);
		$data   = self::jpeg($frame, $scan, "\xFF\xD9");
		$handle = self::handle($data);
		$out    = fopen('php://temp/maxmemory:0', 'w+b');

		memory_reset_peak_usage();
		$memory = memory_get_usage();

		Writer::write($handle, $out);

		$peak = memory_get_peak_usage();

		$this->assertLessThan($memory + 100_000, $peak);
		$this->assertSame(strlen($data), ftell($out));
	}

	public function testStripOther(): void
	{
		$data     = self::fixture('progressive.jpg');
		$camera   = self::fixture('exif.jpg');
		$tiff     = self::exif($camera);
		$tags     = [0x010F];
		$expected = Exif::filter($tiff, $tags);

		$stripped = self::strip($data, $tags, $tiff);

		// without the other image's orientation
		$this->assertSame($expected, self::exif($stripped));
	}

	public function testStripStray(): void
	{
		$quant    = self::segment(0xDB, "\0" . str_repeat("\x01", 64));
		$frame    = self::frame(30, 20);
		$first    = self::segment(0xDA, "\x01\x01\0\0\x3F\0") . "\x12\xFF\0";
		$huffman  = self::segment(0xC4, str_repeat("\0", 17));
		$second   = self::segment(0xDA, "\x01\x01\0\0\x3F\0") . "\x34";
		$end      = "\xFF\xFF\xFF\xD9";
		$data     = self::jpeg($quant, 'stray', $frame, "\xFF\xFF", $first, $huffman, $second, $end);
		$expected = self::jpeg($quant, $frame, $first, $huffman, $second, $end);

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	#[RequiresFunction('exif_read_data')]
	public function testStripTags(): void
	{
		$data     = self::fixture('exif.jpg');
		$exif     = self::exif($data);
		$original = Exif::tags($exif);
		$tags     = Meta::exif([Meta::Camera, Meta::Time]);

		$stripped = self::strip($data, $tags);
		$exif     = self::exif($stripped);
		$kept     = Exif::tags($exif);

		$this->assertSame(['IFD0', 'EXIF'], array_keys($kept));
		$this->assertSame($original['IFD0']['Make'], $kept['IFD0']['Make']);
		$this->assertSame($original['EXIF']['DateTimeOriginal'], $kept['EXIF']['DateTimeOriginal']);
		$this->assertSame(8, $kept['IFD0']['Orientation']);
	}

	public static function stripUnchangedProvider(): array
	{
		return [
			['cmyk.jpg'],
			['icc.jpg'],
			['progressive.jpg'],
			['sampling-422.jpg'],
		];
	}

	#[DataProvider('stripUnchangedProvider')]
	public function testStripUnchanged(string $name): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);

		$this->assertSame($data, $stripped);
	}

	public function testStripWithoutJfif(): void
	{
		$quant    = self::segment(0xDB, "\0" . str_repeat("\x01", 64));
		$camera   = self::app1(3);
		$xmp      = self::xmp();
		$data     = self::jpeg($xmp, $camera, $quant, "\xFF\xD9");
		$expected = self::jpeg($camera, $quant, "\xFF\xD9");

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	public function testWriteMeta(): void
	{
		$data    = self::fixture('progressive.jpg');
		$tiff    = self::tiff([[0x0100, 4, 30]]);
		$icc     = self::icc(200);
		$markers = ["\xFF\xE0", "Exif\0\0", 'ICC_PROFILE'];

		$written = self::write($data, $tiff, $icc);

		$this->assertSame($tiff, self::exif($written));
		$this->assertSame($icc, self::profile($written));

		// after JFIF: EXIF and the profile
		$positions = array_map(fn (string $marker) => strpos($written, $marker), $markers);
		$sorted    = $positions;

		sort($sorted);

		$this->assertSame($sorted, $positions);
	}

	public function testWriteTooLong(): void
	{
		$data   = self::fixture('progressive.jpg');
		$value  = str_repeat('a', 70_000);
		$tiff   = "MM\0*" . pack('NnnnNNN', 8, 1, 0x010E, 2, 70_000, 26, 0) . $value;

		$written = self::write($data, $tiff);

		$this->assertSame(self::strip($data), $written);
	}
}
