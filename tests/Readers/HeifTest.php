<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Orientation;
use Darkroom\Readers\Exif;
use Darkroom\Readers\Header;
use Darkroom\Readers\Heif;
use Darkroom\Readers\Reader;
use Darkroom\Rect;
use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Heif::class)]
class HeifTest extends ReaderTestCase
{
	protected const int DATA = 32;
	protected const string READER = Heif::class;

	protected static function absurd(): string
	{
		$pitm = self::pitm(1);
		$iinf = self::box('iinf', pack('xxxxn', 0xFFFF));
		$iloc = self::box('iloc', pack('xxxxCCn', 0x44, 0, 0xFFFF) . pack('nnn', 1, 0, 0xFFFF));
		$cdsc = self::reference('cdsc', 2, [1], count: 0xFFFF);
		$iref = self::iref([$cdsc]);
		$ispe = self::ispe();
		$ipco = self::ipco($ispe);
		$ipma = self::box('ipma', pack('xxxxNnC', 0xFFFFFFFF, 1, 0xFF) . "\1");
		$iprp = self::iprp($ipco, $ipma);

		return self::heif($pitm, $iinf, $iloc, $iref, $iprp);
	}

	protected static function clap(int ...$values): string
	{
		return self::box('clap', pack('N8', ...$values));
	}

	protected static function colr(int $transfer): string
	{
		return self::box('colr', 'nclx' . pack('nnnC', 9, $transfer, 9, 0x80));
	}

	protected static function field(int $value, int $size): string
	{
		return match ($size) {
			0       => '',
			4       => pack('N', $value),
			default => pack('J', $value)
		};
	}

	protected static function heif(string ...$boxes): string
	{
		$ftyp = self::ftyp('heic', 'mif1', 'heic');
		$meta = self::meta(...$boxes);

		return $ftyp . $meta;
	}

	protected static function iinf(array $entries, int|null $count = null): string
	{
		$count ??= count($entries);

		return self::box('iinf', pack('xxxxn', $count) . implode('', $entries));
	}

	protected static function iloc(
		array $items,
		int $version = 0,
		int $sizes = 0x4400
	): string {
		$id   = $version < 2 ? 'n' : 'N';
		$data = pack('Cxxxn', $version, $sizes) . pack($id, count($items));

		foreach ($items as $item) {
			[$item, $offset, $length, $base, $method] = $item + [3 => 0, 4 => 0];

			$data .= pack($id, $item);
			$data .= $version > 0 ? pack('n', $method) : '';
			$data .= pack('n', 0);
			$data .= self::field($base, $sizes >> 4 & 15);
			$data .= pack('n', 1);
			$data .= self::field(0, $sizes & 15);
			$data .= self::field($offset, $sizes >> 12);
			$data .= self::field($length, $sizes >> 8 & 15);
		}

		return self::box('iloc', $data);
	}

	protected static function infe(
		int $item,
		string $type,
		int $version = 2
	): string {
		$id     = $version < 3 ? 'n' : 'N';
		$header = pack('Cxxx', $version) . pack($id, $item) . pack('n', 0);

		return self::box('infe', $header . $type);
	}

	protected static function ipco(string ...$properties): string
	{
		return self::box('ipco', implode('', $properties));
	}

	protected static function ipma(
		array $entries,
		int $version = 0,
		int $flags = 0
	): string {
		$id    = $version === 0 ? 'n' : 'N';
		$index = ($flags & 1) === 1 ? 'n*' : 'C*';
		$data  = pack('NN', $version << 24 | $flags, count($entries));

		foreach ($entries as $item => $indexes) {
			$data .= pack($id, $item) . chr(count($indexes)) . pack($index, ...$indexes);
		}

		return self::box('ipma', $data);
	}

	protected static function iprp(string ...$boxes): string
	{
		return self::box('iprp', implode('', $boxes));
	}

	protected static function iref(array $references, int $version = 0): string
	{
		return self::box('iref', pack('Cxxx', $version) . implode('', $references));
	}

