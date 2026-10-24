<?php

namespace Darkroom\Bench;

use GdImage;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Written with GD on first use; seeded, so every run reads the same bytes
 */
enum Image
{
	case Gif;
	case Jpeg;
	case Photo;
	case Rotated;

	/**
	 * An APP1 segment with orientation 6
	 */
	protected const string EXIF = "\xFF\xE1\0\x22Exif\0\0" .
		"MM\0\x2A\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0";

	public function file(): string
	{
		$extension = $this === self::Gif ? '.gif' : '.jpg';
		$file      = __DIR__ . '/images/' . strtolower($this->name) . $extension;
		$temp      = $file . '.tmp';

		if (is_file($file) === true) {
			return $file;
		}

		@mkdir(dirname($file));

		// renamed when complete, so a stopped run leaves no half image
		match ($this) {
			self::Gif     => imagegif(self::noise(6000, 300), $temp),
			self::Jpeg    => imagejpeg(self::noise(6000, 4000), $temp, 90),
			self::Photo   => imagejpeg(self::photo(6000, 4000), $temp, 90),
			self::Rotated => file_put_contents($temp, self::rotated()),
		};

		rename($temp, $file);

		return $file;
	}

	/**
	 * Tiles grey grain over the image; opaque without alpha
	 */
	protected static function grain(
		GdImage $image,
		int $min,
		int $max,
		int $alpha
	): GdImage {
		$random = new Randomizer(new Mt19937(0));
		$tile   = imagecreatetruecolor(509, 509);

		imagealphablending($tile, false);

		for ($i = 0; $i < 509 * 509; $i++) {
			$grey = $random->getInt($min, $max) * 0x010101;

			imagesetpixel($tile, $i % 509, intdiv($i, 509), $alpha << 24 | $grey);
		}

		imagesettile($image, $tile);
		imagefilledrectangle($image, 0, 0, imagesx($image) - 1, imagesy($image) - 1, IMG_COLOR_TILED);

		return $image;
	}

	/**
	 * Grey grain, the hardest to decode: the JPEG is twice as large as a photo's
	 */
	protected static function noise(int $width, int $height): GdImage
	{
		$image = imagecreatetruecolor($width, $height);

		return self::grain($image, 107, 149, 0);
	}

	/**
	 * Six areas of color under fainter grain,
	 * which decode about as fast as a photo
	 */
	protected static function photo(int $width, int $height): GdImage
	{
		$colors = [0x6F8FAF, 0x9DB4C8, 0x5A6E8C, 0x4F6B3A, 0x8C6E4F, 0x2F3B24];
		$image  = imagecreatetruecolor($width, $height);
		$small  = imagecreatetruecolor(3, 2);

		foreach ($colors as $i => $color) {
			imagesetpixel($small, $i % 3, intdiv($i, 3), $color);
		}

		imagecopyresampled($image, $small, 0, 0, 0, 0, $width, $height, 3, 2);

		// half transparent
		return self::grain($image, 116, 140, 64);
	}

	/**
	 * The JPEG with the orientation right after its start of image marker
	 */
	protected static function rotated(): string
	{
		$file = self::Jpeg->file();
		$jpeg = (string)file_get_contents($file);

		return substr_replace($jpeg, self::EXIF, 2, 0);
	}
}
