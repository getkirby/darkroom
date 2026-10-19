<?php

namespace Darkroom\Drivers;

use Closure;
use Darkroom\Driver;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\ProcessException;
use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Size;
use Darkroom\Source;
use Darkroom\Tools\Hdr;
use Imagick as Image;
use ImagickException;

/**
 * Shrinks JPEG while decoding, then cuts out,
 * resizes and orients every frame;
 * keeps no metadata but the color profile
 */
final class Imagick extends Driver
{
	/**
	 * Properties the encoders of the formats read:
	 * the HDR signal of AVIF and HEIC
	 */
	private const array KEEP = [
		'heic:cicp' => [Format::Avif, Format::Heic],
	];

	/**
	 * ImageMagick's code for a resource limit error
	 */
	private const int LIMIT = 400;

	/**
	 * Whether two frames written in the format
	 * are read back with their delays
	 *
	 * @todo what's the difference to isAnimated? or canAnimate?
	 */
	private static function animates(string $name): bool
	{
		$image = new Image();

		try {
			$image->newImage(1, 1, 'white', $name);
			$image->newImage(1, 1, 'black', $name);

			// without a delay, JPEG XL frames are layers of one image
			foreach ($image as $frame) {
				$frame->setImageDelay(10);
			}

			$blob   = $image->getImagesBlob();
			$read   = new Image();
			$delays = [];

			$read->readImageBlob($blob);

			foreach ($read as $frame) {
				$delays[] = $frame->getImageDelay();
			}

			return $delays === [10, 10];
		} catch (ImagickException) {
			return false;
		}
	}

	public function canAnimate(Format $format): bool
	{
		$name = static::name($format);

		return $this->cache(
			'animate ' . $name,
			fn (): bool => static::animates($name)
		);
	}

	public function canRead(Format|Source $input): bool
	{
		$format = $input instanceof Source ? $input->format() : $input;
		$name   = static::name($format);

		return $this->cache(
			'read ' . $name,
			fn (): bool => static::knows($name)
		);
	}

	public function canWrite(Format $format): bool
	{
		$name = static::name($format);

		return $this->cache(
			'write ' . $name,
			fn (): bool => static::encodes($name)
		);
	}

	/**
	 * CMYK as sRGB, through the frame's CMYK profile;
	 * by formula without one, or where ImageMagick
	 * lacks LCMS and keeps the pixels
	 */
	private static function cmyk(Image $frame): void
	{
		$profile = (string)($frame->getImageProfiles('icc')['icc'] ?? '');

		// the profile's color space, after its size,
		// CMM, version and class
		if (substr($profile, 16, 4) === 'CMYK') {
			// sRGB-v2-micro of the Compact ICC Profiles (CC0)
			$srgb = (string)file_get_contents(dirname(__DIR__, 2) . '/assets/srgb.icc');

			$frame->profileImage('icc', $srgb);
		}

		if ($frame->getImageColorspace() === Image::COLORSPACE_CMYK) {
			$frame->transformImageColorspace(Image::COLORSPACE_SRGB);
		}
	}

	/**
	 * The first frame, or all of them, on their
	 * screen; JPEG shrunk while decoding
	 */
	private static function decode(Plan $plan): Image
	{
		$hint  = $plan->hint();
		$name  = static::name($plan->source->format());
		$scene = $plan->frames === true ? '' : '[0]';
		$image = new Image();

		$image->setOption('jpeg:size', $hint->width . 'x' . $hint->height);

		// a HEIF sequence's still, which the source's size
		// describes, not the first frame of its track
		$image->setOption('heic:primary-image', 'true');

		// the prefix keeps ImageMagick from guessing the format
		$image->readImage($name . ':' . $plan->source->file . $scene);

		$page = $image->getImagePage();
		$full = [
			'width'  => $image->getImageWidth(),
			'height' => $image->getImageHeight(),
			'x'      => 0,
			'y'      => 0,
		];

		// coalescing copies even a frame that fills its screen
		if ($image->getNumberImages() === 1 && $page === $full) {
			return $image;
		}

		return $image->coalesceImages();
	}

	protected function defaults(): array
	{
		return [
			'limit'   => 200_000_000,
			'threads' => 1,
		];
	}

