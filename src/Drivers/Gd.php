<?php

namespace Darkroom\Drivers;

use Darkroom\Driver;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\ProcessException;
use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Rect;
use Darkroom\Size;
use Darkroom\Source;
use Darkroom\Tools\Icc;
use ErrorException;
use GdImage;
use ValueError;

/**
 * Decodes the whole first frame,
 * resamples it once and orients the small result;
 * writes no metadata but an RGB color profile
 */
final class Gd extends Driver
{
	/**
	 * Transparent black, as GD stores it in truecolor images
	 */
	private const int CLEAR = 0x7F000000;

	/**
	 * A transparent truecolor image that takes pixels as they are
	 */
	private static function blank(int $width, int $height): GdImage
	{
		$image = imagecreatetruecolor($width, $height);

		if ($image === false) {
			throw new ProcessException(
				message: 'GD can\'t create a ' . $width . '×' . $height . ' image'
			);
		}

		imagealphablending($image, enable: false);
		imagefilledrectangle(
			image: $image,
			x1:    0,
			y1:    0,
			x2:    $width - 1,
			y2:    $height - 1,
			color: self::CLEAR
		);

		return $image;
	}

	/**
	 * A gaussian blur: shrunk in one step
	 * until sigma is 1–2 pixels, smoothed there,
	 * then doubled back with one pass each
	 */
	private static function blur(GdImage $image, int $sigma): GdImage
	{
		$width    = imagesx($image);
		$height   = imagesy($image);
		$halvings = (int)log($sigma, 2);
		$factor   = 1 << $halvings;
		$area     = $factor * $factor;

		// shrinking and doubling back add a variance of
		// (area - 1) / 3, each pass 1/2 at its scale
		$passes = (int)round((6 * $sigma * $sigma - 2 * $area + 2) / (3 * $area));

		for ($level = $halvings; $level >= 0; $level--) {
			$factor = 1 << $level;
			$rect   = new Rect(0, 0, imagesx($image), imagesy($image));
			$size   = new Size(
				width:  (int)ceil($width / $factor),
				height: (int)ceil($height / $factor)
			);
			$image  = static::resize($image, $rect, $size);

			static::smooth($image, $level === $halvings ? $passes : 1);
		}

		return $image;
	}

	public function canAnimate(Format $format): bool
	{
		return false;
	}

	public function canRead(Format|Source $input): bool
	{
		$format  = $input instanceof Source ? $input->format() : $input;
		$decoder = static::decoder($format);

		if (function_exists($decoder) === false) {
			return false;
		}

		if ($input instanceof Format) {
			return true;
		}

		// libavif hands over PQ or HLG values,
		// which GD would write as sRGB
		if ($input->isHdr() === true) {
			return false;
		}

		// GD returns a GIF's first frame off its screen
		// and AVIF's coded image around its clean aperture
		if ($input->canvas() !== null) {
			return false;
		}

		// few builds decode an animated WebP
		if ($format === Format::Webp) {
			return
				$this->cache(
					'animation',
					static::decodesAnimation(...)
				) === true ||
				$input->isAnimated() === false;
		}

		return true;
	}

	public function canWrite(Format $format): bool
	{
		$encoder = static::encoder($format);

		return $this->cache(
			$encoder,
			fn (): bool => static::encodes($encoder)
		);
	}

	/**
	 * The first frame
	 */
	private static function decode(Plan $plan): GdImage
	{
		$file    = $plan->source->file;
		$decoder = static::decoder($plan->source->format());
		$image   = $decoder($file);

		if ($image instanceof GdImage === false) {
			throw new ProcessException(
				message: 'GD can\'t decode: ' . $file
			);
		}

		return $image;
	}

	/**
	 * GD's function that reads the format
	 */
	private static function decoder(Format $format): string
	{
		return 'imagecreatefrom' . strtolower($format->name);
	}

	/**
	 * Whether GD decodes an animated WebP:
	 * two lossless frames of a black pixel
	 *
	 * @todo seems super specific, needed?
	 */
	private static function decodesAnimation(): bool
	{
		$pixel = 'VP8L' . pack('V', 14) . "\x2F\0\0\0\0\x07\x10\x11\xFD\x0F\x44\x44\xFF\x03";
		$frame = 'ANMF' . pack('V', 16 + strlen($pixel)) . str_repeat("\0", 12) . "\x64\0\0\0" . $pixel;
		$webp  =
			'WEBP' .
			'VP8X' . pack('V', 10) . "\x02" . str_repeat("\0", 9) .
			'ANIM' . pack('V', 6) . str_repeat("\0", 6) .
			$frame . $frame;

		$riff = 'RIFF' . pack('V', strlen($webp)) . $webp;

		return @imagecreatefromstring($riff) !== false;
	}

