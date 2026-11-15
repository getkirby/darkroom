<?php

namespace Darkroom\Tests\Containers;

use Darkroom\Containers\Splice;
use Darkroom\Tests\Formats\FormatTestCase;
use Darkroom\Tests\Formats\Stream;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Splice::class)]
class SpliceTest extends FormatTestCase
{
	public function testCopy(): void
	{
		$handle = self::handle('abcdefghij');
		$out    = self::handle('');
		$splice = new Splice($handle, $out);

		$splice->copy(0, 2);
		$splice->copy(2, 4);
		$splice->write('-');
		$splice->write('');
		$splice->copy(6, 8);
		$splice->flush();

		rewind($out);

		$this->assertSame('abcd-gh', stream_get_contents($out));
	}

	public function testCopyAdjacent(): void
	{
		// adjacent spans in one copy, so one seek
		$handle = Stream::open('abcdef');
		$out    = self::handle('');
		$splice = new Splice($handle, $out);

		$splice->copy(1, 3);
		$splice->copy(3, 5);
		$splice->flush();

		rewind($out);

		$this->assertSame('bcde', stream_get_contents($out));
		$this->assertSame(1, Stream::of($handle)->seeks);
	}
}
