<?php

namespace Darkroom\Tests;

use Darkroom\Rect;
use Darkroom\Size;
use FilesystemIterator;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class TestCase extends BaseTestCase
{
	private string|null $tmp = null;

	protected static function app1(int $orientation): string
	{
		$tiff = self::tiff($orientation);

		return self::segment(0xE1, "Exif\0\0" . $tiff);
	}

	protected static function bare(int $width, int $height): string
	{
		$ihdr = self::ihdr($width, $height);
		$iend = self::chunk('IEND');

		return self::png($ihdr, $iend);
	}

	protected static function box(string $type, string $data = ''): string
	{
		return pack('N', 8 + strlen($data)) . $type . $data;
	}

	protected static function chunk(string $type, string $data = ''): string
	{
		$crc = crc32($type . $data);

		return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
	}

	protected function file(string $name, string|null $data = null): string
	{
		$file = $this->tmp() . '/' . $name;

		if ($data !== null) {
			file_put_contents($file, $data);
		}

		return $file;
	}

	protected static function framed(Size $screen, Rect $frame): string
	{
		$header = 'GIF89a' . pack('vv', $screen->width, $screen->height) . "\0\0\0";
		$image  = ',' . pack('vvvv', $frame->x, $frame->y, $frame->width, $frame->height);
		$data   = "\0\x02\x02\x44\x01\0";

		return $header . $image . $data . ';';
	}

	protected static function ftyp(string $major, string ...$compatible): string
	{
		return self::box('ftyp', $major . pack('N', 0) . implode('', $compatible));
	}

	protected static function ihdr(int $width, int $height): string
	{
		return self::chunk('IHDR', pack('NN', $width, $height) . "\x08\x02\0\0\0");
	}

	protected static function large(string $type, string $data = ''): string
	{
		return pack('N', 1) . $type . pack('J', 16 + strlen($data)) . $data;
	}

	protected static function last(string $type, string $data = ''): string
	{
		return pack('N', 0) . $type . $data;
	}

	protected static function png(string ...$chunks): string
	{
		return "\x89PNG\r\n\x1A\n" . implode('', $chunks);
	}

	protected static function riff(string $type, string $data = ''): string
	{
		$pad = strlen($data) % 2 === 1 ? "\0" : '';

		return $type . pack('V', strlen($data)) . $data . $pad;
	}

	protected static function segment(int $marker, string $data): string
	{
		return "\xFF" . chr($marker) . pack('n', strlen($data) + 2) . $data;
	}

	protected static function sized(
		int $size,
		string $type,
		string $data = ''
	): string {
		return pack('N', $size) . $type . $data;
	}

	protected function tearDown(): void
	{
		if ($this->tmp === null) {
			return;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $file) {
			$file->isDir() === true ? rmdir($file) : unlink($file);
		}

		rmdir($this->tmp);
	}

	protected static function tiff(int|array $entries, bool $little = false): string
	{
		if (is_int($entries) === true) {
			$entries = [[0x0112, 3, $entries]];
		}

		$short = $little === true ? 'v' : 'n';
		$long  = $little === true ? 'V' : 'N';
		$order = $little === true ? "II*\0" : "MM\0*";
		$data  = $order . pack($long, 8) . pack($short, count($entries));

		foreach ($entries as [$tag, $type, $value]) {
			$data .= pack($short . $short . $long, $tag, $type, 1);
			$data .= $type === 3 ? pack($short . 'x2', $value) : pack($long, $value);
		}

		return $data . pack($long, 0);
	}

	protected function tmp(): string
	{
		if ($this->tmp === null) {
			$this->tmp = sys_get_temp_dir() . '/darkroom-' . bin2hex(random_bytes(8));
			mkdir($this->tmp);
		}

		return $this->tmp;
	}
}
