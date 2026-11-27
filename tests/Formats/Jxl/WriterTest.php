<?php

namespace Darkroom\Tests\Formats\Jxl;

use Darkroom\Formats\Jpeg\Reader as Jpeg;
use Darkroom\Formats\Jxl\Writer;
use Darkroom\Meta\Exif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Writer::class)]
class WriterTest extends JxlTestCase
{
	public function testStrip(): void
	{
		$codestream = self::jxl(...self::SIZE);
		$level      = self::box('jxll', "\x0A");
		$tiff       = self::tiff(6);
		$exif       = self::box('Exif', pack('N', 0) . $tiff);
		$xmp        = self::box('xml ', '<x:xmpmeta/>');
		$c2pa       = self::box('jumb', 'manifest');
		$brotli     = self::box('brob', 'Exifcompressed');
		$jpeg       = self::box('jbrd', 'reconstruction');
		$first      = self::jxlp(0, substr($codestream, 0, 10));
		$last       = self::jxlp(0x80000001, substr($codestream, 10));
		$index      = self::box('jxli', 'index');
		$data       = self::container($level, $exif, $xmp, $first, $c2pa, $brotli, $jpeg, $last, $index);
		$expected   = self::container($level, $first, $last, $index);

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	public function testStripEnd(): void
	{
		$codestream = self::jxl(...self::SIZE);
		$tiff       = self::tiff(6);
		$exif       = self::box('Exif', pack('N', 0) . $tiff);
		$jxlc       = self::last('jxlc', $codestream);
		$data       = self::container($exif, $jxlc);
		$expected   = self::container($jxlc);

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	public static function stripFixtureProvider(): array
	{
		return [
			'brotli' => ['jxl/brob.jxl'],
			'exif'   => ['jxl/exif-6.jxl'],
		];
	}

	#[DataProvider('stripFixtureProvider')]
	public function testStripFixture(string $name): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);

		$this->assertEquals(self::read($data), self::read($stripped));
		$this->assertSame('', self::exif($stripped));
		$this->assertStringNotContainsString('jbrd', $stripped);
		$this->assertStringNotContainsString('brob', $stripped);
		$this->assertLessThan(strlen($data), strlen($stripped));
	}

	public function testStripGarbage(): void
	{
		$data = self::fixture('jxl/exif-6.jxl');

		$this->assertStrips($data, 12);
	}

	public function testStripTags(): void
	{
		$data = self::fixture('jxl/exif-6.jxl');
		$tiff = self::exif($data);
		$tags = [0x010F];
		$kept = Exif::filter($tiff, $tags);

		$stripped = self::strip($data, $tags);

		// without an orientation, as the codestream has it
		$this->assertSame($kept, self::exif($stripped));
		$this->assertStringNotContainsString('jbrd', $stripped);
	}

	public static function stripUnchangedProvider(): array
	{
		return [
			['jxl/animated.jxl'],
			['jxl/container.jxl'],
			['jxl/plain.jxl'],
		];
	}

	#[DataProvider('stripUnchangedProvider')]
	public function testStripUnchanged(string $name): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);

		$this->assertSame($data, $stripped);
	}

	public function testStripWrap(): void
	{
		$data   = self::fixture('jxl/plain.jxl');
		$camera = self::fixture('exif.jpg');
		$handle = self::handle($camera);
		$jpeg   = new Jpeg($handle);
		$tiff   = $jpeg->exif();
		$tags   = [0x010F];
		$kept   = Exif::filter($tiff, $tags);

		$stripped = self::strip($data, $tags, $tiff);

		// the bare codestream in a container, after the EXIF data
		$this->assertStringStartsWith("\0\0\0\x0CJXL \r\n\x87\n", $stripped);
		$this->assertStringEndsWith('jxlc' . $data, $stripped);
		$this->assertSame($kept, self::exif($stripped));
		$this->assertEquals(self::read($data), self::read($stripped));
	}
}
