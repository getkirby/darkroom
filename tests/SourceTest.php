<?php

namespace Darkroom\Tests;

use Darkroom\Exception\UnsupportedException;
use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Readers\Reader;
use Darkroom\Rect;
use Darkroom\Size;
use Darkroom\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[CoversClass(Source::class)]
class SourceTest extends TestCase
{
	protected const string FIXTURES = __DIR__ . '/fixtures';

	protected static function fixture(string $name): Source
	{
		return new Source(self::FIXTURES . '/' . $name);
	}

	protected function invalid(): array
	{
		$missing = $this->file('missing.jpg');
		$dir     = $this->tmp();
		$empty   = $this->file('empty', '');
		$svg     = $this->file('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

		return [
			new Source($missing),
			new Source($dir),
			new Source($empty),
			new Source($svg),
			new Source(''),
		];
	}

	public function testCanvas(): void
	{
		$screen = new Size(200, 100);
		$frame  = new Rect(80, 30, 41, 41);
		$gif    = self::framed($screen, $frame);
		$file   = $this->file('frame.gif', $gif);
		$source = new Source($file);
		$still  = self::fixture('gif/still.gif');
		$jpeg   = self::fixture('exif.jpg');

		$this->assertEquals(new Rect(-80, -30, 200, 100), $source->canvas());
		$this->assertNull($still->canvas());
		$this->assertNull($jpeg->canvas());

		foreach ($this->invalid() as $source) {
			$this->assertNull($source->canvas());
		}
	}

	public function testCicp(): void
	{
		$files = [
			'heif/hdr.avif'   => [9, 16, 9, 1],
			'heif/plain.heic' => [1, 13, 6, 1],
			'exif.jpg'        => Reader::UNSPECIFIED,
		];

		foreach ($files as $file => $cicp) {
			$source = self::fixture($file);

			$this->assertSame($cicp, $source->cicp());
		}

		foreach ($this->invalid() as $source) {
			$this->assertSame(Reader::UNSPECIFIED, $source->cicp());
		}
	}

	public function testCicpReadsOnce(): void
	{
		$data   = file_get_contents(self::FIXTURES . '/heif/hdr.avif');
		$file   = $this->file('image.avif', $data);
		$source = new Source($file);
		$cicp   = $source->cicp();

		file_put_contents($file, 'replaced');

		$this->assertSame([9, 16, 9, 1], $cicp);
		$this->assertSame($cicp, $source->cicp());
		$this->assertTrue($source->isHdr());
	}

	public function testConstruct(): void
	{
		$file = $this->file('missing.jpg');

		$source = new Source($file);

		$this->assertSame($file, $source->file);
	}

	public function testConstructReadsOnce(): void
	{
		$data   = file_get_contents(self::FIXTURES . '/exif.jpg');
		$file   = $this->file('image', $data);
		$source = new Source($file);
		$screen = new Size(200, 100);
		$frame  = new Rect(80, 30, 41, 41);
		$gif    = self::framed($screen, $frame);

		$format      = $source->format();
		$size        = $source->size();
		$orientation = $source->orientation();
		$animated    = $source->isAnimated();
		$hdr         = $source->isHdr();
		$canvas      = $source->canvas();
		$exif        = $source->exif();

		file_put_contents($file, $gif);

		$this->assertSame($format, $source->format());
		$this->assertEquals($size, $source->size());
		$this->assertSame($orientation, $source->orientation());
		$this->assertSame($animated, $source->isAnimated());
		$this->assertSame($hdr, $source->isHdr());
		$this->assertNull($canvas);
		$this->assertNull($source->canvas());
		$this->assertSame($exif, $source->exif());
	}

	#[RequiresFunction('exif_read_data')]
	public function testExif(): void
	{
		$source = self::fixture('exif.jpg');
		$exif   = $source->exif();

		$this->assertSame(['IFD0', 'THUMBNAIL', 'EXIF', 'GPS'], array_keys($exif));
		$this->assertSame('Darkroom', $exif['IFD0']['Make']);
		$this->assertSame('Fixture', $exif['IFD0']['Model']);
		$this->assertSame(8, $exif['IFD0']['Orientation']);
		$this->assertSame('1/125', $exif['EXIF']['ExposureTime']);
		$this->assertSame('2020:01:02 03:04:05', $exif['EXIF']['DateTimeOriginal']);
		$this->assertSame('N', $exif['GPS']['GPSLatitudeRef']);

		$files = [
			'orientation/Landscape_6.jpg',
			'png/exif-6.png',
			'webp/exif-6.webp',
			'jxl/exif-6.jxl'
		];

		foreach ($files as $file) {
			$source = self::fixture($file);
			$exif   = $source->exif();

			$this->assertSame(6, $exif['IFD0']['Orientation']);
		}

		$source = self::fixture('heif/exif-irot-2.avif');
		$exif   = $source->exif();

		$this->assertSame('Darkroom', $exif['IFD0']['Make']);
		$this->assertSame(6, $exif['IFD0']['Orientation']);
	}

	#[RequiresFunction('exif_read_data')]
	public function testExifNone(): void
	{
		$files = [
			'cmyk.jpg',
			'png/plain.png',
			'webp/lossy.webp',
			'heif/plain.heic',
			'jxl/plain.jxl'
		];

		foreach ($files as $file) {
			$source = self::fixture($file);

			$this->assertSame([], $source->exif());
		}

		foreach ($this->invalid() as $source) {
			$this->assertSame([], $source->exif());
		}
	}

	public function testFormat(): void
	{
		$jpeg = self::fixture('exif.jpg');
		$file = $this->file('image', 'GIF89a');
		$gif  = new Source($file);

		$this->assertSame(Format::Jpeg, $jpeg->format());
		$this->assertSame(Format::Gif, $gif->format());
	}

	public function testFormatIgnoresExtension(): void
	{
		$data   = file_get_contents(self::FIXTURES . '/cmyk.jpg');
		$file   = $this->file('image.png', $data);
		$source = new Source($file);

		$this->assertSame(Format::Jpeg, $source->format());
	}

	public function testFormatReadsHeader(): void
	{
		$data   = file_get_contents(self::FIXTURES . '/orientation/Landscape_6.jpg');
		$file   = $this->file('image', $data);
		$source = new Source($file);

		$source->format();
		unlink($file);

		$this->assertEquals(new Size(180, 120), $source->size());
		$this->assertSame(Orientation::Rotate90, $source->orientation());
	}

	public function testFormatUnreadable(): void
	{
		$file   = $this->file('missing.jpg');
		$source = new Source($file);

		$this->expectException(UnsupportedException::class);
		$this->expectExceptionMessage('Unsupported or unreadable image: ' . $file);

		$source->format();
	}

	public function testFormatUnsupported(): void
	{
		$file   = $this->file('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
		$source = new Source($file);

		$this->expectException(UnsupportedException::class);
		$this->expectExceptionMessage('Unsupported or unreadable image: ' . $file);

		$source->format();
	}

	/**
	 * `magick icc.jpg p3.icc`
	 * `vips copy icc.jpg 'icc.png[keep=icc]'`
	 * `magick icc.jpg -strip -profile p3.icc icc.<extension>`
	 */
	public function testIcc(): void
	{
		$p3    = file_get_contents(self::FIXTURES . '/p3.icc');
		$files = [
			'icc.jpg'       => $p3,
			'png/icc.png'   => $p3,
			'webp/icc.webp' => $p3,
			'exif.jpg'      => '',
			'gif/still.gif' => '',
			'jxl/plain.jxl' => '',
		];

		foreach ($files as $file => $icc) {
			$source = self::fixture($file);

			$this->assertSame($icc, $source->icc());
		}

		foreach ($this->invalid() as $source) {
			$this->assertSame('', $source->icc());
		}
	}

	public function testIccReadsOnce(): void
	{
		$data   = file_get_contents(self::FIXTURES . '/icc.jpg');
		$file   = $this->file('image.jpg', $data);
		$source = new Source($file);
		$icc    = $source->icc();

		file_put_contents($file, 'replaced');

		$this->assertNotSame('', $icc);
		$this->assertSame($icc, $source->icc());
	}

	#[RequiresPhpExtension('gd')]
	public function testIsAnimated(): void
	{
		$file  = $this->file('still.gif');
		$image = imagecreatetruecolor(4, 3);

		imagegif($image, $file);

		$still = new Source($file);

		$this->assertFalse($still->isAnimated());

		// APNG files and HEIF sequences count as stills
		$files = [
			'exif.jpg'           => false,
			'gif/animated.gif'   => true,
			'gif/still.gif'      => false,
			'png/animated.png'   => false,
			'webp/animated.webp' => true,
			'webp/lossy.webp'    => false,
			'heif/animated.avif' => false,
			'heif/animated.heic' => false,
			'jxl/animated.jxl'   => true,
			'jxl/plain.jxl'      => false,
		];

		foreach ($files as $file => $animated) {
			$source = self::fixture($file);

			$this->assertSame($animated, $source->isAnimated());
		}

		foreach ($this->invalid() as $source) {
			$this->assertFalse($source->isAnimated());
		}
	}

	public function testIsHdr(): void
	{
		$files = [
			'heif/hdr.avif'   => true,
			'heif/plain.avif' => false,
			'heif/plain.heic' => false,
			'png/16-bit.png'  => false,
			'exif.jpg'        => false,
		];

		foreach ($files as $file => $hdr) {
			$source = self::fixture($file);

			$this->assertSame($hdr, $source->isHdr());
		}

		foreach ($this->invalid() as $source) {
			$this->assertFalse($source->isHdr());
		}
	}

	public static function fixtureProvider(): array
	{
		return [
			'cmyk'        => ['cmyk.jpg', 40, 30, Orientation::None],
			'exif'        => ['exif.jpg', 48, 64, Orientation::Rotate270],
			'progressive' => ['progressive.jpg', 40, 30, Orientation::None],
			'landscape 0' => ['orientation/Landscape_0.jpg', 180, 120, Orientation::None],
			'landscape 1' => ['orientation/Landscape_1.jpg', 180, 120, Orientation::None],
			'landscape 2' => ['orientation/Landscape_2.jpg', 180, 120, Orientation::FlipHorizontal],
			'landscape 3' => ['orientation/Landscape_3.jpg', 180, 120, Orientation::Rotate180],
			'landscape 4' => ['orientation/Landscape_4.jpg', 180, 120, Orientation::FlipVertical],
			'landscape 5' => ['orientation/Landscape_5.jpg', 180, 120, Orientation::Transpose],
			'landscape 6' => ['orientation/Landscape_6.jpg', 180, 120, Orientation::Rotate90],
			'landscape 7' => ['orientation/Landscape_7.jpg', 180, 120, Orientation::Transverse],
			'landscape 8' => ['orientation/Landscape_8.jpg', 180, 120, Orientation::Rotate270],
			'portrait 0'  => ['orientation/Portrait_0.jpg', 120, 180, Orientation::None],
			'portrait 1'  => ['orientation/Portrait_1.jpg', 120, 180, Orientation::None],
			'portrait 2'  => ['orientation/Portrait_2.jpg', 120, 180, Orientation::FlipHorizontal],
			'portrait 3'  => ['orientation/Portrait_3.jpg', 120, 180, Orientation::Rotate180],
			'portrait 4'  => ['orientation/Portrait_4.jpg', 120, 180, Orientation::FlipVertical],
			'portrait 5'  => ['orientation/Portrait_5.jpg', 120, 180, Orientation::Transpose],
			'portrait 6'  => ['orientation/Portrait_6.jpg', 120, 180, Orientation::Rotate90],
			'portrait 7'  => ['orientation/Portrait_7.jpg', 120, 180, Orientation::Transverse],
			'portrait 8'  => ['orientation/Portrait_8.jpg', 120, 180, Orientation::Rotate270],

			'gif'                 => ['gif/still.gif', 30, 20, Orientation::None],
			'jp2'                 => ['jp2/plain.jp2', 30, 20, Orientation::None],
			'jp2, codestream'     => ['jp2/plain.j2k', 30, 20, Orientation::None],
			'gif, animated'       => ['gif/animated.gif', 30, 20, Orientation::None],
			'png'                 => ['png/plain.png', 30, 20, Orientation::None],
			'png, exif'           => ['png/exif-6.png', 20, 30, Orientation::Rotate90],
			'webp'                => ['webp/lossy.webp', 30, 20, Orientation::None],
			'webp, odd'           => ['webp/odd-lossless.webp', 31, 21, Orientation::None],
			'webp, exif'          => ['webp/exif-6.webp', 20, 30, Orientation::Rotate90],
			'heic'                => ['heif/plain.heic', 30, 20, Orientation::None],
			'heic, exif'          => ['heif/exif.heic', 20, 30, Orientation::Rotate90],
			'avif, clap'          => ['heif/clap.avif', 25, 15, Orientation::None],
			'avif, irot'          => ['heif/irot-1.avif', 20, 30, Orientation::Rotate270],
			'avif, irot and imir' => ['heif/irot-1-imir-0.avif', 20, 30, Orientation::Transpose],
			'avif, exif and irot' => ['heif/exif-irot-2.avif', 30, 20, Orientation::Rotate180],
			'jxl'                 => ['jxl/plain.jxl', 30, 20, Orientation::None],
			'jxl, exif'           => ['jxl/exif-6.jxl', 20, 30, Orientation::Rotate90],
			'jxl, brob'           => ['jxl/brob.jxl', 20, 30, Orientation::Rotate90],
		];
	}

	#[DataProvider('fixtureProvider')]
	public function testOrientation(
		string $file,
		int $width,
		int $height,
		Orientation $orientation
	): void {
		$source = self::fixture($file);

		$this->assertSame($orientation, $source->orientation());
	}

	public function testOrientationUnreadable(): void
	{
		foreach ($this->invalid() as $source) {
			$this->assertSame(Orientation::None, $source->orientation());
		}
	}

	#[DataProvider('fixtureProvider')]
	public function testSize(
		string $file,
		int $width,
		int $height,
		Orientation $orientation
	): void {
		$source = self::fixture($file);
		$size   = new Size($width, $height);

		$this->assertEquals($size, $source->size());
		$this->assertSame($width, $source->width());
		$this->assertSame($height, $source->height());
		$this->assertSame($size->ratio(), $source->ratio());
		$this->assertSame($size->isLandscape(), $source->isLandscape());
		$this->assertSame($size->isPortrait(), $source->isPortrait());
		$this->assertSame($size->isSquare(), $source->isSquare());
	}

	public function testSizeUnreadable(): void
	{
		$jpeg  = file_get_contents(self::FIXTURES . '/orientation/Landscape_6.jpg');
		$files = [
			'cut.jpg'       => substr($jpeg, 0, 200),
			'zero.gif'      => 'GIF89a' . pack('vv', 300, 0) . "\0\0\0",
			'zero.png'      => self::bare(0, 200),
			'signature.jpg' => "\xFF\xD8\xFF\xE0",
		];

		$sources = $this->invalid();

		foreach ($files as $name => $data) {
			$file      = $this->file($name, $data);
			$sources[] = new Source($file);
		}

		foreach ($sources as $source) {
			$this->assertEquals(new Size(0, 0), $source->size());
		}
	}
}
