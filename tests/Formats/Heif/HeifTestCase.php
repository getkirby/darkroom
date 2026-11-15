<?php

namespace Darkroom\Tests\Formats\Heif;

use Darkroom\Formats\Heif\Reader;
use Darkroom\Formats\Heif\Writer;
use Darkroom\Tests\Formats\FormatTestCase;

abstract class HeifTestCase extends FormatTestCase
{
	protected const int DATA = 32;
	protected const string READER = Reader::class;
	protected const string WRITER = Writer::class;

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
			1       => chr($value),
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
}
