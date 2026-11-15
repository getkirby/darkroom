<?php

namespace Darkroom\Tests\Containers;

use Darkroom\Containers\Bytes;
use Darkroom\Tests\Formats\FormatTestCase;
use Darkroom\Tests\Formats\Stream;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Bytes::class)]
class BytesTest extends FormatTestCase
{
	public function testBytes(): void
	{
		$handle = self::handle('abcdef');

		$this->assertSame('', Bytes::bytes($handle, 0));
		$this->assertSame('', Bytes::bytes($handle, -1));
		$this->assertSame('ab', Bytes::bytes($handle, 2));
		$this->assertSame('cdef', Bytes::bytes($handle, 10));
		$this->assertSame('', Bytes::bytes($handle, 10));

		$data   = str_repeat('a', 2_000_000);
		$handle = self::handle($data);
		$bytes  = Bytes::bytes($handle, PHP_INT_MAX);

		$this->assertSame(1_048_576, strlen($bytes));

		$data   = str_repeat('a', 20_000);
		$handle = Stream::open($data);
		$bytes  = Bytes::bytes($handle, 20_000);

		$this->assertSame(20_000, strlen($bytes));
	}

	public function testInt(): void
	{
		$this->assertSame(0x0102, Bytes::int("\x01\x02"));
		$this->assertSame(0x0201, Bytes::int("\x01\x02", little: true));
		$this->assertSame(0x01020304, Bytes::int("\x01\x02\x03\x04"));
		$this->assertSame(0x04030201, Bytes::int("\x01\x02\x03\x04", little: true));
		$this->assertSame(0, Bytes::int(''));

		$eight = "\x01\x02\x03\x04\x05\x06\x07\x08";
		$nine  = $eight . "\x09";

		$this->assertSame(0x01, Bytes::int("\x01"));
		$this->assertSame(0x010203, Bytes::int("\x01\x02\x03"));
		$this->assertSame(0x030201, Bytes::int("\x01\x02\x03", little: true));
		$this->assertSame(0x0102030405060708, Bytes::int($eight));
		$this->assertSame(0x0203040506070809, Bytes::int($nine));
		$this->assertSame(0x0807060504030201, Bytes::int($nine, little: true));
	}

	public function testSeek(): void
	{
		$handle = Stream::open('abcdef');
		fread($handle, 2);

		Bytes::seek($handle, 2);
		$stream = Stream::of($handle);

		$this->assertSame(0, $stream->seeks);

		Bytes::seek($handle, 1);

		$this->assertSame('bcd', fread($handle, 3));
	}
}
