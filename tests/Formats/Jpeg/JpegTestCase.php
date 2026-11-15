<?php

namespace Darkroom\Tests\Formats\Jpeg;

use Darkroom\Formats\Jpeg\Reader;
use Darkroom\Formats\Jpeg\Writer;
use Darkroom\Tests\Formats\FormatTestCase;

abstract class JpegTestCase extends FormatTestCase
{
	protected const string READER = Reader::class;
	protected const string WRITER = Writer::class;

	protected static function app2(string $profile, int $size = 65519): array
	{
		$parts    = str_split($profile, $size);
		$segments = [];

		foreach ($parts as $index => $part) {
			$head       = "ICC_PROFILE\0" . chr($index + 1) . chr(count($parts));
			$segments[] = self::segment(0xE2, $head . $part);
		}

		return $segments;
	}

	protected static function frame(
		int $width,
		int $height,
		int $marker = 0xC0
	): string {
		$size       = pack('nn', $height, $width);
		$components = "\x03\x01\x22\0\x02\x11\x01\x03\x11\x01";

		return self::segment($marker, "\x08" . $size . $components);
	}

	protected static function jpeg(string ...$segments): string
	{
		return "\xFF\xD8" . implode('', $segments);
	}

	protected static function xmp(): string
	{
		return self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta/>");
	}
}
