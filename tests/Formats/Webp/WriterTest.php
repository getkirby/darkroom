<?php

namespace Darkroom\Tests\Formats\Webp;

use Darkroom\Formats\Jpeg\Reader as Jpeg;
use Darkroom\Formats\Webp\Vp8x;
use Darkroom\Formats\Webp\Writer;
use Darkroom\Meta\Exif;
use Darkroom\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Writer::class)]
#[CoversClass(Vp8x::class)]
class WriterTest extends WebpTestCase
{
	public function testStrip(): void
	{
		// ICC, alpha, EXIF and XMP; without XMP
		$extended = self::extended(0x3C);
		$flagged  = self::extended(0x38);
		$icc      = self::riff('ICCP', 'profile');
		$alpha    = self::riff('ALPH', 'alpha');
		$pixels   = self::lossy();
		$tiff     = self::tiff([[0x0112, 3, 6], [0x8825, 4, 26]]);
		$camera   = self::riff('EXIF', "Exif\0\0" . $tiff);
		$six      = self::tiff(6);
		$rotated  = self::riff('EXIF', $six);
		$xmp      = self::riff('XMP ', '<x:xmpmeta/>');
		$c2pa     = self::riff('C2PA', 'manifest');
		$data     = self::webp($extended, $icc, $alpha, $pixels, $camera, $xmp, $c2pa);
		$expected = self::webp($flagged, $icc, $alpha, $pixels, $rotated);

		$stripped = self::strip($data . 'trailing');

		$this->assertSame($expected, $stripped);
	}

	public static function stripFixtureProvider(): array
	{
		return [
			'animated' => ['webp/animated.webp', Orientation::None],
			'exif'     => ['webp/exif-6.webp', Orientation::Rotate90],
			'icc'      => ['webp/icc.webp', Orientation::None],
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

		$this->assertEquals(self::read($data), self::read($stripped));
		$this->assertSame(self::profile($data), self::profile($stripped));
		$this->assertSame($orientation, Exif::orientation($handle));
		$this->assertSame($orientation === Orientation::None, $exif === '');
		$this->assertSame(self::isAnimated($data), self::isAnimated($stripped));
		$this->assertSame($stripped, $again);
	}

	public function testStripGarbage(): void
	{
		$data = self::fixture('webp/exif-6.webp');

		$this->assertStrips($data, 12);
	}

	public function testStripTags(): void
	{
		$camera   = self::fixture('exif.jpg');
		$handle   = self::handle($camera);
		$jpeg     = new Jpeg($handle);
		$tiff     = $jpeg->exif();
		$tags     = [0x010F, 0x0110];
		$kept     = Exif::filter($tiff, $tags, Orientation::Rotate270);
		$vp8x     = self::extended(0x08);
		$pixels   = self::lossy();
		$exif     = self::riff('EXIF', $tiff);
		$filtered = self::riff('EXIF', $kept);
		$data     = self::webp($vp8x, $pixels, $exif);
		$expected = self::webp($vp8x, $pixels, $filtered);

		$stripped = self::strip($data, $tags);

		$this->assertSame($expected, $stripped);
	}

	public function testStripTrailing(): void
	{
		$pixels   = self::lossy();
		$data     = self::webp($pixels);
		$trailing = self::riff('ALPH', 'trailing');

		$stripped = self::strip($data . $trailing);

		$this->assertSame($data, $stripped);
	}

	public static function stripUnchangedProvider(): array
	{
		return [
			['webp/alpha.webp'],
			['webp/animated.webp'],
			['webp/lossless.webp'],
			['webp/lossy.webp'],
			['webp/odd-lossy.webp'],
		];
	}

	#[DataProvider('stripUnchangedProvider')]
	public function testStripUnchanged(string $name): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);

		$this->assertSame($data, $stripped);
	}

	public function testStripWithoutOrientation(): void
	{
		// EXIF and XMP
		$extended = self::extended(0x0C);
		$plain    = self::extended(0);
		$pixels   = self::lossless(0);
		$tiff     = self::tiff([[0x0100, 4, 30]]);
		$exif     = self::riff('EXIF', $tiff);
		$xmp      = self::riff('XMP ', '<x:xmpmeta/>');
		$data     = self::webp($extended, $pixels, $exif, $xmp);
		$expected = self::webp($plain, $pixels);

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	public function testWriteProfile(): void
	{
		$extended = self::extended(0x20);
		$plain    = self::extended(0);
		$own      = self::riff('ICCP', self::icc(200));
		$icc      = self::icc(300);
		$iccp     = self::riff('ICCP', $icc);
		$pixels   = self::lossy();
		$data     = self::webp($extended, $own, $pixels);

		$replaced = self::write($data, icc: $icc);
		$removed  = self::write($data, icc: '');

		$this->assertSame(self::webp($extended, $iccp, $pixels), $replaced);
		$this->assertSame(self::webp($plain, $pixels), $removed);
	}

	public function testWriteSimple(): void
	{
		// lossless with alpha, then lossy
		$lossless = self::lossless(0x10000000 | 19 << 14 | 29);
		$lossy    = self::lossy();
		$tiff     = self::tiff([[0x0100, 4, 30]]);
		$six      = Exif::filter($tiff, [], Orientation::Rotate90);
		$exif     = self::riff('EXIF', $six);
		$icc      = self::icc(200);
		$iccp     = self::riff('ICCP', $icc);

		$transparent = self::webp($lossless);
		$opaque      = self::webp($lossy);

		// extended, as meta needs the flags
		$flagged  = self::extended(0x38);
		$expected = self::webp($flagged, $iccp, $lossless, $exif);
		$extended = self::extended(0x28);
		$other    = self::webp($extended, $iccp, $lossy, $exif);

		$alpha = self::write($transparent, $six, $icc);
		$plain = self::write($opaque, $six, $icc);

		$this->assertSame($expected, $alpha);
		$this->assertSame($other, $plain);
	}
}
