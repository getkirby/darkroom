<?php

namespace Darkroom\Tests\Formats\Gif;

use Darkroom\Formats\Gif\Reader;
use Darkroom\Formats\Gif\Writer;
use Darkroom\Tests\Formats\FormatTestCase;

abstract class GifTestCase extends FormatTestCase
{
	protected const string READER = Reader::class;
	protected const string WRITER = Writer::class;

	protected static function blocks(string $data): string
	{
		$blocks = '';

		foreach (str_split($data, 255) as $block) {
			$blocks .= chr(strlen($block)) . $block;
		}

		return $blocks . "\0";
	}

	protected static function control(): string
	{
		$blocks = self::blocks("\0\x0A\0\0");

		return "!\xF9" . $blocks;
	}

	protected static function frame(array $position, string $blocks): string
	{
		return ',' . pack('vvvv', ...$position) . "\0\x02" . $blocks;
	}

	protected static function gif(string ...$blocks): string
	{
		$screen = 'GIF89a' . pack('vv', 30, 20) . "\x80\0\0";
		$table  = str_repeat("\0", 6);

		return $screen . $table . implode('', $blocks) . ';';
	}

	protected static function image(string $data = "\x02\x02\x44\x01"): string
	{
		$descriptor = ',' . pack('vvvv', 0, 0, 30, 20) . "\x80";
		$table      = str_repeat("\0", 6);
		$blocks     = self::blocks($data);

		return $descriptor . $table . "\x02" . $blocks;
	}

	protected static function netscape(): string
	{
		$blocks = self::blocks('NETSCAPE2.0');

		return "!\xFF" . $blocks;
	}

	protected static function screen(array $size, string $version = '89a'): string
	{
		return 'GIF' . $version . pack('vv', ...$size) . "\0\0\0";
	}
}
