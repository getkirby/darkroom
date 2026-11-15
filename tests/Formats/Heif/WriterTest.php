<?php

namespace Darkroom\Tests\Formats\Heif;

use Darkroom\Formats\Heif\Iloc;
use Darkroom\Formats\Heif\Items;
use Darkroom\Formats\Heif\Meta;
use Darkroom\Formats\Heif\Writer;
use Darkroom\Meta\Exif;
use Darkroom\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Writer::class)]
#[CoversClass(Iloc::class)]
#[CoversClass(Items::class)]
#[CoversClass(Meta::class)]
class WriterTest extends HeifTestCase
{
	public function testStrip(): void
	{
		$tiff     = self::tiff(6);
		$exif     = pack('N', 0) . $tiff;
		$xmp      = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF/></x:xmpmeta>';
		$none     = self::tiff([]);
		$empty    = str_pad(pack('N', 0) . $none, strlen($exif), "\0");
		$packet   = str_pad('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>', strlen($xmp), ' ');
		$image    = self::infe(1, 'hvc1');
		$item     = self::infe(2, 'Exif');
		$mime     = self::infe(3, "mime\0application/rdf+xml\0");
		$iinf     = self::iinf([$image, $item, $mime]);
		$iloc     = self::iloc([[2, self::DATA, strlen($exif)], [3, self::DATA + strlen($exif), strlen($xmp)]]);
		$data     = self::item($exif . $xmp, $iloc, iinf: $iinf);
		$expected = self::item($empty . $packet, $iloc, iinf: $iinf);

		$stripped = self::strip($data . 'trailing');

		$this->assertSame($expected, $stripped);
		$this->assertStringStartsWith($none, self::exif($stripped));
	}

	public function testStripAuxiliary(): void
	{
		// the depth map's XMP stays, the image's goes
		$xmp      = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF/></x:xmpmeta>';
		$packet   = str_pad('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>', strlen($xmp), ' ');
		$image    = self::infe(1, 'hvc1');
		$own      = self::infe(2, "mime\0application/rdf+xml\0");
		$depth    = self::infe(3, 'hvc1');
		$map      = self::infe(4, "mime\0application/rdf+xml\0");
		$iinf     = self::iinf([$image, $own, $depth, $map]);
		$auxl     = self::reference('auxl', 3, [1]);
		$first    = self::reference('cdsc', 2, [1]);
		$second   = self::reference('cdsc', 4, [3]);
		$iref     = self::iref([$auxl, $first, $second]);
		$iloc     = self::iloc([[2, self::DATA, strlen($xmp)], [4, self::DATA + strlen($xmp), strlen($xmp)]]);
		$data     = self::item($xmp . $xmp, $iloc, $iref, $iinf);
		$expected = self::item($packet . $xmp, $iloc, $iref, $iinf);

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	public function testStripBoxes(): void
	{
		$ftyp     = self::ftyp('heic', 'mif1', 'heic');
		$uuid     = self::box('uuid', str_repeat("\xD8", 16) . 'manifest');
		$free     = self::box('free', str_repeat("\0", 24));
		$video    = self::large('mpvd', 'video');
		$blank    = self::large('free', str_repeat("\0", 5));
		$mdat     = self::box('mdat', 'pixels');
		$pitm     = self::pitm(1);
		$meta     = self::meta($pitm);
		$data     = $ftyp . $uuid . $video . $mdat . $meta . $video;
		$expected = $ftyp . $free . $blank . $mdat . $meta;

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	public function testStripExtents(): void
	{
		$exif     = pack('N', 0) . self::tiff(6);
		$none     = pack('N', 0) . self::tiff([]);
		$first    = str_pad($none, 20, "\0");
		$second   = str_repeat("\0", strlen($exif) - 20);
		$iloc     = self::box('iloc', pack('Cxxxn', 0, 0x4400) . pack('nnnn', 1, 2, 0, 2) . pack('NN', self::DATA, 20) . pack('NN', self::DATA + 20, strlen($exif) - 20));
		$data     = self::item($exif, $iloc);
		$expected = self::item($first . $second, $iloc);

		$stripped = self::strip($data);

		$this->assertSame($expected, $stripped);
	}

	public static function stripFixtureProvider(): array
	{
		return [
			'avif'    => ['heif/exif.avif'],
			'grid'    => ['heif/grid.heic'],
			'heic'    => ['heif/exif.heic'],
			'rotated' => ['heif/exif-irot-2.avif'],
		];
	}

	#[DataProvider('stripFixtureProvider')]
	public function testStripFixture(string $name): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);
		$again    = self::strip($stripped);
		$exif     = self::exif($stripped);
		$handle   = self::handle($exif);

		$this->assertEquals(self::read($data), self::read($stripped));
		$this->assertSame(self::cicp($data), self::cicp($stripped));
		$this->assertSame(Orientation::None, Exif::orientation($handle));
		$this->assertStringStartsWith(self::tiff([]), $exif);
		$this->assertSame(strlen($data), strlen($stripped));
		$this->assertSame($stripped, $again);
	}