	/**
	 * The image in the plan's format,
	 * encoded in memory for the color profile
	 */
	private static function encode(GdImage $image, Plan $plan): string
	{
		// encoders without alpha would keep the colors under it; JPEG
		// decodes opaque, and no step after decoding adds transparency
		if (
			$plan->output->format->isOpaque() === true &&
			$plan->source->format()->isOpaque() === false
		) {
			$image = static::flatten($image);
		}

		imagesavealpha($image, true);
		imageinterlace($image, $plan->output->interlace);

		// GIF knows one transparent color instead of alpha
		if ($plan->output->format === Format::Gif) {
			static::threshold($image);

			$image = static::palette($image);
		}

		$encoder = static::encoder($plan->output->format);
		$options = match ($plan->output->format) {
			Format::Avif,
			Format::Jpeg,
			Format::Webp => [$plan->output->quality],
			default      => []
		};

		/** @var resource $stream */
		$stream = fopen('php://memory', 'w+b');

		if ($encoder($image, $stream, ...$options) !== true) {
			throw new ProcessException(
				message: 'GD can\'t encode ' . $plan->output->format->extension()
			);
		}

		rewind($stream);

		return (string)stream_get_contents($stream);
	}

	/**
	 * GD's function that writes the format
	 */
	private static function encoder(Format $format): string
	{
		return 'image' . strtolower($format->name);
	}

	/**
	 * Whether the encoder writes a pixel, as some
	 * builds read a format they can't write
	 */
	private static function encodes(string $encoder): bool
	{
		if (function_exists($encoder) === false) {
			return false;
		}

		$image  = imagecreatetruecolor(1, 1);
		$stream = fopen('php://memory', 'w+b');

		return @$encoder($image, $stream) === true;
	}

	/**
	 * Grayscale, blur and sharpen, in this order
	 */
	private static function filter(GdImage $image, Plan $plan): GdImage
	{
		if ($plan->thumb->grayscale === true) {
			imagefilter($image, IMG_FILTER_GRAYSCALE);
		}

		if ($plan->thumb->blur !== null) {
			$image = static::blur($image, $plan->thumb->blur);
		}

		if ($plan->thumb->sharpen !== null) {
			static::sharpen($image, $plan->thumb->sharpen);
		}

		return $image;
	}

	/**
	 * The image on white, blended by its alpha
	 */
	private static function flatten(GdImage $image): GdImage
	{
		$width     = imagesx($image);
		$height    = imagesy($image);
		$flattened = static::blank($width, $height);

		imagefilledrectangle($flattened, 0, 0, $width - 1, $height - 1, 0xFFFFFF);
		imagealphablending($flattened, true);
		imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);

