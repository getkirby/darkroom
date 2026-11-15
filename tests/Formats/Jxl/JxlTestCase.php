<?php

namespace Darkroom\Tests\Formats\Jxl;

use Darkroom\Formats\Jxl\Reader;
use Darkroom\Formats\Jxl\Writer;
use Darkroom\Tests\Formats\FormatTestCase;

abstract class JxlTestCase extends FormatTestCase
{
	protected const string READER = Reader::class;
	protected const array SIZE = [[0, 1], [0, 2], [89, 9], [1, 3]];
	protected const string WRITER = Writer::class;

	protected static function container(string ...$boxes): string
	{
		$signature = self::box('JXL ', "\r\n\x87\n");
		$ftyp      = self::ftyp('jxl ', 'jxl ');

		return $signature . $ftyp . implode('', $boxes);
	}

	protected static function jxl(array ...$fields): string
	{
		$bits = '';

		foreach ($fields as [$value, $count]) {
			for ($bit = 0; $bit < $count; $bit++) {
				$bits .= ($value >> $bit) & 1;
			}
		}

		$bytes = '';

		foreach (str_split(str_pad($bits, 256, '0'), 8) as $byte) {
			$bytes .= chr(bindec(strrev($byte)));
		}

		return "\xFF\x0A" . $bytes;
	}

	protected static function jxlp(int $index, string $part): string
	{
		return self::box('jxlp', pack('N', $index) . $part);
	}
}
