<?php

namespace Darkroom\Tests\Meta;

use Darkroom\Containers\Bytes;
use Darkroom\Formats\Jpeg\Reader as Jpeg;
use Darkroom\Meta\Exif;
use Darkroom\Orientation;
use Darkroom\Tests\Formats\FormatTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(Exif::class)]
class ExifTest extends FormatTestCase
{
	/**
	 * The EXIF data of a camera's JPEG: little-endian, with make,
	 * model and orientation, exposure and date, GPS and a thumbnail
	 */
	protected static function camera(): string
	{
		$data   = self::fixture('exif.jpg');
		$handle = self::handle($data);

		$jpeg = new Jpeg($handle);

		return $jpeg->exif();
	}

	protected static function orientation(string $data): Orientation
	{
		$handle = self::handle($data);

		return Exif::orientation($handle);
	}

	public function testFilter(): void
	{
		$tiff     = self::tiff([[0x0112, 3, 6], [0x0100, 4, 1800], [0x0101, 4, 1200]]);
		$expected = self::tiff([[0x0101, 4, 1200], [0x0112, 3, 8]]);

		$filtered = Exif::filter($tiff, [0x0101], Orientation::Rotate270);

		$this->assertSame($expected, $filtered);
	}

	public function testFilterGarbage(): void
	{
		$tiff   = self::camera();
		$engine = new Mt19937(42);
		$random = new Randomizer($engine);
		$tags   = [0x010F, 0x9003, 1, 2];

		for ($run = 0; $run < 300; $run++) {
			$changed = $tiff;

			for ($change = 0; $change < 8; $change++) {
				$offset           = $random->getInt(4, strlen($tiff) - 1);
				$changed[$offset] = $random->getBytes(1);
			}

			$filtered = Exif::filter($changed, $tags, Orientation::Rotate90);

			$this->assertLessThan(strlen($tiff), strlen($filtered));
			$this->assertSame(Orientation::Rotate90, self::orientation($filtered));
		}
	}

	public function testFilterMemory(): void
	{
		// 5,000 tags, each an undefined value of 4,000 bytes at the same offset
		$tiff = "MM\0*" . pack('Nn', 8, 5000);

		for ($tag = 1; $tag <= 5000; $tag++) {
			$tiff .= pack('nnNN', $tag, 7, 4000, 60014);
		}

		$tiff .= pack('N', 0) . str_repeat('x', 4000);

		memory_reset_peak_usage();
		$memory = memory_get_usage();

		$filtered = Exif::filter($tiff, [0x8298]);
		$peak     = memory_get_peak_usage() - $memory;

		$this->assertSame('', $filtered);
		$this->assertLessThan(1_000_000, $peak);
	}

	public function testFilterNone(): void
	{
		$tiff = self::camera();
		$six  = self::tiff(6);

		$this->assertSame('', Exif::filter($tiff, []));
		$this->assertSame('', Exif::filter('', [0x010F]));
		$this->assertSame('', Exif::filter($tiff, [0x0103]));
		$this->assertSame($six, Exif::filter('other', [], Orientation::Rotate90));
	}

	public function testFilterPointer(): void
	{
		// the EXIF IFD after IFD0, by an offset of type IFD
		$ifd      = pack('n', 1) . pack('nnNnx2', 0xA405, 3, 1, 50) . pack('N', 0);
		$tiff     = self::tiff([[0x8769, 13, 26]]) . $ifd;
		$long     = self::tiff([[0x8769, 4, 26]]) . $ifd;
		$expected = Exif::filter($long, [0xA405]);

		$filtered = Exif::filter($tiff, [0xA405]);

		$this->assertNotSame('', $expected);
		$this->assertSame($expected, $filtered);
	}

	#[RequiresFunction('exif_read_data')]
	public function testFilterTags(): void
	{
		$tiff     = self::camera();
		$original = Exif::tags($tiff);
		$tags     = [0x010F, 0x0110, 0x829A, 0x9003];

		$filtered = Exif::filter($tiff, $tags);
		$kept     = Exif::tags($filtered);

		$this->assertStringStartsWith("II*\0", $filtered);
		$this->assertSame(['IFD0', 'EXIF'], array_keys($kept));
		$this->assertSame($original['IFD0']['Make'], $kept['IFD0']['Make']);
		$this->assertSame($original['IFD0']['Model'], $kept['IFD0']['Model']);
		$this->assertSame($original['EXIF']['ExposureTime'], $kept['EXIF']['ExposureTime']);
		$this->assertSame($original['EXIF']['DateTimeOriginal'], $kept['EXIF']['DateTimeOriginal']);
		$this->assertSame(['ExposureTime', 'ExifVersion', 'DateTimeOriginal'], array_keys($kept['EXIF']));

		$location = Exif::filter($tiff, range(0, 0x1F));
		$kept     = Exif::tags($location);

		// IFD0 with only the pointer to GPS
		$this->assertSame(['GPS'], array_keys($kept));
		$this->assertSame($original['GPS'], $kept['GPS']);
	}

	public function testFilterUtf8(): void
	{
		// EXIF 3.0's type, here a byte in the entry
		$tiff = self::tiff([[0x010E, 129, 0x41000000]]);

		$filtered = Exif::filter($tiff, [0x010E]);

		$this->assertSame($tiff, $filtered);
	}

