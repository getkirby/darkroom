<?php

namespace Darkroom\Tests\Formats\Webp;

use Darkroom\Formats\Webp\Reader;
use Darkroom\Formats\Webp\Writer;
use Darkroom\Tests\Formats\FormatTestCase;

abstract class WebpTestCase extends FormatTestCase
{
	protected const string READER = Reader::class;
	protected const string WRITER = Writer::class;

	protected static function extended(
		int $flags,
		int $width = 30,
		int $height = 20
	): string {
		$width  = substr(pack('V', $width - 1), 0, 3);
		$height = substr(pack('V', $height - 1), 0, 3);

		return self::riff('VP8X', chr($flags) . "\0\0\0" . $width . $height);
	}

	protected static function lossless(int $bits): string
	{
		return self::riff('VP8L', "\x2F" . pack('V', $bits));
	}

	protected static function lossy(int $width = 30, int $height = 20): string
	{
		$frame = "\x50\x02\0\x9D\x01\x2A" . pack('vv', $width, $height) . str_repeat("\0", 8);

		return self::riff('VP8 ', $frame);
	}

	protected static function webp(string ...$chunks): string
	{
		$data = 'WEBP' . implode('', $chunks);

		return 'RIFF' . pack('V', strlen($data)) . $data;
	}
}
