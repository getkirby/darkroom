<?php

namespace Darkroom\Tests;

use Darkroom\Format;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Format::class)]
class FormatTest extends TestCase
{
	public static function detectProvider(): array
	{
		return [
			'avif'             => [self::ftyp('avif', 'mif1', 'miaf'), Format::Avif],
			'avif, animated'   => [self::ftyp('avis', 'msf1', 'avif'), Format::Avif],
			'avif, compatible' => [self::ftyp('mif1', 'mif1', 'avif'), Format::Avif],
			'gif87a'           => ['GIF87a', Format::Gif],
			'gif89a'           => ['GIF89a', Format::Gif],
			'heic'             => [self::ftyp('heic', 'mif1', 'heic'), Format::Heic],
			'heic, major only' => [self::ftyp('heic'), Format::Heic],
			'heic, sequence'   => [self::ftyp('msf1', 'msf1', 'hevc'), Format::Heic],
			'heif'             => [self::ftyp('mif1', 'mif1'), Format::Heic],
			'jpeg'             => ["\xFF\xD8\xFF\xE0", Format::Jpeg],
			'jxl'              => ["\xFF\x0A\xFA\x1F", Format::Jxl],
			'jxl, container'   => ["\0\0\0\x0CJXL \r\n\x87\n\0\0\0\x14ftypjxl ", Format::Jxl],
			'png'              => ["\x89PNG\r\n\x1A\n\0\0\0\x0DIHDR", Format::Png],
			'webp'             => ["RIFF\x24\0\0\0WEBPVP8 ", Format::Webp],
			'empty'            => ['', null],
			'text'             => ['Hello', null],
			'svg'              => ['<svg xmlns="http://www.w3.org/2000/svg"/>', null],
			'mp4'              => ["\0\0\0\x18ftypisom\0\0\x02\0isommp41", null],
			'wav'              => ["RIFF\x24\0\0\0WAVEfmt ", null],
			'jpeg, cut'        => ["\xFF\xD8", null],
			'tiff'             => ["II*\0\x08\0\0\0", null],
			'bmp'              => ["BM\x8A\0\0\0\0\0\0\0\x8A\0\0\0|\0\0\0", null],
		];
	}

	#[DataProvider('detectProvider')]
	public function testDetect(string $bytes, Format|null $format): void
	{
		$this->assertSame($format, Format::detect($bytes));
	}

	public function testExtension(): void
	{
		$this->assertSame('avif', Format::Avif->extension());
		$this->assertSame('gif', Format::Gif->extension());
		$this->assertSame('heic', Format::Heic->extension());
		$this->assertSame('jpg', Format::Jpeg->extension());
		$this->assertSame('jxl', Format::Jxl->extension());
		$this->assertSame('png', Format::Png->extension());
		$this->assertSame('webp', Format::Webp->extension());
	}

	public static function fromProvider(): array
	{
		return [
			'avif'        => ['avif', Format::Avif],
			'image/avif'  => ['image/avif', Format::Avif],
			'gif'         => ['gif', Format::Gif],
			'image/gif'   => ['image/gif', Format::Gif],
			'heic'        => ['heic', Format::Heic],
			'heif'        => ['heif', Format::Heic],
			'image/heic'  => ['image/heic', Format::Heic],
			'image/heif'  => ['image/heif', Format::Heic],
			'jpg'         => ['jpg', Format::Jpeg],
			'jpeg'        => ['jpeg', Format::Jpeg],
			'jpe'         => ['jpe', Format::Jpeg],
			'image/jpeg'  => ['image/jpeg', Format::Jpeg],
			'image/pjpeg' => ['image/pjpeg', Format::Jpeg],
			'jxl'         => ['jxl', Format::Jxl],
			'image/jxl'   => ['image/jxl', Format::Jxl],
			'png'         => ['png', Format::Png],
			'apng'        => ['apng', Format::Png],
			'image/png'   => ['image/png', Format::Png],
			'image/apng'  => ['image/apng', Format::Png],
			'webp'        => ['webp', Format::Webp],
			'image/webp'  => ['image/webp', Format::Webp],
			'upper case'  => ['JPG', Format::Jpeg],
			'mixed case'  => ['Image/WebP', Format::Webp],
		];
	}

	#[DataProvider('fromProvider')]
	public function testFrom(string $value, Format $format): void
	{
		$this->assertSame($format, Format::from($value));
	}

	public static function fromInvalidProvider(): array
	{
		return [
			'empty'    => [''],
			'dot'      => ['.jpg'],
			'bmp'      => ['bmp'],
			'jp2'      => ['jp2'],
			'tif'      => ['tif'],
			'svg'      => ['svg'],
			'svg type' => ['image/svg+xml'],
			'wildcard' => ['image/*'],
		];
	}

	#[DataProvider('fromInvalidProvider')]
	public function testFromInvalid(string $value): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown image format: ' . $value);

		Format::from($value);
	}

	public function testIsOpaque(): void
	{
		$opaque = array_filter(Format::cases(), fn (Format $format): bool => $format->isOpaque());

		$this->assertSame([Format::Jpeg], array_values($opaque));
	}

	public function testMime(): void
	{
		$this->assertSame('image/avif', Format::Avif->mime());
		$this->assertSame('image/gif', Format::Gif->mime());
		$this->assertSame('image/heic', Format::Heic->mime());
		$this->assertSame('image/jpeg', Format::Jpeg->mime());
		$this->assertSame('image/jxl', Format::Jxl->mime());
		$this->assertSame('image/png', Format::Png->mime());
		$this->assertSame('image/webp', Format::Webp->mime());
	}

	public function testViewable(): void
	{
		$hidden = array_filter(
			Format::cases(),
			fn (Format $format): bool => in_array($format, Format::VIEWABLE, true) === false
		);

		$this->assertSame([Format::Heic, Format::Jxl], array_values($hidden));
		$this->assertSame(Format::Webp, Format::VIEWABLE[0]);
	}
}