	#[RequiresFunction('exif_read_data')]
	public function testFilterVersion(): void
	{
		$tiff = self::camera();

		$time  = Exif::filter($tiff, [0x9003]);
		$plain = Exif::filter($tiff, [0x010F]);

		// with the version, as an EXIF IFD needs it
		$kept  = Exif::tags($time);
		$other = Exif::tags($plain);

		$this->assertSame(['ExifVersion', 'DateTimeOriginal'], array_keys($kept['EXIF']));
		$this->assertArrayNotHasKey('EXIF', $other);
	}

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

	public static function orientationProvider(): array
	{
		return [
			'big-endian'       => [self::tiff(6), Orientation::Rotate90],
			'little-endian'    => [self::tiff(6, little: true), Orientation::Rotate90],
			'among other tags' => [
				self::tiff([[0x0100, 3, 1800], [0x0101, 3, 1200], [0x0112, 3, 3]]),
				Orientation::Rotate180
			],
			'as LONG'          => [self::tiff([[0x0112, 4, 8]]), Orientation::Rotate270],
			'other types'      => [self::tiff([[0x0100, 1, 1800], [0x0112, 2, 6]]), Orientation::None],
			'other tags'       => [self::tiff([[0x0103, 3, 6], [0x0128, 3, 2]]), Orientation::None],
			'no entries'       => [self::tiff([]), Orientation::None],
			'after the marker' => ["Exif\0\0" . self::tiff(6), Orientation::Rotate90],
		];
	}

	#[DataProvider('orientationProvider')]
	public function testOrientation(string $data, Orientation $expected): void
	{
		$this->assertSame($expected, self::orientation($data));
	}

	public function testOrientationEveryValue(): void
	{
		foreach (Orientation::cases() as $orientation) {
			foreach ([false, true] as $little) {
				$data = self::tiff($orientation->value, $little);

				$this->assertSame($orientation, self::orientation($data));
			}
		}
	}

	public function testOrientationFromPosition(): void
	{
		$tiff   = self::tiff(6);
		$ifd    = substr($tiff, 8);
		$data   = "MM\0*" . pack('N', 20) . str_repeat("\0", 12) . $ifd;
		$handle = self::handle("Exif\0\0" . $data);

		fseek($handle, 6);

		$this->assertSame(Orientation::Rotate90, Exif::orientation($handle));
	}

	public function testOrientationGarbage(): void
	{
		$engine = new Mt19937(42);
		$random = new Randomizer($engine);

		foreach (["II*\0", "MM\0*"] as $order) {
			for ($run = 0; $run < 500; $run++) {
				$length = $random->getInt(1, 200);
				$data   = $order . $random->getBytes($length);

				$this->assertInstanceOf(Orientation::class, self::orientation($data));
			}
		}
	}

	public static function orientationInvalidProvider(): array
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

	#[DataProvider('orientationInvalidProvider')]
	public function testOrientationInvalid(string $data): void
	{
		$this->assertSame(Orientation::None, self::orientation($data));
	}

	public function testOrientationInvalidValue(): void
	{
		foreach ([0, 9, 0xFFFF] as $value) {
			$data = self::tiff($value);

			$this->assertSame(Orientation::None, self::orientation($data));
		}
	}

	public function testOrientationLimit(): void
	{
		$entries = array_fill(0, Bytes::STEPS - 1, [0x0103, 3, 1]);
		$last    = self::tiff([...$entries, [0x0112, 3, 6]]);
		$beyond  = self::tiff([...$entries, [0x0103, 3, 1], [0x0112, 3, 6]]);

		$this->assertSame(Orientation::Rotate90, self::orientation($last));
		$this->assertSame(Orientation::None, self::orientation($beyond));
	}

	public function testOrientationTruncated(): void
	{
		$data = self::tiff([[0x0100, 3, 1800], [0x0101, 3, 1200], [0x0112, 3, 6]]);

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);
			$entries   = max(0, intdiv($length - 10, 12));
			$expected  = $entries >= 3 ? Orientation::Rotate90 : Orientation::None;

			$this->assertSame($expected, self::orientation($truncated));
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

	public function testTiff(): void
	{
		$tiff   = self::tiff(6);
		$length = strlen($tiff);
		$after  = self::handle($tiff . 'abc');
		$marker = self::handle("Exif\0\0" . $tiff);
		$empty  = self::handle($tiff);

		$this->assertSame($tiff, Exif::tiff($after, $length));
		$this->assertSame($tiff, Exif::tiff($marker, $length + 6));
		$this->assertSame('', Exif::tiff($empty, 0));

		$none    = self::handle(pack('N', 0) . $tiff);
		$skipped = self::handle(pack('N', 6) . "Exif\0\0" . $tiff);
		$marker  = self::handle(pack('N', 0) . "Exif\0\0" . $tiff);
		$beyond  = self::handle(pack('N', 100) . $tiff);
		$huge    = self::handle(pack('N', 0xFFFFFFFF) . $tiff);

		$this->assertSame($tiff, Exif::tiff($none, $length + 4, offset: true));
		$this->assertSame($tiff, Exif::tiff($skipped, $length + 10, offset: true));
		$this->assertSame($tiff, Exif::tiff($marker, $length + 10, offset: true));
		$this->assertSame('', Exif::tiff($beyond, $length + 4, offset: true));
		$this->assertSame('', Exif::tiff($huge, PHP_INT_MAX, offset: true));

		$data   = str_repeat('a', 2_000_000);
		$handle = self::handle($data);
		$bytes  = Exif::tiff($handle, PHP_INT_MAX);

		$this->assertSame(1_048_576, strlen($bytes));
	}
}
