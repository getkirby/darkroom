<?php

namespace Darkroom\Tests\Readers;

use Darkroom\Readers\Header;
use Darkroom\Readers\Jp2;
use Darkroom\Size;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Jp2::class)]
class Jp2Test extends ReaderTestCase
{
	protected const string READER = Jp2::class;

	/**
	 * SOC and SIZ with the reference grid and the image's offset on it
	 */
	private static function codestream(
		int $width,
		int $height,
		int $x = 0,
		int $y = 0
	): string {
		$siz = pack('nnNNNN', 47, 0, $width, $height, $x, $y);

		return "\xFF\x4F\xFF\x51" . $siz;
	}

	private static function container(string ...$boxes): string
	{
		$signature = "\0\0\0\x0CjP  \r\n\x87\n";
		$ftyp      = self::box('ftyp', 'jp2 ' . "\0\0\0\0" . 'jp2 ');

		return $signature . $ftyp . implode('', $boxes);
	}

	/**
	 * The `jp2h` box with its image header: height first
	 */
	private static function header(int $width, int $height): string
	{
		$ihdr = self::box('ihdr', pack('NNnCCCC', $height, $width, 3, 7, 7, 0, 0));

		return self::box('jp2h', $ihdr);
	}

	public static function fixtureProvider(): array
	{
		return [
			'container'  => ['jp2/plain.jp2', 30, 20],
			'codestream' => ['jp2/plain.j2k', 30, 20],
		];
	}

	#[DataProvider('fixtureProvider')]
	public function testRead(string $file, int $width, int $height): void
	{
		$data   = self::fixture($file);
		$size   = new Size($width, $height);
		$header = new Header($size);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadContainer(): void
	{
		$colr   = self::box('colr', "\x01\0\0\0\0\0\x10");
		$jp2h   = self::header(300, 7);
		$data   = self::container($colr, $jp2h);
		$size   = new Size(300, 7);
		$header = new Header($size);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadGarbage(): void
	{
		$container  = self::fixture('jp2/plain.jp2');
		$codestream = self::fixture('jp2/plain.j2k');

		$this->assertGarbage($container, keep: 12);
		$this->assertGarbage($codestream, keep: 4);
	}

	public static function readInvalidProvider(): array
	{
		$other = self::box('jp2h', self::box('colr'));

		return [
			'empty'            => [''],
			'signature only'   => ["\xFF\x4F\xFF\x51"],
			'no header'        => [self::container()],
			'no image header'  => [self::container($other)],
			'offset too large' => [self::codestream(30, 20, 40, 30)],
		];
	}

	#[DataProvider('readInvalidProvider')]
	public function testReadInvalid(string $data): void
	{
		$this->assertEquals(new Header(), self::read($data));
	}

	public function testReadOffset(): void
	{
		$data   = self::codestream(40, 30, 10, 5);
		$size   = new Size(30, 25);
		$header = new Header($size);

		$this->assertEquals($header, self::read($data));
	}

	public function testReadTruncated(): void
	{
		$container  = self::fixture('jp2/plain.jp2');
		$codestream = self::fixture('jp2/plain.j2k');

		$this->assertTruncated($container);
		$this->assertTruncated($codestream);
	}
}