	private static function encode(
		Image $image,
		Plan $plan,
		string $file
	): void {
		// GIF stores only what changes, other encoders need whole frames;
		// unchanged pixels turn transparent, so their dithering doesn't shimmer
		if ($plan->frames === true && $plan->output->format === Format::Gif) {
			$image = $image->optimizeImageLayers();
			$image->optimizeImageTransparency();
		}

		$interlace = $plan->output->interlace === true ? Image::INTERLACE_PLANE : Image::INTERLACE_NO;

		// JPEG and WebP read the image's quality,
		// AVIF and JPEG XL the wand's
		$image->setCompressionQuality($plan->output->quality);
		$image->setInterlaceScheme($interlace);

		// chroma at half resolution both ways (4:2:0),
		// where ImageMagick would keep the source's or,
		// from quality 90 on, write it whole
		if ($plan->output->format === Format::Jpeg) {
			$image->setOption('jpeg:sampling-factor', '2x2,1x1,1x1');
		}

		// PNG reads the quality's tens as zlib level;
		// 9 takes two to five times as long as zlib's
		// default for files a few percent smaller
		if ($plan->output->format === Format::Png) {
			$image->setOption('png:compression-level', '6');
		}

		foreach ($image as $frame) {
			$frame->setImageCompressionQuality($plan->output->quality);

			// 16 bits only make thumbs larger
			if ($plan->output->format === Format::Png) {
				$frame->setImageDepth(8);
			}

			// on white, as encoders without alpha
			// would keep the colors under it
			if ($plan->output->format->isOpaque() === true) {
				$frame->setImageBackgroundColor('white');
				$frame->setImageAlphaChannel(Image::ALPHACHANNEL_REMOVE);
			}
		}

		// the prefix decides the format,
		// not the temp file's name
		$image->writeImages(
			static::name($plan->output->format) . ':' . $file,
			true
		);
	}

	/**
	 * Whether ImageMagick writes a pixel in the format,
	 * as it lists formats it can only read
	 */
	private static function encodes(string $name): bool
	{
		$image = new Image();

		try {
			$image->newImage(1, 1, 'white', $name);

			return $image->getImageBlob() !== '';
		} catch (ImagickException) {
			return false;
		}
	}

	/**
	 * Grayscale, blur and sharpen, in this order
	 */
	private static function filter(Image $frame, Plan $plan): void
	{
		if ($plan->thumb->grayscale === true) {
			static::gray($frame);
		}

		if ($plan->thumb->blur !== null) {
			$frame->blurImage(0, $plan->thumb->blur);
		}

		if ($plan->thumb->sharpen !== null) {
			$frame->sharpenImage(0, $plan->thumb->sharpen / 100);
		}
	}

	/**
	 * One frame's region at the resample
	 * size, oriented, filtered and stripped
	 */
	private function frame(Image $frame, Plan $plan): void
	{
		$decoded  = new Size($frame->getImageWidth(), $frame->getImageHeight());
		$region   = $plan->region($decoded);
		$resample = $plan->resample();

		// cropping copies even the whole frame
		if (
			$region->width < $decoded->width ||
			$region->height < $decoded->height
		) {
			$frame->cropImage($region->width, $region->height, $region->x, $region->y);

			// the crop would keep its offset into the frame
			$frame->setImagePage(0, 0, 0, 0);
		}

		// ImageMagick's filter: Lanczos,
		// Mitchell for alpha and palettes
		$frame->resizeImage($resample->width, $resample->height, Image::FILTER_UNDEFINED, 1);

		// web formats show no CMYK
		if ($frame->getImageColorspace() === Image::COLORSPACE_CMYK) {
			static::cmyk($frame);
		}

		$this->tone($frame, $plan);

		static::orient($frame, $plan->orientation);
		static::filter($frame, $plan);
		static::strip($frame, $plan);
	}

