<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Format;
use Darkroom\Readers\Gif;
use Darkroom\Readers\Heif;
use Darkroom\Readers\Reader;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Reader::class)]
class ReaderTest extends ReaderTestCase
{
	public function testBytes(): void
	{
		$handle = self::handle('abcdef');

		$this->assertSame('', Reader::bytes($handle, 0));
		$this->assertSame('', Reader::bytes($handle, -1));
		$this->assertSame('ab', Reader::bytes($handle, 2));
		$this->assertSame('cdef', Reader::bytes($handle, 10));
		$this->assertSame('', Reader::bytes($handle, 10));

		$data   = str_repeat('a', 2_000_000);
		$handle = self::handle($data);
		$bytes  = Reader::bytes($handle, PHP_INT_MAX);

		$this->assertSame(1_048_576, strlen($bytes));

		$data   = str_repeat('a', 20_000);
		$handle = Stream::open($data);
		$bytes  = Reader::bytes($handle, 20_000);

		$this->assertSame(20_000, strlen($bytes));
	}

	public function testCanvas(): void
	{
		$this->assertNull(self::canvas('abc'));
	}

	public function testCicp(): void
	{
		$this->assertSame(Reader::UNSPECIFIED, self::cicp('abc'));
	}

	public function testExif(): void
	{
		$this->assertSame('', self::exif('abc'));
	}

	public function testFind(): void
	{
		$ftyp   = self::box('ftyp', 'avif');
		$first  = self::box('meta', 'abc');
		$second = self::box('meta', 'de');
		$data   = $ftyp . $first . $second;

		$handle = self::handle($data);
		$walk   = Reader::walk($handle, 'box');

		$this->assertSame(3, Reader::find($walk, 'meta'));
		$this->assertSame('abc', Reader::bytes($handle, 3));

		$handle = self::handle($data);
		$walk   = Reader::walk($handle, 'box');

		$this->assertSame(0, Reader::find($walk, 'iinf'));
	}

	public function testFor(): void
	{
		$this->assertSame(Heif::class, Reader::for(Format::Avif));
		$this->assertSame(Heif::class, Reader::for(Format::Heic));
		$this->assertSame(Gif::class, Reader::for(Format::Gif));
	}

	public function testIcc(): void
	{
		$this->assertSame('', self::profile('abc'));
	}

	public function testInt(): void
	{
		$this->assertSame(0x0102, Reader::int("\x01\x02"));
		$this->assertSame(0x0201, Reader::int("\x01\x02", little: true));
		$this->assertSame(0x01020304, Reader::int("\x01\x02\x03\x04"));
		$this->assertSame(0x04030201, Reader::int("\x01\x02\x03\x04", little: true));
		$this->assertSame(0, Reader::int(''));

		$eight = "\x01\x02\x03\x04\x05\x06\x07\x08";
		$nine  = $eight . "\x09";

		$this->assertSame(0x01, Reader::int("\x01"));
		$this->assertSame(0x010203, Reader::int("\x01\x02\x03"));
		$this->assertSame(0x030201, Reader::int("\x01\x02\x03", little: true));
		$this->assertSame(0x0102030405060708, Reader::int($eight));
		$this->assertSame(0x0203040506070809, Reader::int($nine));
		$this->assertSame(0x0807060504030201, Reader::int($nine, little: true));
	}

	public function testIsAnimated(): void
	{
		$this->assertFalse(self::isAnimated('abc'));
	}

	public function testOpen(): void
	{
		$avif  = self::FIXTURES . '/heif/exif.avif';
		$gif   = self::FIXTURES . '/gif/still.gif';
		$close = fn (string $reader, $handle) => $handle;
		$call  = fn (
			string $reader,
			$handle,
			Format $format
		) => [$reader, ftell($handle), $format];

		$this->assertSame([Heif::class, 0, Format::Avif], Reader::open($avif, $call));
		$this->assertSame([Gif::class, 0, Format::Gif], Reader::open($gif, $call));

		$handle = Reader::open($gif, $close);

		$this->assertFalse(is_resource($handle));
	}

	public function testOpenInvalid(): void
	{
		$call = fn () => 'called';

		$this->assertNull(Reader::open(__FILE__, $call));
		$this->assertNull(Reader::open(self::FIXTURES, $call));
		$this->assertNull(Reader::open(self::FIXTURES . '/missing.gif', $call));
	}

	public function testProfile(): void
	{
		$icc = self::icc(200);

		$this->assertSame($icc, Reader::profile($icc));
		$this->assertSame($icc, Reader::profile($icc . 'more'));

		$cut      = substr($icc, 0, 199);
		$unsigned = substr_replace($icc, 'scan', 36, 4);
		$short    = self::icc(131);

		$this->assertSame('', Reader::profile($cut));
		$this->assertSame('', Reader::profile($unsigned));
		$this->assertSame('', Reader::profile($short));
		$this->assertSame('', Reader::profile(''));
	}

	public function testSeek(): void
	{
		$handle = Stream::open('abcdef');
		fread($handle, 2);

		Reader::seek($handle, 2);
		$stream = Stream::of($handle);

		$this->assertSame(0, $stream->seeks);

		Reader::seek($handle, 1);

		$this->assertSame('bcd', fread($handle, 3));
	}

