<?php

namespace Darkroom\Tests\Formats;

use Darkroom\Drivers\Vips;
use Darkroom\Format;
use Darkroom\Formats\Reader;
use Darkroom\Formats\Writer;
use Imagick;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Writer::class)]
class WriterTest extends FormatTestCase
{
	protected static function embedded(string $data): string
	{
		$format = Format::detect($data);
		$class  = Reader::for($format);
		$handle = fopen('php://memory', 'w+b');

		fwrite($handle, $data);
		rewind($handle);

		$reader = new $class($handle);

		return $reader->icc();
	}

	protected static function encode(
		string $encoder,
		bool $alpha = false,
		int ...$options
	): string {
		$image  = imagecreatetruecolor(30, 20);
		$stream = fopen('php://memory', 'w+b');

		imagealphablending($image, false);
		imagesavealpha($image, $alpha);

		for ($y = 0; $y < 20; $y++) {
			for ($x = 0; $x < 30; $x++) {
				$opacity = $alpha === true ? $y * 6 << 24 : 0;

				imagesetpixel($image, $x, $y, $opacity | $x * 8 << 16 | 0x4080);
			}
		}

		$encoder($image, $stream, ...$options);
		rewind($stream);

		return stream_get_contents($stream);
	}

	protected static function gd(string $data): string
	{
		$image  = imagecreatefromstring($data);
		$stream = fopen('php://memory', 'w+b');

		imagesavealpha($image, true);
		imagepng($image, $stream);
		rewind($stream);

		return stream_get_contents($stream);
	}

	protected static function pad(string $profile, int $bytes): string
	{
		$length = pack('N', strlen($profile) + $bytes);

		return substr_replace($profile . str_repeat("\0", $bytes), $length, 0, 4);
	}

	public static function embedProvider(): array
	{
		$p3    = file_get_contents(self::FIXTURES . '/p3.icc');
		$large = self::pad($p3, 150_000);
		$odd   = self::pad($p3, 1);
		$jpeg  = self::encode('imagejpeg');

		$app0  = 2 + unpack('n', $jpeg, 4)[1];
		$plain = substr_replace($jpeg, '', 2, $app0);

		return [
			'jpeg'                 => [$jpeg, $p3],
			'jpeg, no jfif'        => [$plain, $p3],
			'jpeg, segments'       => [$jpeg, $large],
			'png'                  => [self::encode('imagepng'), $p3],
			'png, alpha'           => [self::encode('imagepng', true), $large],
			'webp'                 => [self::encode('imagewebp'), $p3],
			'webp, alpha'          => [self::encode('imagewebp', true), $p3],
			'webp, lossless'       => [self::encode('imagewebp', false, IMG_WEBP_LOSSLESS), $p3],
			'webp, lossless alpha' => [self::encode('imagewebp', true, IMG_WEBP_LOSSLESS), $p3],
			'webp, odd'            => [self::encode('imagewebp'), $odd],
		];
	}

	#[DataProvider('embedProvider')]
	public function testEmbed(string $data, string $profile): void
	{
		$format   = Format::detect($data);
		$embedded = Writer::embed($data, $format, icc: $profile);
		$found    = self::embedded($embedded);
		$expected = self::gd($data);
		$actual   = self::gd($embedded);

		$this->assertSame($profile, $found);
		$this->assertSame($expected, $actual);
	}

	#[DataProvider('embedProvider')]
	public function testEmbedImagick(string $data, string $profile): void
	{
		if (extension_loaded('imagick') === false) {
			$this->markTestSkipped('Imagick not available');
		}

		$format   = Format::detect($data);
		$embedded = Writer::embed($data, $format, icc: $profile);
		$image    = new Imagick();
		$plain    = new Imagick();

		$image->readImageBlob($embedded);
		$plain->readImageBlob($data);

		$this->assertSame($profile, $image->getImageProfile('icc'));
		$this->assertSame($plain->getImageSignature(), $image->getImageSignature());
	}

	public function testEmbedJpeg(): void
	{
		$p3       = file_get_contents(self::FIXTURES . '/p3.icc');
		$large    = self::pad($p3, 150_000);
		$jpeg     = self::encode('imagejpeg');
		$embedded = Writer::embed($jpeg, Format::Jpeg, icc: $large);

		$app0  = 2 + unpack('n', $jpeg, 4)[1];
		$first = substr($embedded, 2 + $app0, 18);

		$this->assertSame("\xFF\xE2\xFF\xFFICC_PROFILE\0\x01\x03", $first);
		$this->assertSame(3, substr_count($embedded, 'ICC_PROFILE'));
	}

	public function testEmbedNone(): void
	{
		$p3     = file_get_contents(self::FIXTURES . '/p3.icc');
		$gif    = self::encode('imagegif');
		$avif   = self::fixture('heif/plain.avif');

		$this->assertSame($gif, Writer::embed($gif, Format::Gif, icc: $p3));
		$this->assertSame($avif, Writer::embed($avif, Format::Avif, icc: $p3));
	}

	#[DataProvider('embedProvider')]
	public function testEmbedReplace(string $data, string $profile): void
	{
		$format   = Format::detect($data);
		$other    = self::icc(200);
		$embedded = Writer::embed($data, $format, icc: $other);

		$replaced = Writer::embed($embedded, $format, icc: $profile);
		$removed  = Writer::embed($embedded, $format, icc: '');

		$this->assertSame($profile, self::embedded($replaced));
		$this->assertSame('', self::embedded($removed));
		$this->assertSame(self::gd($data), self::gd($removed));
	}

	#[DataProvider('embedProvider')]
	public function testEmbedVips(string $data, string $profile): void
	{
		$vips = new Vips();

		if ($vips->isAvailable() === false) {
			$this->markTestSkipped('libvips not available');
		}

		$format   = Format::detect($data);
		$embedded = Writer::embed($data, $format, icc: $profile);
		$image    = Image::newFromBuffer($embedded);
		$plain    = Image::newFromBuffer($data);

		$this->assertSame($profile, $image->get('icc-profile-data'));
		$this->assertSame($plain->writeToMemory(), $image->writeToMemory());
	}

	public function testEmbedWebp(): void
	{
		$p3       = file_get_contents(self::FIXTURES . '/p3.icc');
		$lossless = self::encode('imagewebp', true, IMG_WEBP_LOSSLESS);
		$lossy    = self::encode('imagewebp');

		$embedded = Writer::embed($lossless, Format::Webp, icc: $p3);
		$riff     = unpack('V', $embedded, 4)[1];

		$this->assertSame('VP8L', substr($lossless, 12, 4));
		$this->assertSame('VP8X', substr($embedded, 12, 4));
		$this->assertSame("\x30\0\0\0\x1D\0\0\x13\0\0", substr($embedded, 20, 10));
		$this->assertSame('ICCP', substr($embedded, 30, 4));
		$this->assertSame(strlen($embedded) - 8, $riff);

		$embedded = Writer::embed($lossy, Format::Webp, icc: $p3);

		$this->assertSame('VP8 ', substr($lossy, 12, 4));
		$this->assertSame("\x20\0\0\0\x1D\0\0\x13\0\0", substr($embedded, 20, 10));
	}
}