	/**
	 * Rec. 601 luma of the gamma-encoded values,
	 * as GD's; alpha keeps its row
	 */
	private static function gray(Image $frame): void
	{
		$luma = [0.299, 0.587, 0.114, 0.0, 0.0];

		$frame->colorMatrixImage([
			...$luma,
			...$luma,
			...$luma,
			0.0, 0.0, 0.0, 1.0, 0.0,
			0.0, 0.0, 0.0, 0.0, 1.0,
		]);

		$frame->transformImageColorspace(Image::COLORSPACE_GRAY);

		// gray is written without chroma, which the identity
		// matrix (0) doesn't allow; 2 leaves it unspecified
		$cicp = explode('/', $frame->getImageProperty('heic:cicp'));

		if (count($cicp) === 4 && $cicp[2] === '0') {
			$cicp[2] = '2';
			$frame->setImageProperty('heic:cicp', implode('/', $cicp));
		}
	}

	public function info(): array
	{
		if ($this->isAvailable() === false) {
			return parent::info();
		}

		// `ImageMagick 7.1.2-31 Q16-HDRI …`
		$version = explode(' ', Image::getVersion()['versionString']);

		return [
			'library' => 'ImageMagick',
			'version' => $version[1],
		];
	}

	public function isAvailable(): bool
	{
		return
			extension_loaded('imagick') === true &&
			defined(Image::class . '::RESOURCETYPE_LISTLENGTH') === true;
	}

	/**
	 * Whether ImageMagick has a coder for the format;
	 * loads only that coder, where listing formats
	 * would load all of them
	 *
	 * @todo not canRead, canWrite?
	 */
	private static function knows(string $name): bool
	{
		$image = new Image();

		try {
			return $image->setFormat($name);
		} catch (ImagickException) {
			return false;
		}
	}

	/**
	 * Sets ImageMagick's resource limits, which hold for
	 * the whole process, and returns the ones they replace
	 *
	 * @param array<int, int> $limits
	 * @return array<int, int>
	 */
	private static function limit(array $limits): array
	{
		$previous = [];

		foreach ($limits as $type => $value) {
			$limit = Image::getResourceLimit($type);

			$previous[$type] = $limit < PHP_INT_MAX ? (int)$limit : PHP_INT_MAX;

			Image::setResourceLimit($type, $value);
		}

		return $previous;
	}

	/**
	 * The threads, and the `limit` setting for all frames together
	 *
	 * @return array<int, int>
	 */
	private function limits(Plan $plan): array
	{
		$limits = [
			Image::RESOURCETYPE_THREAD => (int)$this->settings['threads']
		];

		/** @var int|null $limit */
		$limit = $this->settings['limit'];

		if ($limit === null) {
			return $limits;
		}

		$source = $plan->source->size();
		$pixels = $source->pixels();

		// libheif decodes the coded image, larger than the clean
		// aperture, within the square of the shorter side limit
		$side = max($source->width, $source->height, (int)sqrt($limit));

		return $limits + [
			Image::RESOURCETYPE_WIDTH      => $side,
			Image::RESOURCETYPE_HEIGHT     => $side,
			// ImageMagick refuses a list as long as its limit
			Image::RESOURCETYPE_LISTLENGTH => intdiv($limit, $pixels) + 1,
			// spilled onto the disk, the pixel cache
			// holds `limit` pixels of four 32-bit channels
			Image::RESOURCETYPE_DISK       => $limit * 16,
		];
	}

	/**
	 * ImageMagick's name of the format
	 */
	private static function name(Format $format): string
	{
		return strtoupper($format->name);
	}

	/**
	 * Turns the stored pixels into the displayed image and says
	 * so, as encoders write the orientation the decoder read
	 */
	private static function orient(
		Image $frame,
		Orientation $orientation
	): void {
		match ($orientation) {
			Orientation::None           => true,
			Orientation::FlipHorizontal => $frame->flopImage(),
			Orientation::Rotate180      => $frame->rotateImage('none', 180),
			Orientation::FlipVertical   => $frame->flipImage(),
			Orientation::Transpose      => $frame->transposeImage(),
			Orientation::Rotate90       => $frame->rotateImage('none', 90),
			Orientation::Transverse     => $frame->transverseImage(),
			Orientation::Rotate270      => $frame->rotateImage('none', 270),
		};

		$frame->setImageOrientation(Image::ORIENTATION_TOPLEFT);
	}

	/**
	 * libheif applies `irot` and `imir` while decoding
	 */
	public function orients(Source $source): bool
	{
		return match ($source->format()) {
			Format::Avif,
			Format::Heic => true,
			default      => false
		};
	}