	public function testTiff(): void
	{
		$tiff   = self::tiff(6);
		$length = strlen($tiff);
		$after  = self::handle($tiff . 'abc');
		$marker = self::handle("Exif\0\0" . $tiff);
		$empty  = self::handle($tiff);

		$this->assertSame($tiff, Reader::tiff($after, $length));
		$this->assertSame($tiff, Reader::tiff($marker, $length + 6));
		$this->assertSame('', Reader::tiff($empty, 0));

		$none    = self::handle(pack('N', 0) . $tiff);
		$skipped = self::handle(pack('N', 6) . "Exif\0\0" . $tiff);
		$marker  = self::handle(pack('N', 0) . "Exif\0\0" . $tiff);
		$beyond  = self::handle(pack('N', 100) . $tiff);
		$huge    = self::handle(pack('N', 0xFFFFFFFF) . $tiff);

		$this->assertSame($tiff, Reader::tiff($none, $length + 4, offset: true));
		$this->assertSame($tiff, Reader::tiff($skipped, $length + 10, offset: true));
		$this->assertSame($tiff, Reader::tiff($marker, $length + 10, offset: true));
		$this->assertSame('', Reader::tiff($beyond, $length + 4, offset: true));
		$this->assertSame('', Reader::tiff($huge, PHP_INT_MAX, offset: true));

		$data   = str_repeat('a', 2_000_000);
		$handle = self::handle($data);
		$bytes  = Reader::tiff($handle, PHP_INT_MAX);

		$this->assertSame(1_048_576, strlen($bytes));
	}

	public function testWalkBox(): void
	{
		$ftyp  = self::box('ftyp', 'avif');
		$mdat  = self::large('mdat', 'abc');
		$meta  = self::last('meta', 'defgh');
		$data  = $ftyp . $mdat . $meta;
		$boxes = [
			['ftyp', 4, 'av'],
			['mdat', 3, 'ab'],
			['meta', PHP_INT_MAX - 39, 'de'],
		];

		$this->assertSame($boxes, self::walk($data, 'box'));

		$ispe  = self::box('ispe', 'abc');
		$irot  = self::box('irot', 'de');
		$imir  = self::last('imir');
		$data  = $ispe . $irot . $imir;
		$first = ['ispe', 3, 'ab'];
		$both  = [$first, ['irot', 2, 'de']];

		$this->assertSame([$first], self::walk($data, 'box', 11));
		$this->assertSame([$first], self::walk($data, 'box', 20));
		$this->assertSame([...$both, ['imir', 0, '']], self::walk($data, 'box', 29));
		$this->assertSame([...$both, ['imir', 1, '']], self::walk($data, 'box', 30));

		$mdat   = self::last('mdat');
		$meta   = self::box('meta', 'abc');
		$data   = $ftyp . $mdat . $meta;
		$handle = Stream::open($data);
		$walk   = Reader::walk($handle, 'box');
		$types  = array_keys(iterator_to_array($walk));

		$this->assertSame(['ftyp', 'mdat'], $types);
		$this->assertLessThanOrEqual(strlen($data), ftell($handle));
	}

	public function testWalkInvalid(): void
	{
		$this->assertSame([], self::walk('', 'png'));
		$this->assertSame([], self::walk('abcdefg', 'box'));

		$small = self::sized(7, 'ftyp', 'avif');
		$short = self::sized(1, 'mdat', pack('J', 15));
		$huge  = self::sized(1, 'mdat', str_repeat("\xFF", 8));

		$this->assertSame([], self::walk($small, 'box'));
		$this->assertSame([], self::walk($short, 'box'));
		$this->assertSame([], self::walk($huge, 'box'));

		$text = self::sized(0xFFFFFFFF, 'tEXt', 'abc');
		$end  = self::sized(0, 'IEND');
		$data = $text . $end;

		$this->assertSame([['tEXt', 0xFFFFFFFF, 'ab']], self::walk($data, 'png'));
	}

	public function testWalkLimit(): void
	{
		$free = self::box('free');
		$data = str_repeat($free, Reader::STEPS + 1);

		$this->assertCount(Reader::STEPS, self::walk($data, 'box'));
	}

	public function testWalkPng(): void
	{
		$text = self::chunk('tEXt', 'abc');
		$end  = self::chunk('IEND');
		$data = $text . $end;

		$this->assertSame([['tEXt', 3, 'ab'], ['IEND', 0, '']], self::walk($data, 'png'));
	}

	public function testWalkRiff(): void
	{
		$iccp = self::riff('ICCP', 'abc');
		$exif = self::riff('EXIF', 'de');
		$data = $iccp . $exif;

		$this->assertSame([['ICCP', 3, 'ab'], ['EXIF', 2, 'de']], self::walk($data, 'riff'));
	}

	public function testWalkSeeks(): void
	{
		$ispe   = self::box('ispe', 'abc');
		$irot   = self::box('irot', 'de');
		$handle = Stream::open($ispe . $irot);

		foreach (Reader::walk($handle, 'box') as $length) {
			Reader::bytes($handle, $length);
		}

		$stream = Stream::of($handle);

		$this->assertSame(0, $stream->seeks);
	}

	private static function walk(
		string $data,
		string $layout,
		int $length = PHP_INT_MAX
	): array {
		$handle = self::handle($data);
		$chunks = [];

		foreach (Reader::walk($handle, $layout, $length) as $type => $size) {
			$bytes    = Reader::bytes($handle, min($size, 2));
			$chunks[] = [$type, $size, $bytes];
		}

		return $chunks;
	}
}