	protected static function irot(int $turns): string
	{
		return self::box('irot', chr($turns));
	}

	protected static function ispe(int $width = 300, int $height = 200): string
	{
		return self::box('ispe', pack('xxxxNN', $width, $height));
	}

	protected static function item(
		string $data,
		string|null $iloc = null,
		string|null $iref = null,
		string|null $iinf = null
	): string {
		$ftyp   = self::ftyp('heic', 'mif1', 'heic');
		$mdat   = self::box('mdat', $data);
		$pitm   = self::pitm(1);
		$image  = self::infe(1, 'hvc1');
		$exif   = self::infe(2, 'Exif');
		$cdsc   = self::reference('cdsc', 2, [1]);
		$iinf ??= self::iinf([$image, $exif]);
		$iref ??= self::iref([$cdsc]);
		$iloc ??= self::iloc([[2, self::DATA, strlen($data)]]);
		$meta   = self::meta($pitm, $iinf, $iref, $iloc);

		return $ftyp . $mdat . $meta;
	}

	protected static function meta(string ...$boxes): string
	{
		return self::box('meta', pack('N', 0) . implode('', $boxes));
	}

	protected static function pad(string $box, int $bytes): string
	{
		return pack('N', strlen($box) + $bytes) . substr($box, 4) . str_repeat("\0", $bytes);
	}

	protected static function pitm(int $item, int $version = 0): string
	{
		$id = $version === 0 ? 'n' : 'N';

		return self::box('pitm', pack('Cxxx', $version) . pack($id, $item));
	}

	protected static function primary(string ...$properties): string
	{
		$ispe    = self::ispe();
		$indexes = range(1, count($properties) + 1);
		$pitm    = self::pitm(1);
		$ipco    = self::ipco($ispe, ...$properties);
		$ipma    = self::ipma([1 => $indexes]);
		$iprp    = self::iprp($ipco, $ipma);

		return self::heif($pitm, $iprp);
	}

	protected static function reference(
		string $type,
		int $from,
		array $to,
		int $version = 0,
		int|null $count = null
	): string {
		$id      = $version === 0 ? 'n' : 'N';
		$count ??= count($to);
		$data    = pack($id, $from) . pack('n', $count) . pack($id . '*', ...$to);

		return self::box($type, $data);
	}

	public static function canvasProvider(): array
	{
		return [
			'centred'       => [[50, 1, 40, 1, 0, 1, 0, 1], new Rect(7, 4, 50, 40)],
			'odd'           => [[51, 1, 41, 1, 0, 1, 0, 1], new Rect(6, 4, 51, 41)],
			'half offsets'  => [[25, 1, 15, 1, 1, 2, -1, 2], new Rect(20, 16, 25, 15)],
			'half sizes'    => [[101, 2, 81, 2, 0, 1, 0, 1], new Rect(6, 4, 51, 41)],
			'thirds'        => [[151, 3, 121, 3, 1, 3, -1, 3], new Rect(7, 4, 50, 40)],
			'sevenths'      => [[49, 1, 39, 1, 7, 3, -5, 7], new Rect(9, 4, 49, 39)],
			'thousandths'   => [
				[50_000, 1000, 40_000, 1000, 500, 1000, 250, 1000],
				new Rect(7, 4, 50, 40)
			],
			'one pixel'     => [[1, 1, 1, 1, 0, 1, 0, 1], new Rect(31, 24, 1, 1)],
			'right, bottom' => [[50, 1, 40, 1, 20, 1, 10, 1], new Rect(27, 14, 37, 34)],
			'left, top'     => [[50, 1, 40, 1, -20, 1, -10, 1], new Rect(0, 0, 37, 35)],
			'left, bottom'  => [[50, 1, 40, 1, -27, 2, 17, 2], new Rect(0, 13, 44, 35)],
			'whole'         => [[64, 1, 48, 1, 0, 1, 0, 1], null],
			'larger'        => [[100, 1, 80, 1, 0, 1, 0, 1], null],
		];
	}