	/**
	 * @throws LimitException If the image exceeds ImageMagick's resource limits
	 * @throws ProcessException If ImageMagick fails
	 */
	public function process(Plan $plan, string $file): void
	{
		$limits   = $this->limits($plan);
		$previous = static::limit($limits);
		$time     = (int)ini_get('max_execution_time');

		// PHP's timer doesn't stop ImageMagick;
		// left set, as no later image of the request
		// may take longer, and PHP_INT_MAX overflows its clock
		if ($time > 0) {
			Image::setResourceLimit(Image::RESOURCETYPE_TIME, $time);
		}

		try {
			$image = static::decode($plan);

			foreach ($image as $frame) {
				$this->frame($frame, $plan);
			}

			static::encode($image, $plan, $file);
		} catch (ImagickException $exception) {
			$message = 'ImageMagick failed on ' . $plan->source->file . ': ' . $exception->getMessage();

			if ($exception->getCode() === self::LIMIT) {
				throw new LimitException(
					message: $message,
					previous: $exception
				);
			}

			throw new ProcessException(
				message: $message,
				previous: $exception
			);
		} finally {
			static::limit($previous);
		}
	}

	/**
	 * Removes all profiles but the color profile,
	 * if kept, and the properties the output's encoder
	 * doesn't read, as other encoders would write them as metadata
	 */
	private static function strip(Image $frame, Plan $plan): void
	{
		foreach ($frame->getImageProfiles('*', false) as $name) {
			if ($plan->output->icc === false || $name !== 'icc') {
				$frame->removeImageProfile((string)$name);
			}
		}

		foreach ($frame->getImageProperties('*', false) as $name) {
			if (in_array($plan->output->format, self::KEEP[$name] ?? [], true) === false) {
				$frame->deleteImageProperty($name);
			}
		}
	}

	/**
	 * A lookup table for `clutImage()`: the function's
	 * values for evenly spaced inputs from 0 to 1
	 *
	 * @param Closure(float): float $function
	 */
	private static function table(
		int $length,
		Closure $function
	): Image {
		$values = [];
		$table  = new Image();

		for ($step = 0; $step < $length; $step++) {
			$value    = $function($step / ($length - 1));
			$values[] = (int)round(65535.0 * $value);
		}

		// a 16-bit PGM
		$table->readImageBlob(
			'P5 ' . $length . ' 1 65535 ' . pack('n*', ...$values)
		);

		return $table;
	}

	/**
	 * PQ or HLG as sRGB, for encoders that would write
	 * the signal's values as they are: in linear light,
	 * from the source's primaries to BT.709,
	 * then each channel tone-mapped and encoded
	 */
	private function tone(Image $frame, Plan $plan): void
	{
		// AVIF and HEIC keep the signal
		// where ImageMagick read it, and so writes it
		$keep =
			in_array($plan->output->format, self::KEEP['heic:cicp'], true) === true &&
			$frame->getImageProperties('heic:cicp', false) !== [];

		if ($plan->source->isHdr() === false || $keep === true) {
			return;
		}

		[$primaries, $transfer] = $plan->source->cicp();

		$channels = Image::CHANNEL_RED | Image::CHANNEL_GREEN | Image::CHANNEL_BLUE;
		$matrix   = Hdr::matrix($primaries);

		// a step per 10-bit signal; linear light needs more,
		// as sRGB bends near black
		$light = $this->cache(
			'light ' . $transfer,
			fn (): Image => static::table(
				length:   1024,
				function: fn (float $signal): float => Hdr::light($transfer, $signal)
			)
		);

		$srgb = $this->cache(
			'srgb',
			fn (): Image => static::table(4096, Hdr::srgb(...))
		);

		$frame->clutImage($light, $channels);
		$frame->colorMatrixImage([
			...$matrix[0], 0.0, 0.0,
			...$matrix[1], 0.0, 0.0,
			...$matrix[2], 0.0, 0.0,
			0.0, 0.0, 0.0, 1.0, 0.0,
			0.0, 0.0, 0.0, 0.0, 1.0,
		]);
		$frame->clutImage($srgb, $channels);

		// a profile would describe the HDR signal
		$frame->profileImage('icc', null);
	}
}