	public function testStripGarbage(): void
	{
		$data = self::fixture('heif/exif.heic');

		$this->assertStrips($data, 28);
	}

	public function testStripIdat(): void
	{
		$tiff     = self::tiff(6);
		$exif     = pack('N', 0) . $tiff;
		$none     = pack('N', 0) . self::tiff([]);
		$blank    = str_pad($none, strlen($exif), "\0");
		$image    = self::infe(1, 'hvc1');
		$item     = self::infe(2, 'Exif');
		$iinf     = self::iinf([$image, $item]);
		$iloc     = self::iloc([[2, 3, strlen($exif), 0, 1]], version: 1);
		$cdsc     = self::reference('cdsc', 2, [1]);
		$iref     = self::iref([$cdsc]);
		$pitm     = self::pitm(1);
		$idat     = self::box('idat', 'abc' . $exif);
		$blanked  = self::box('idat', 'abc' . $blank);
		$data     = self::heif($pitm, $iinf, $iref, $iloc, $idat);
		$expected = self::heif($pitm, $iinf, $iref, $iloc, $blanked);

		$stripped = self::strip($data);

		$this->assertSame($tiff, self::exif($data));
		$this->assertSame($expected, $stripped);
	}

	public function testStripTags(): void
	{
		$data = self::fixture('heif/exif.heic');
		$tiff = self::exif($data);
		$tags = [0x010F];
		$kept = Exif::filter($tiff, $tags);

		$stripped = self::strip($data, $tags);

		// in place, without an orientation, as a property has it
		$this->assertSame(strlen($data), strlen($stripped));
		$this->assertStringStartsWith($kept, self::exif($stripped));
		$this->assertEquals(self::read($data), self::read($stripped));
	}

	public function testStripTagsTooLong(): void
	{
		$ftyp   = self::ftyp('heic', 'mif1', 'heic');
		$pitm   = self::pitm(1);
		$image  = self::infe(1, 'hvc1');
		$exif   = self::infe(2, "Exif\0");
		$cdsc   = self::reference('cdsc', 2, [1]);
		$iref   = self::iref([$cdsc]);
		$small  = pack('N', 0) . self::tiff([[0x0100, 4, 30]]);
		$camera = self::tiff([[0x0100, 4, 30], [0x0101, 4, 20], [0x0112, 3, 6]]);
		$tags   = [0x0100, 0x0101];
		$iinf   = self::iinf([$image, $exif]);

		// the size first, as the offsets depend on it
		$length = strlen(self::meta($pitm, $iinf, self::iloc([[1, 0, 6], [2, 0, 0]]), $iref));
		$pixel  = strlen($ftyp) + $length + 8;
		$iloc   = self::iloc([[1, $pixel, 6], [2, $pixel + 6, strlen($small)]]);
		$data   = $ftyp . self::meta($pitm, $iinf, $iloc, $iref) . self::box('mdat', 'pixels' . $small);

		$stripped = self::strip($data, $tags, $camera);

		// the image's own item, too small for the tags, blanked
		$this->assertSame(strlen($data), strlen($stripped));
		$this->assertStringStartsWith(self::tiff([]), self::exif($stripped));
	}

	public static function stripUnchangedProvider(): array
	{
		return [
			['heif/animated.heic'],
			['heif/plain.avif'],
			['heif/plain.heic'],
		];
	}

	#[DataProvider('stripUnchangedProvider')]
	public function testStripUnchanged(string $name): void
	{
		$data = self::fixture($name);

		$stripped = self::strip($data);

		$this->assertSame($data, $stripped);
	}

	public function testWriteWithoutItem(): void
	{
		$data   = self::fixture('heif/plain.heic');
		$tiff   = self::tiff([[0x0100, 4, 30]]);

		$written = self::write($data, $tiff);

		// without an item of its own, as for a thumb
		$this->assertSame($data, $written);
	}
}