	#[DataProvider('canvasProvider')]
	public function testCanvas(array $clap, Rect|null $canvas): void
	{
		$ispe = self::ispe(64, 48);
		$clap = self::clap(...$clap);
		$ipco = self::ipco($ispe, $clap);
		$ipma = self::ipma([1 => [1, 2]]);
		$iprp = self::iprp($ipco, $ipma);
		$pitm = self::pitm(1);
		$data = self::heif($pitm, $iprp);
		$size = new Size($canvas->width ?? 64, $canvas->height ?? 48);

		$this->assertEquals($canvas, self::canvas($data));
		$this->assertEquals($size, self::read($data)->size);
	}

	public function testCanvasFixtures(): void
	{
		$clap  = self::fixture('heif/clap.avif');
		$plain = self::fixture('heif/plain.heic');
		$exif  = self::fixture('heif/exif.heic');
		$irot  = self::fixture('heif/irot-1.avif');

		$this->assertEquals(new Rect(3, 2, 25, 15), self::canvas($clap));

		$this->assertEquals(new Rect(0, 0, 30, 20), self::canvas($plain));
		$this->assertEquals(new Rect(0, 0, 30, 20), self::canvas($exif));

		$this->assertNull(self::canvas($irot));
		$this->assertNull(self::canvas(''));

		for ($length = 0; $length <= strlen($clap); $length++) {
			$truncated = substr($clap, 0, $length);
			$canvas    = self::canvas($truncated);

			$this->assertTrue($canvas === null || $canvas->x + $canvas->width <= 30);
		}
	}

	public static function cicpProvider(): array
	{
		return [
			'pq'       => ['heif/hdr.avif', [9, 16, 9, 1]],
			'avif'     => ['heif/plain.avif', [1, 2, 0, 1]],
			'animated' => ['heif/animated.avif', [1, 2, 0, 1]],
			'heic'     => ['heif/plain.heic', [1, 13, 6, 1]],
			'grid'     => ['heif/grid.heic', [1, 13, 6, 1]],
		];
	}

	#[DataProvider('cicpProvider')]
	public function testCicp(string $file, array $cicp): void
	{
		$data = self::fixture($file);

		$this->assertSame($cicp, self::cicp($data));
	}

	public function testCicpItems(): void
	{
		$ispe = self::ispe();
		$pq   = self::colr(16);
		$ipco = self::ipco($ispe, $pq);
		$ipma = self::ipma([1 => [1], 2 => [1, 2]]);
		$iprp = self::iprp($ipco, $ipma);

		$first  = self::pitm(1);
		$second = self::pitm(2);
		$other  = self::heif($first, $iprp);
		$own    = self::heif($second, $iprp);

		$this->assertSame(Reader::UNSPECIFIED, self::cicp($other));
		$this->assertSame([9, 16, 9, 1], self::cicp($own));
	}

	public static function cicpPropertiesProvider(): array
	{
		$pq      = self::colr(16);
		$hlg     = self::colr(18);
		$srgb    = self::colr(13);
		$icc     = self::box('colr', 'prof' . str_repeat("\0", 16));
		$limited = self::box('colr', 'nclx' . pack('nnnC', 1, 13, 6, 0x7F));

		$cut = self::box('colr', 'nclx' . pack('n', 9));

		return [
			'pq'           => [[$pq], [9, 16, 9, 1]],
			'hlg'          => [[$hlg], [9, 18, 9, 1]],
			'srgb'         => [[$srgb], [9, 13, 9, 1]],
			'limited'      => [[$limited], [1, 13, 6, 0]],
			'icc'          => [[$icc], Reader::UNSPECIFIED],
			'icc, then pq' => [[$icc, $pq], [9, 16, 9, 1]],
			'cut off'      => [[$cut], Reader::UNSPECIFIED],
			'none'         => [[], Reader::UNSPECIFIED],
		];
	}

