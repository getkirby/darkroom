<?php

namespace Darkroom\Tests\Formats;

use Darkroom\Format;
use Darkroom\Formats\Gif\Reader as Gif;
use Darkroom\Formats\Header;
use Darkroom\Formats\Heif\Reader as Heif;
use Darkroom\Formats\Reader as Base;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Base::class)]
class ReaderTest extends FormatTestCase
{
	public function testClose(): void
	{
		$handle = self::handle('GIF89a');
		$reader = new Gif($handle);

		unset($reader);

		$this->assertFalse(is_resource($handle));
	}

	public function testDefaults(): void
	{
		$handle = self::handle('abc');
		$reader = new class ($handle) extends Base {
			public function header(): Header
			{
				return new Header();
			}
		};

		$this->assertNull($reader->canvas());
		$this->assertSame(Base::UNSPECIFIED, $reader->cicp());
		$this->assertSame('', $reader->exif());
		$this->assertSame('', $reader->icc());
		$this->assertFalse($reader->isAnimated());
	}

	public function testFor(): void
	{
		$this->assertSame(Heif::class, Base::for(Format::Avif));
		$this->assertSame(Heif::class, Base::for(Format::Heic));
		$this->assertSame(Gif::class, Base::for(Format::Gif));
	}

	public function testOpen(): void
	{
		$avif = self::FIXTURES . '/heif/exif.avif';
		$gif  = self::FIXTURES . '/gif/still.gif';

		$heif  = Base::open($avif);
		$still = Base::open($gif);

		$this->assertInstanceOf(Heif::class, $heif);
		$this->assertSame(Format::Avif, $heif->format);
		$this->assertInstanceOf(Gif::class, $still);
		$this->assertSame(Format::Gif, $still->format);
	}

	public function testOpenInvalid(): void
	{
		$this->assertNull(Base::open(__FILE__));
		$this->assertNull(Base::open(self::FIXTURES));
		$this->assertNull(Base::open(self::FIXTURES . '/missing.gif'));
		$this->assertNull(Base::open(''));
	}

	public function testProfile(): void
	{
		$icc = self::icc(200);

		$this->assertSame($icc, Base::profile($icc));
		$this->assertSame($icc, Base::profile($icc . 'more'));

		$cut      = substr($icc, 0, 199);
		$unsigned = substr_replace($icc, 'scan', 36, 4);
		$short    = self::icc(131);

		$this->assertSame('', Base::profile($cut));
		$this->assertSame('', Base::profile($unsigned));
		$this->assertSame('', Base::profile($short));
		$this->assertSame('', Base::profile(''));
	}
}
