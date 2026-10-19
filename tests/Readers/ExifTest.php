<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Orientation;
use Darkroom\Readers\Exif;
use Darkroom\Readers\Header;
use Darkroom\Readers\Reader;
use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(Exif::class)]
class ExifTest extends ReaderTestCase
{
	protected const string READER = Exif::class;

	public function testMarker(): void
	{
		$tiff   = self::tiff(6);
		$handle = self::handle("Exif\0\0" . $tiff);

		$this->assertTrue(Exif::marker($handle));
		$this->assertSame(6, ftell($handle));

		$handle = self::handle('abc' . $tiff);
		fseek($handle, 3);

		$this->assertFalse(Exif::marker($handle));
		$this->assertSame(3, ftell($handle));

		$handle = self::handle('Exif');

		$this->assertFalse(Exif::marker($handle));
	}

	public static function readProvider(): array
	{
		return [
			'big-endian' => [
				self::tiff(6),
				[0, 0, Orientation::Rotate90]
			],
			'little-endian' => [
				self::tiff(6, little: true),
				[0, 0, Orientation::Rotate90]
			],
			'size as SHORT' => [
				self::tiff([[0x0100, 3, 1800], [0x0101, 3, 1200], [0x0112, 3, 3]]),
				[1800, 1200, Orientation::Rotate180]
			],
			'size as LONG' => [
				self::tiff([[0x0100, 4, 70000], [0x0101, 4, 1200]], little: true),
				[70000, 1200, Orientation::None]
			],
			'orientation as LONG' => [
				self::tiff([[0x0112, 4, 8]]),
				[0, 0, Orientation::Rotate270]
			],
			'other types' => [
				self::tiff([[0x0100, 1, 1800], [0x0112, 2, 6]]),
				[0, 0, Orientation::None]
			],
			'other tags' => [
				self::tiff([[0x0103, 3, 6], [0x0128, 3, 2]]),
				[0, 0, Orientation::None]
			],
			'no entries' => [
				self::tiff([]),
				[0, 0, Orientation::None]
			],
			'after the marker' => [
				"Exif\0\0" . self::tiff(6),
				[0, 0, Orientation::None]
			],
		];
	}

	#[DataProvider('readProvider')]
	public function testRead(string $data, array $expected): void
	{
		[$width, $height, $orientation] = $expected;

		$size   = new Size($width, $height);
		$header = new Header($size, $orientation);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadEveryOrientation(): void
	{
		foreach (Orientation::cases() as $orientation) {
			foreach ([false, true] as $little) {
				$data = self::tiff($orientation->value, $little);

				$this->assertSame($orientation, self::read($data)->orientation);
			}
		}
	}

	public function testReadFromPosition(): void
	{
		$tiff   = self::tiff(6);
		$ifd    = substr($tiff, 8);
		$data   = "MM\0*" . pack('N', 20) . str_repeat("\0", 12) . $ifd;
		$handle = self::handle("Exif\0\0" . $data);

		fseek($handle, 6);

		$this->assertSame(Orientation::Rotate90, Exif::read($handle)->orientation);
	}

	public function testReadGarbage(): void
	{
		$engine = new Mt19937(42);
		$random = new Randomizer($engine);

		foreach (["II*\0", "MM\0*"] as $order) {
			for ($run = 0; $run < 500; $run++) {
				$length = $random->getInt(1, 200);
				$data   = $order . $random->getBytes($length);
				$header = self::read($data);

				$this->assertGreaterThanOrEqual(0, $header->size->width);
				$this->assertGreaterThanOrEqual(0, $header->size->height);
			}
		}
	}

	public static function readInvalidProvider(): array
	{
		return [
			'empty'       => [''],
			'no TIFF'     => ['Exif'],
			'marker only' => ["Exif\0\0"],
			'BigTIFF'     => ["II+\0" . str_repeat("\0", 12)],
			'header only' => ["MM\0*"],
			'outside'     => ["MM\0*\xFF\xFF\xFF\xFF"],
			'no entries'  => ["II*\0\x08\0\0\0"],
			'too many'    => ["MM\0*\0\0\0\x08\xFF\xFF\x01\x12\0\x03\0\0\0\x01"],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadInvalidOrientation(): void
	{
		foreach ([0, 9, 0xFFFF] as $value) {
			$data = self::tiff($value);

			$this->assertSame(Orientation::None, self::read($data)->orientation);
		}
	}

	public function testReadLimit(): void
	{
		$entries = array_fill(0, Reader::STEPS - 1, [0x0103, 3, 1]);
		$last    = self::tiff([...$entries, [0x0112, 3, 6]]);
		$beyond  = self::tiff([...$entries, [0x0103, 3, 1], [0x0112, 3, 6]]);

		$this->assertSame(Orientation::Rotate90, self::read($last)->orientation);
		$this->assertSame(Orientation::None, self::read($beyond)->orientation);
	}

	public function testReadTruncated(): void
	{
		$data = self::tiff([[0x0100, 3, 1800], [0x0101, 3, 1200], [0x0112, 3, 6]]);

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated   = substr($data, 0, $length);
			$header      = self::read($truncated);
			$entries     = max(0, intdiv($length - 10, 12));
			$orientation = $entries >= 3 ? Orientation::Rotate90 : Orientation::None;

			$this->assertSame($entries >= 1 ? 1800 : 0, $header->size->width);
			$this->assertSame($entries >= 2 ? 1200 : 0, $header->size->height);
			$this->assertSame($orientation, $header->orientation);
		}
	}

	#[RequiresFunction('exif_read_data')]
	public function testTags(): void
	{
		$data = self::tiff([[0x0112, 3, 6], [0x0100, 4, 1800]]);
		$tags = Exif::tags($data);

		$this->assertSame(['IFD0'], array_keys($tags));
		$this->assertSame(6, $tags['IFD0']['Orientation']);
		$this->assertSame(1800, $tags['IFD0']['ImageWidth']);
	}

	#[RequiresFunction('exif_read_data')]
	public function testTagsNone(): void
	{
		$this->assertSame([], Exif::tags(''));

		$empty = self::tiff([]);

		$this->assertSame([], Exif::tags("MM\0*\0\0\0\x08"));
		$this->assertSame([], Exif::tags($empty));
	}
}