		return $flattened;
	}

	public function info(): array
	{
		if ($this->isAvailable() === false) {
			return parent::info();
		}

		return [
			'library' => 'GD',
			'version' => (string)gd_info()['GD Version'],
		];
	}

	public function isAvailable(): bool
	{
		return extension_loaded('gd');
	}

	/**
	 * Turns the stored pixels into the displayed image
	 */
	private static function orient(
		GdImage $image,
		Orientation $orientation
	): GdImage {
		$turns = $orientation->turns();

		// a half turn in place, as `imagerotate()` copies;
		// GD turns counterclockwise
		if ($turns === 2) {
			imageflip($image, IMG_FLIP_BOTH);
		} elseif ($turns > 0) {
			$image = imagerotate($image, -90 * $turns, self::CLEAR);

			if ($image === false) {
				throw new ProcessException(
					message: 'GD can\'t rotate the image'
				);
			}

			imagealphablending($image, false);
		}

		if ($orientation->flips() === true) {
			imageflip($image, IMG_FLIP_HORIZONTAL);
		}

		return $image;
	}

	/**
	 * At most 256 colors and the transparent one,
	 * as GIF holds: each the mean of its pixels,
	 * where GD's quantizer alone gives the middle
	 * of its color cell
	 */
	private static function palette(GdImage $image): GdImage
	{
		$width   = imagesx($image);
		$height  = imagesy($image);
		$palette = static::blank($width, $height);

		imagecopy($palette, $image, 0, 0, 0, 0, $width, $height);
		imagecolortransparent($palette, self::CLEAR);
		imagetruecolortopalette($palette, true, 256);
		imagecolormatch($image, $palette);

		return $palette;
	}

	/**
	 * Throws before decoding an image that can't fit into
	 * the memory limit, which would end in a fatal error
	 */
	private static function preflight(Plan $plan): void
	{
		/** @var non-empty-string $setting always set, -1 for no limit */
		$setting = ini_get('memory_limit');
		$limit   = ini_parse_quantity($setting);

		if ($limit <= 0) {
			return;
		}

		// four bytes a pixel: the source, the resampled image,
		// a copy of it, and the encoded image, at most about
		// as large, twice while it is copied
		$resample = $plan->resample();
		$pixels   = $plan->source->size()->pixels() + $resample->pixels() * 4;

		if ($pixels * 4 > $limit - memory_get_usage()) {
			throw new LimitException(
				message: 'Image too large for the memory limit: ' . $plan->source->file
			);
		}
	}

	/**
	 * @throws LimitException Before decoding, if the image can't fit into the memory limit
	 * @throws ProcessException If GD fails
	 */
	public function process(Plan $plan, string $file): void
	{
		static::preflight($plan);

		// first, as the handler below would
		// throw on warnings readers silence
		$profile = static::profile($plan);

		// GD reports failures as warnings; libpng's own warnings,
		// such as about a profile that doesn't fit, it recovers from
		set_error_handler(static function (int $level, string $message): bool {
			if (str_contains($message, 'libpng warning: ') === true) {
				return true;
			}

			throw new ErrorException($message, 0, $level);
		}, E_WARNING | E_NOTICE);

		try {
			$image = static::decode($plan);
			$image = static::resample($image, $plan);
			$image = static::orient($image, $plan->orientation);
			$image = static::filter($image, $plan);
			$data  = static::encode($image, $plan);
			$data  = Icc::embed($data, $plan->output->format, $profile);

			static::write($file, $data);
		} catch (ErrorException|ValueError $exception) {
			throw new ProcessException(
				message: 'GD failed on ' . $plan->source->file . ': ' . $exception->getMessage(),
				previous: $exception
			);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * The source's color profile, if kept;
	 * only an RGB one, as GD writes RGB
	 */
	private static function profile(Plan $plan): string
	{
		if ($plan->output->icc === false) {
			return '';
		}

		$profile = $plan->source->icc();

		// the color space in the header
		return substr($profile, 16, 4) === 'RGB ' ? $profile : '';
	}

	/**
	 * The plan's region, averaged to the resample size
	 */
	private static function resample(GdImage $image, Plan $plan): GdImage
	{
		$region   = $plan->region();
		$resample = $plan->resample();

		return static::resize($image, $region, $resample);
	}

	/**
	 * The rectangle of the image, averaged to the size in one pass
	 */
	private static function resize(
		GdImage $image,
		Rect $rect,
		Size $size
	): GdImage {
		$resized = static::blank($size->width, $size->height);

		imagecopyresampled(
			$resized,
			$image,
			0,
			0,
			$rect->x,
			$rect->y,
			$size->width,
			$size->height,
			$rect->width,
			$rect->height
		);

		return $resized;
	}

	/**
	 * A gaussian sharpen with a sigma of the amount in
	 * hundredths, as a 3×3 kernel: the pixel twice, less its blur
	 */
	private static function sharpen(GdImage $image, int $amount): void
	{
		$sigma  = (float)$amount / 100.0;
		$edge   = exp(-0.5 / ($sigma * $sigma));
		$corner = $edge * $edge;
		$sum    = 1.0 + 4.0 * ($edge + $corner);
		$kernel = [
			[-$corner, -$edge, -$corner],
			[-$edge, 2.0 * $sum, -$edge],
			[-$corner, -$edge, -$corner],
		];

		// the offset rounds rather than truncates
		imageconvolution($image, $kernel, $sum + 1.0, 0.5);
	}

	/**
	 * Passes of a 3×3 gaussian, each with a variance of 0.5
	 */
	private static function smooth(GdImage $image, int $passes): void
	{
		$kernel = [
			[1, 2, 1],
			[2, 4, 2],
			[1, 2, 1],
		];

		// the offset rounds, so many passes don't darken the image
		for ($pass = 0; $pass < $passes; $pass++) {
			imageconvolution($image, $kernel, 16, 0.5);
		}
	}

	/**
	 * Clears pixels at most half opaque and makes the
	 * others opaque, as ImageMagick does for GIF
	 */
	private static function threshold(GdImage $image): void
	{
		$width  = imagesx($image);
		$height = imagesy($image);
		$cut    = static::blank($width, $height);

		// at least half transparent becomes fully transparent,
		// which blending leaves as the clear canvas under it
		imagefilter($image, IMG_FILTER_COLORIZE, 0, 0, 0, 63);
		imagealphablending($cut, true);
		imagecopy($cut, $image, 0, 0, 0, 0, $width, $height);
		imagecolortransparent($cut, self::CLEAR);

		// merging copies the others without alpha
		// and skips the transparent color
		imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, self::CLEAR);
		imagecopymerge($image, $cut, 0, 0, 0, 0, $width, $height, 100);
	}

	/**
	 * Writes the encoded image in one call
	 */
	private static function write(string $file, string $data): void
	{
		if (file_put_contents($file, $data) !== strlen($data)) {
			throw new ProcessException(
				message: 'GD can\'t write: ' . $file
			);
		}
	}
}