	#[DataProvider('cicpPropertiesProvider')]
	public function testCicpProperties(array $properties, array $cicp): void
	{
		$data = self::primary(...$properties);

		$this->assertSame($cicp, self::cicp($data));
	}

	public function testCicpTruncated(): void
	{
		$data = self::fixture('heif/hdr.avif');

		$ipma = strpos($data, 'ipma');

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated = substr($data, 0, $length);
			$cicp      = $length >= $ipma + 17 ? [9, 16, 9, 1] : Reader::UNSPECIFIED;

			$this->assertSame($cicp, self::cicp($truncated));
		}
	}

	public function testExif(): void
	{
		$files = [
			'heif/exif.heic',
			'heif/exif.avif',
			'heif/exif-irot-2.avif',
			'heif/grid.heic'
		];

		foreach ($files as $file) {
			$data   = self::fixture($file);
			$exif   = self::exif($data);
			$handle      = self::handle($exif);
			$orientation = Exif::orientation($handle);

			$this->assertStringStartsWith("MM\0*", $exif);
			$this->assertSame(Orientation::Rotate90, $orientation);
		}

		$tiff   = self::tiff(3);
		$marked = self::item(pack('N', 6) . "Exif\0\0" . $tiff);
		$plain  = self::item(pack('N', 0) . $tiff);

		$this->assertSame($tiff, self::exif($marked));
		$this->assertSame($tiff, self::exif($plain));
	}

	public function testExifItems(): void
	{
		$tiff = self::tiff(6);
		$data = pack('N', 0) . $tiff;

		$infe = self::infe(2, "Exif\0", version: 3);
		$iinf = self::iinf([$infe]);
		$file = self::item($data, iinf: $iinf);

		$this->assertSame($tiff, self::exif($file));

		$infe = self::infe(2, "Exif\0", version: 1);
		$iinf = self::iinf([$infe]);
		$file = self::item($data, iinf: $iinf);

		$this->assertSame('', self::exif($file));

		$entries = [];

		for ($item = 3; $item <= Reader::STEPS; $item++) {
			$entries[] = self::infe($item, 'hvc1');
		}

		$entries[] = self::infe(2, 'Exif');
		$iinf      = self::iinf($entries, count: Reader::STEPS);
		$file      = self::item($data, iinf: $iinf);

		$this->assertSame($tiff, self::exif($file));
	}

	public function testExifLocations(): void
	{
		$tiff   = self::tiff(6);
		$data   = pack('N', 0) . $tiff;
		$length = strlen($data);

		$iloc = self::iloc([[2, 2, $length, self::DATA - 2]], version: 1, sizes: 0x4444);
		$file = self::item($data, $iloc);

		$this->assertSame($tiff, self::exif($file));

		$iloc = self::iloc([[1, 0, 0], [2, self::DATA, $length]], version: 2);
		$file = self::item($data, $iloc);

		$this->assertSame($tiff, self::exif($file));

		$iloc = self::iloc([[2, self::DATA, $length]], sizes: 0x8800);
		$file = self::item($data, $iloc);

		$this->assertSame($tiff, self::exif($file));
	}

	public static function exifMemoryProvider(): array
	{
		$targets    = array_fill(0, 0xFFFF, 5);
		$references = [];
		$entries    = [
			self::infe(1, 'hvc1'),
			self::infe(2, 'Exif')
		];

		for ($item = 100; $item < 120; $item++) {
			$references[] = self::reference('cdsc', $item, $targets);
			$entries[]    = self::infe($item, 'Exif');
		}

		$references[] = self::reference('cdsc', 2, [1]);
		$iref         = self::iref($references);
		$iinf         = self::iinf($entries);

		return [
			'other items' => [$iref, null],
			'Exif items'  => [$iref, $iinf],
		];
	}

	#[DataProvider('exifMemoryProvider')]
	public function testExifMemory(string $iref, string|null $iinf): void
	{
		$tiff   = self::tiff(6);
		$data   = pack('N', 0) . $tiff;
		$file   = self::item($data, iref: $iref, iinf: $iinf);
		$handle = self::handle($file);

		memory_reset_peak_usage();
		$memory = memory_get_usage();
		$exif   = Heif::exif($handle);
		$peak   = memory_get_peak_usage();

		$this->assertSame($tiff, $exif);

		$this->assertLessThan($memory + 300_000, $peak);
	}

	public function testExifNone(): void
	{
		$plain = self::fixture('heif/plain.heic');
		$irot  = self::fixture('heif/irot-1.avif');

		$this->assertSame('', self::exif($plain));
		$this->assertSame('', self::exif($irot));
		$this->assertSame('', self::exif(''));

		$tiff   = self::tiff(6);
		$data   = pack('N', 0) . $tiff;
		$length = strlen($data);

		$cdsc  = self::reference('cdsc', 2, [3]);
		$iref  = self::iref([$cdsc]);
		$other = self::item($data, iref: $iref);

		$cdsc = self::reference('cdsc', 1, [1]);
		$iref = self::iref([$cdsc]);
		$from = self::item($data, iref: $iref);
		$none = self::item($data, iref: '');

		$this->assertSame('', self::exif($other));
		$this->assertSame('', self::exif($from));
		$this->assertSame('', self::exif($none));

		$iloc    = self::iloc([[2, self::DATA, $length, 0, 1]], version: 1);
		$idat    = self::item($data, $iloc);
		$iloc    = self::iloc([[3, self::DATA, $length]]);
		$missing = self::item($data, $iloc);

		$this->assertSame('', self::exif($idat));
		$this->assertSame('', self::exif($missing));

		$iloc     = self::iloc([[2, PHP_INT_MAX, $length, PHP_INT_MAX]], sizes: 0x8880);
		$sum      = self::item($data, $iloc);
		$iloc     = self::iloc([[2, -1, $length]], sizes: 0x8800);
		$negative = self::item($data, $iloc);

		$this->assertSame('', self::exif($sum));
		$this->assertSame('', self::exif($negative));
	}

	public function testExifReferences(): void
	{
		$tiff = self::tiff(6);
		$data = pack('N', 0) . $tiff;

		$dimg = self::reference('dimg', 1, [3]);
		$cdsc = self::reference('cdsc', 2, [3, 1]);
		$iref = self::iref([$dimg, $cdsc]);
		$file = self::item($data, iref: $iref);

		$this->assertSame($tiff, self::exif($file));

		$cdsc = self::reference('cdsc', 2, [1], version: 1);
		$iref = self::iref([$cdsc], version: 1);
		$file = self::item($data, iref: $iref);

		$this->assertSame($tiff, self::exif($file));

		$cdsc  = self::reference('cdsc', 2, [0x0300, 0x0103]);
		$iref  = self::iref([$cdsc]);
		$whole = self::item($data, iref: $iref);

		$cdsc   = self::reference('cdsc', 2, [3], count: 2);
		$free   = self::box('free', pack('n', 1));
		$iref   = self::iref([$cdsc, $free]);
		$within = self::item($data, iref: $iref);

		$this->assertSame('', self::exif($whole));
		$this->assertSame('', self::exif($within));
	}

	public static function readProvider(): array
	{
		return [
			'plain'          => ['heif/plain.avif', 30, 20, Orientation::None],
			'irot 1'         => ['heif/irot-1.avif', 30, 20, Orientation::Rotate270],
			'irot 2'         => ['heif/irot-2.avif', 30, 20, Orientation::Rotate180],
			'irot 3'         => ['heif/irot-3.avif', 30, 20, Orientation::Rotate90],
			'imir 0'         => ['heif/imir-0.avif', 30, 20, Orientation::FlipVertical],
			'imir 1'         => ['heif/imir-1.avif', 30, 20, Orientation::FlipHorizontal],
			'irot 1, imir 0' => ['heif/irot-1-imir-0.avif', 30, 20, Orientation::Transpose],
			'irot 1, imir 1' => ['heif/irot-1-imir-1.avif', 30, 20, Orientation::Transverse],
			'irot 2, imir 0' => ['heif/irot-2-imir-0.avif', 30, 20, Orientation::FlipHorizontal],
			'irot 2, imir 1' => ['heif/irot-2-imir-1.avif', 30, 20, Orientation::FlipVertical],
			'irot 3, imir 0' => ['heif/irot-3-imir-0.avif', 30, 20, Orientation::Transverse],
			'irot 3, imir 1' => ['heif/irot-3-imir-1.avif', 30, 20, Orientation::Transpose],
			'imir 0, irot 1' => ['heif/imir-0-irot-1.avif', 30, 20, Orientation::Transverse],
			'clap'           => ['heif/clap.avif', 25, 15, Orientation::None],
			'exif'           => ['heif/exif.avif', 30, 20, Orientation::Rotate90],
			'exif, irot 2'   => ['heif/exif-irot-2.avif', 30, 20, Orientation::Rotate180],
			'animated'       => ['heif/animated.avif', 30, 20, Orientation::None],
			'heic'           => ['heif/plain.heic', 30, 20, Orientation::None],
			'heic, odd'      => ['heif/odd.heic', 31, 21, Orientation::None],
			'heic, exif'     => ['heif/exif.heic', 30, 20, Orientation::Rotate90],
			'heic, animated' => ['heif/animated.heic', 30, 20, Orientation::None],
			'heic, grid'     => ['heif/grid.heic', 64, 48, Orientation::Rotate90],
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

	public function testReadAssociations(): void
	{
		$ispe       = self::ispe();
		$irot       = self::irot(1);
		$properties = self::ipco($ispe, $irot);
		$size       = new Size(300, 200);
		$rotated    = new Header($size, Orientation::Rotate270);
		$plain      = new Header($size);

		$pitm = self::pitm(7, version: 1);
		$ipma = self::ipma([7 => [0x8001, 0x8002]], version: 1, flags: 1);
		$iprp = self::iprp($properties, $ipma);
		$data = self::heif($pitm, $iprp);

		$this->assertEquals($rotated, self::read($data));

		$pitm = self::pitm(2);
		$ipma = self::ipma([1 => [1, 2], 2 => [0, 1]]);
		$iprp = self::iprp($properties, $ipma);
		$data = self::heif($pitm, $iprp);

		$this->assertEquals($plain, self::read($data));

		$pitm = self::pitm(1);
		$ipma = self::ipma([1 => [1, 2]]);
		$iprp = self::iprp($ipma, $properties);
		$data = self::heif($iprp, $pitm);

		$this->assertEquals($rotated, self::read($data));

		$first  = self::ipma([1 => [1]]);
		$second = self::ipma([2 => [0x8001, 0x8002]], flags: 1);
		$iprp   = self::iprp($properties, $first, $second);
		$pitm   = self::pitm(1);
		$one    = self::heif($pitm, $iprp);
		$pitm   = self::pitm(2);
		$two    = self::heif($pitm, $iprp);

		$this->assertEquals($plain, self::read($one));
		$this->assertEquals($rotated, self::read($two));

		$pitm   = self::pitm(1);
		$other  = self::ipma([2 => [1]]);
		$ipma   = self::ipma([1 => [1, 2]]);
		$iprp   = self::iprp($properties, $other, $other, $other, $ipma);
		$fourth = self::heif($pitm, $iprp);
		$iprp   = self::iprp($properties, $other, $other, $other, $other, $ipma);
		$fifth  = self::heif($pitm, $iprp);

		$this->assertEquals($rotated, self::read($fourth));
		$this->assertEquals(new Header(), self::read($fifth));

		$pitm     = self::pitm(2);
		$ipma     = self::ipma([3 => [1], 2 => [1, 2]]);
		$iprp     = self::iprp($properties, $ipma);
		$unsorted = self::heif($pitm, $iprp);

		$this->assertEquals(new Header(), self::read($unsorted));
	}

	public function testReadClap(): void
	{
		$clap = self::clap(150, 1, 100, 1, 0, 1, 0, 1);
		$half = self::primary($clap);
		$clap = self::clap(301, 2, 200, 3, 0, 1, 0, 1);
		$odd  = self::primary($clap);
		$clap = self::clap(150, 0, 100, 0, 0, 0, 0, 0);
		$zero = self::primary($clap);

		$this->assertEquals(new Size(150, 100), self::read($half)->size);
		$this->assertEquals(new Size(151, 67), self::read($odd)->size);
		$this->assertEquals(new Size(150, 100), self::read($zero)->size);

		$clap    = self::clap(400, 1, 300, 1, 0, 1, 0, 1);
		$larger  = self::primary($clap);
		$clap    = self::clap(200, 1, 100, 1, 80, 1, 0, 1);
		$shifted = self::primary($clap);

		$this->assertEquals(new Size(300, 200), self::read($larger)->size);
		$this->assertEquals(new Size(170, 100), self::read($shifted)->size);

		$ispe = self::ispe();
		$clap = self::clap(150, 1, 100, 1, 0, 1, 0, 1);
		$ipco = self::ipco($ispe, $clap);
		$ipma = self::ipma([1 => [2, 1]]);
		$iprp = self::iprp($ipco, $ipma);
		$pitm = self::pitm(1);
		$data = self::heif($pitm, $iprp);

		$this->assertEquals(new Size(150, 100), self::read($data)->size);

		$irot   = self::irot(3);
		$data   = self::primary($clap, $irot);
		$size   = new Size(150, 100);
		$header = new Header($size, Orientation::Rotate90);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadContainers(): void
	{
		$ispe   = self::ispe();
		$irot   = self::irot(1);
		$first  = self::ipco($ispe, $irot);
		$ispe   = self::ispe(600, 400);
		$second = self::ipco($ispe);
		$ipma   = self::ipma([1 => [1, 2, 3]]);
		$iprp   = self::iprp($first, $second, $ipma);
		$pitm   = self::pitm(1);
		$data   = self::heif($pitm, $iprp);
		$size   = new Size(300, 200);
		$header = new Header($size, Orientation::Rotate270);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadGarbage(): void
	{
		$exif = self::fixture('heif/exif.heic');
		$irot = self::fixture('heif/irot-1-imir-0.avif');
		$hdr  = self::fixture('heif/hdr.avif');

		$this->assertGarbage($exif, keep: 28);
		$this->assertGarbage($irot, keep: 32);
		$this->assertGarbage($hdr, keep: 28);
	}

	public static function readInvalidProvider(): array
	{
		$ftyp  = self::box('ftyp', 'heic');
		$mdat  = self::box('mdat', 'abc');
		$ispe  = self::ispe();
		$ipco  = self::ipco($ispe);
		$ipma  = self::ipma([1 => [1]]);
		$iprp  = self::iprp($ipco, $ipma);
		$pitm  = self::pitm(1);
		$short = self::box('meta', 'ab');
		$large = self::sized(100, 'pitm');
		$cut   = self::meta($large);
		$tiny  = self::sized(4, 'pitm');
		$small = self::meta($tiny);

		return [
			'empty'           => [''],
			'no boxes'        => ['GIF89a'],
			'no meta'         => [$ftyp . $mdat],
			'no primary item' => [self::heif($iprp)],
			'no properties'   => [self::heif($pitm)],
			'short meta'      => [$ftyp . $short],
			'cut off child'   => [$ftyp . $cut],
			'child too small' => [$small],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadLargeBoxes(): void
	{
		$ftyp = self::box('ftyp', 'heic');
		$pitm = self::pitm(1);
		$ispe = self::ispe();
		$ipco = self::ipco($ispe);
		$ipma = self::ipma([1 => [1]]);
		$iprp = self::iprp($ipco, $ipma);
		$meta = pack('N', 0) . $pitm . $iprp;
		$size = new Size(300, 200);

		$large = $ftyp . self::large('meta', $meta);
		$end   = $ftyp . self::last('meta', $meta);

		$this->assertEquals($size, self::read($large)->size);
		$this->assertEquals($size, self::read($end)->size);

		$mdat   = self::box('mdat', str_repeat("\0", 10_000_000));
		$data   = $ftyp . $mdat . self::box('meta', $meta);
		$handle = self::handle($data);

		$this->assertEquals($size, Heif::read($handle)->size);
	}

	public function testReadLimit(): void
	{
		$size = new Size(300, 200);
		$data = self::absurd();

		$this->assertReads($data);
		$this->assertEquals($size, self::read($data)->size);

		$pitm = self::pitm(1);
		$ispe = self::ispe();
		$box  = self::box('free');
		$free = str_repeat($box, Reader::STEPS - 1);

		$ipco = self::ipco($free, $ispe);
		$ipma = self::ipma([1 => [Reader::STEPS]], flags: 1);
		$iprp = self::iprp($ipco, $ipma);
		$last = self::heif($pitm, $iprp);

		$ipco = self::ipco($free, $box, $ispe);
		$ipma = self::ipma([1 => [Reader::STEPS + 1]], flags: 1);
		$iprp = self::iprp($ipco, $ipma);
		$over = self::heif($pitm, $iprp);

		$this->assertEquals($size, self::read($last)->size);
		$this->assertEquals(new Size(0, 0), self::read($over)->size);
	}

	public static function readMemoryProvider(): array
	{
		$ispe = self::ispe();
		$ipco = self::ipco($ispe);
		$ipma = self::ipma([1 => [1]]);
		$iprp = self::iprp($ipco, $ipma);
		$pitm = self::pitm(1);

		$box   = self::box('free');
		$free  = str_repeat($box, 999);
		$first = self::ipco($ispe, $free);
		$boxes = self::iprp(str_repeat($first, 300), $ipma);

		$large      = self::pad($ispe, 1_000_000);
		$large      = self::ipco(str_repeat($large, 4));
		$properties = self::iprp($large, $ipma);
		$padded     = self::pad($pitm, 1_000_000);

		$entries = array_fill(1, 170_000, [1]);
		$entries = self::ipma($entries, version: 1);
		$items   = self::iprp($ipco, $entries);
		$last    = self::pitm(170_000, version: 1);

		return [
			'ipco boxes' => [self::heif($pitm, $boxes), 100_000],
			'properties' => [self::heif($pitm, $properties), 100_000],
			'pitm'       => [self::heif($padded, $iprp), 100_000],
			'ipma'       => [self::heif($last, $items), 1_200_000],
		];
	}

	#[DataProvider('readMemoryProvider')]
	public function testReadMemory(string $data, int $limit): void
	{
		$handle = self::handle($data);

		memory_reset_peak_usage();
		$memory = memory_get_usage();
		$header = Heif::read($handle);
		$peak   = memory_get_peak_usage();

		$this->assertEquals(new Size(300, 200), $header->size);
		$this->assertLessThan($memory + $limit, $peak);
	}

	public function testReadTruncated(): void
	{
		$data = self::fixture('heif/irot-3.avif');

		$ipma = strpos($data, 'ipma');

		for ($length = 0; $length <= strlen($data); $length++) {
			$truncated   = substr($data, 0, $length);
			$header      = self::read($truncated);
			$width       = $length >= $ipma + 16 ? 30 : 0;
			$orientation = $length >= $ipma + 20 ? Orientation::Rotate90 : Orientation::None;

			$this->assertSame($width, $header->size->width);
			$this->assertSame($orientation, $header->orientation);
		}

		$exif     = self::fixture('heif/exif.heic');
		$animated = self::fixture('heif/animated.heic');

		$this->assertTruncated($exif);
		$this->assertTruncated($animated);
	}
}
