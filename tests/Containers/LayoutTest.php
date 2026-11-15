<?php

namespace Darkroom\Tests\Containers;

use Darkroom\Containers\Bytes;
use Darkroom\Containers\Layout;
use Darkroom\Tests\Formats\FormatTestCase;
use Darkroom\Tests\Formats\Stream;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Layout::class)]
class LayoutTest extends FormatTestCase
{
	public function testChunk(): void
	{
		$box  = self::box('ftyp', 'avif');
		$png  = self::chunk('tEXt', 'abc');
		$odd  = self::riff('ICCP', 'abc');
		$even = self::riff('EXIF', 'de');

		$this->assertSame($box, Layout::Box->chunk('ftyp', 'avif'));
		$this->assertSame($png, Layout::Png->chunk('tEXt', 'abc'));
		$this->assertSame($odd, Layout::Riff->chunk('ICCP', 'abc'));
		$this->assertSame($even, Layout::Riff->chunk('EXIF', 'de'));
	}

	public function testFind(): void
	{
		$ftyp   = self::box('ftyp', 'avif');
		$first  = self::box('meta', 'abc');
		$second = self::box('meta', 'de');
		$data   = $ftyp . $first . $second;

		$handle = self::handle($data);
		$walk   = Layout::Box->walk($handle);

		$this->assertSame(3, Layout::find($walk, 'meta'));
		$this->assertSame('abc', Bytes::bytes($handle, 3));

		$handle = self::handle($data);
		$walk   = Layout::Box->walk($handle);

		$this->assertSame(0, Layout::find($walk, 'iinf'));
	}

	public function testSpans(): void
	{
		$ftyp   = self::box('ftyp', 'avif');
		$mdat   = self::large('mdat', 'abc');
		$meta   = self::last('meta', 'defgh');
		$handle = self::handle($ftyp . $mdat . $meta);
		$spans  = Layout::Box->spans($handle);
		$boxes  = [
			'ftyp' => [0, 12],
			'mdat' => [12, 31],
			'meta' => [31, PHP_INT_MAX],
		];

		$this->assertSame($boxes, iterator_to_array($spans));

		$text   = self::chunk('tEXt', 'abc');
		$end    = self::chunk('IEND');
		$handle = self::handle('x' . $text . $end);
		fseek($handle, 1);
		$spans  = Layout::Png->spans($handle);

		$this->assertSame(['tEXt' => [1, 16], 'IEND' => [16, 28]], iterator_to_array($spans));

		$iccp   = self::riff('ICCP', 'abc');
		$exif   = self::riff('EXIF', 'de');
		$handle = self::handle($iccp . $exif . $iccp);
		$spans  = Layout::Riff->spans($handle, 22);

		$this->assertSame(['ICCP' => [0, 12], 'EXIF' => [12, 22]], iterator_to_array($spans));
	}

	public function testSpansLimit(): void
	{
		$free   = self::box('free');
		$handle = self::handle(str_repeat($free, Bytes::STEPS + 1));
		$spans  = Layout::Box->spans($handle);
		$count  = 0;

		foreach ($spans as [$start, $end]) {
			$this->assertSame($count * 8, $start);
			$this->assertSame($start + 8, $end);

			$count++;
		}

		$this->assertSame(Bytes::STEPS + 1, $count);
	}

	public function testTail(): void
	{
		$this->assertSame(0, Layout::Box->tail(3));
		$this->assertSame(4, Layout::Png->tail(3));
		$this->assertSame(1, Layout::Riff->tail(3));
		$this->assertSame(0, Layout::Riff->tail(2));
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

		$this->assertSame($boxes, self::walk($data, Layout::Box));

		$ispe  = self::box('ispe', 'abc');
		$irot  = self::box('irot', 'de');
		$imir  = self::last('imir');
		$data  = $ispe . $irot . $imir;
		$first = ['ispe', 3, 'ab'];
		$both  = [$first, ['irot', 2, 'de']];

		$this->assertSame([$first], self::walk($data, Layout::Box, 11));
		$this->assertSame([$first], self::walk($data, Layout::Box, 20));
		$this->assertSame([...$both, ['imir', 0, '']], self::walk($data, Layout::Box, 29));
		$this->assertSame([...$both, ['imir', 1, '']], self::walk($data, Layout::Box, 30));

		$mdat   = self::last('mdat');
		$meta   = self::box('meta', 'abc');
		$data   = $ftyp . $mdat . $meta;
		$handle = Stream::open($data);
		$walk   = Layout::Box->walk($handle);
		$types  = array_keys(iterator_to_array($walk));

		$this->assertSame(['ftyp', 'mdat'], $types);
		$this->assertLessThanOrEqual(strlen($data), ftell($handle));
	}

	public function testWalkInvalid(): void
	{
		$this->assertSame([], self::walk('', Layout::Png));
		$this->assertSame([], self::walk('abcdefg', Layout::Box));

		$small = self::sized(7, 'ftyp', 'avif');
		$short = self::sized(1, 'mdat', pack('J', 15));
		$huge  = self::sized(1, 'mdat', str_repeat("\xFF", 8));

		$this->assertSame([], self::walk($small, Layout::Box));
		$this->assertSame([], self::walk($short, Layout::Box));
		$this->assertSame([], self::walk($huge, Layout::Box));

		$text = self::sized(0xFFFFFFFF, 'tEXt', 'abc');
		$end  = self::sized(0, 'IEND');
		$data = $text . $end;

		$this->assertSame([['tEXt', 0xFFFFFFFF, 'ab']], self::walk($data, Layout::Png));
	}

	public function testWalkLimit(): void
	{
		$free = self::box('free');
		$data = str_repeat($free, Bytes::STEPS + 1);

		$this->assertCount(Bytes::STEPS, self::walk($data, Layout::Box));
	}

	public function testWalkPng(): void
	{
		$text = self::chunk('tEXt', 'abc');
		$end  = self::chunk('IEND');
		$data = $text . $end;

		$this->assertSame([['tEXt', 3, 'ab'], ['IEND', 0, '']], self::walk($data, Layout::Png));
	}

	public function testWalkRiff(): void
	{
		$iccp = self::riff('ICCP', 'abc');
		$exif = self::riff('EXIF', 'de');
		$data = $iccp . $exif;

		$this->assertSame([['ICCP', 3, 'ab'], ['EXIF', 2, 'de']], self::walk($data, Layout::Riff));
	}

	public function testWalkSeeks(): void
	{
		$ispe   = self::box('ispe', 'abc');
		$irot   = self::box('irot', 'de');
		$handle = Stream::open($ispe . $irot);

		foreach (Layout::Box->walk($handle) as $length) {
			Bytes::bytes($handle, $length);
		}

		$stream = Stream::of($handle);

		$this->assertSame(0, $stream->seeks);
	}

	protected static function walk(
		string $data,
		Layout $layout,
		int $length = PHP_INT_MAX
	): array {
		$handle = self::handle($data);
		$chunks = [];

		foreach ($layout->walk($handle, $length) as $type => $size) {
			$bytes    = Bytes::bytes($handle, min($size, 2));
			$chunks[] = [$type, $size, $bytes];
		}

		return $chunks;
	}
}
