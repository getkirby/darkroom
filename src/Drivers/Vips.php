<?php

namespace Darkroom\Drivers;

use Closure;
use Darkroom\Driver;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\ProcessException;
use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Source;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\FFI;
use Jcupitt\Vips\ForeignKeep;
use Jcupitt\Vips\Image;
use Jcupitt\Vips\Introspect;
use Jcupitt\Vips\Target;

/**
 * Loads the source shrunk to the plan's scale,
 * then cuts, orients and filters each frame;
 * needs libvips
 */
final class Vips extends Driver
{
	/**
	 * A white pixel, from libvips 8.18 through libheif 1.23 and aom 3.15
	 */
	private const string AVIF = <<<'BASE64'
		AAAAHGZ0eXBhdmlmAAAAAG1pZjFhdmlmbWlhZgAAANZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABw
		aWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAAA+gABAAAAAAAAAB8AAAAjaWlu
		ZgAAAAAAAQAAABVpbmZlAgAAAAABAABhdjAxAAAAAA5waXRtAAAAAAABAAAAVmlwcnAAAAA4aXBj
		bwAAAAxhdjFDgQAMAAAAABRpc3BlAAAAAAAAAAEAAAABAAAAEHBpeGkAAAAAAwgICAAAABZpcG1h
		AAAAAAAAAAEAAQOBAgMAAAAnbWRhdBIACggYAAaICGg0IDIRGUeHhiGAAGgAAJBAyRxgimo=
		BASE64;

	/**
	 * A white 64×64 image, from libvips 8.18 through libheif 1.23 and x265 4.3
	 */
	private const string HEIC = <<<'BASE64'
		AAAAHGZ0eXBoZWljAAAAAG1pZjFoZWljbWlhZgAAAUJtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABw
		aWN0AAAAAAAAAAAAAAAAAAAAACJpbG9jAAAAAERAAAEAAQAAAAABZgABAAAAAAAAABYAAAAjaWlu
		ZgAAAAAAAQAAABVpbmZlAgAAAAABAABodmMxAAAAAA5waXRtAAAAAAABAAAAwmlwcnAAAACkaXBj
		bwAAAHhodmNDAQNwAAAAkAAAAAAAHvAA/P34+AAADwNgAAEAGEABDAH//wNwAAADAJAAAAMAAAMA
		HroCQGEAAQArQgEBA3AAAAMAkAAAAwAAAwAeoCCBBZbqSSmubgIaDAgAAAMAyAAAAwAIQGIAAQAH
		RAHBcrAiQAAAABRpc3BlAAAAAAAAAEAAAABAAAAAEHBpeGkAAAAAAwgICAAAABZpcG1hAAAAAAAA
		AAEAAQOBAgMAAAAebWRhdAAAABIoAa8TgObIaFPqH7pl35Rho5g=
		BASE64;

	/**
	 * Whether two frames written in the format
	 * load back as an animation
	 */
	private function animates(Format $format): bool
	{
		$pixel  = static::pixel();
		$target = Target::newToMemory();

		// two different frames, as savers merge equal ones
		$strip = $pixel->embed(0, 0, 1, 2, ['extend' => 'black']);

		// JPEG XL keeps frames as an animation only with their delays
		$strip->setType(FFI::gtypes('VipsArrayInt'), 'delay', [100, 100]);

		try {
			$this->write($strip, $target, $format, ['page_height' => 1]);
			$data  = (string)$target->get('blob');
			$image = Image::newFromBuffer($data);
		} catch (VipsException) {
			return false;
		}

		if ($image->typeOf('delay') === 0) {
			return false;
		}

		/** @var list<int> $delays */
		$delays = $image->get('delay');

		// a loader of animations knows the delays of all frames
		return count($delays) === 2;
	}

	public function canAnimate(Format $format): bool
	{
		return $this->probe(
			'animate ' . $format->name,
			fn (): bool => $this->animates($format)
		);
	}

	public function canRead(Format|Source $input): bool
	{
		$format = $input instanceof Source ? $input->format() : $input;
		$reads  = $this->probe(
			'read ' . $format->name,
			fn (): bool => static::decodes($format)
		);

		if ($reads === false || $input instanceof Format) {
			return $reads;
		}

		// libvips reads no CICP,
		// so it would write HDR as 8-bit sRGB
		if ($input->isHdr() === true) {
			return false;
		}

		// libvips' HEIF loader doesn't know AVIF sequences,
		// ImageMagick's may
		if (
			$format === Format::Avif &&
			Image::findLoad($input->file) === null
		) {
			return false;
		}

		return
			$input->isAnimated() === false ||
			$this->plays($input) === true;
	}

	public function canWrite(Format $format): bool
	{
		return $this->probe(
			'write ' . $format->name,
			fn (): bool => $this->encodes($format)
		);
	}

	/**
	 * Whether a sample of the format loads,
	 * also where only ImageMagick reads it for libvips
	 */
	private static function decodes(Format $format): bool
	{
		try {
			$data    = static::sample($format);
			$image   = Image::newFromBuffer($data);
			$average = $image->avg();
		} catch (VipsException) {
			return false;
		}

		// libvips blacks out what the decoder fails on,
		// at times without an error
		return $average > 0;
	}

	protected function defaults(): array
	{
		return [
			'limit'   => null,
			'threads' => null,
		];
	}

	/**
	 * Whether a pixel can be written in the format,
	 * as libheif may lack the format's encoder
	 */
	private function encodes(Format $format): bool
	{
		$pixel  = static::pixel();
		$target = Target::newToMemory();

		try {
			$this->write($pixel, $target, $format, []);
		} catch (VipsException) {
			return false;
		}

		return true;
	}

	/**
	 * Grayscale, blur and sharpen, in this order
	 */
	private static function filter(Image $image, Plan $plan): Image
	{
		if ($plan->thumb->grayscale === true) {
			$image = static::gray($image);
		}

		if ($plan->thumb->blur !== null) {
			$image = $image->gaussblur($plan->thumb->blur);
		}

		if ($plan->thumb->sharpen !== null) {
			$image = $image->sharpen([
				'sigma' => $plan->thumb->sharpen / 100
			]);
		}

		return $image;
	}

	/**
	 * The image on white, blended by its alpha
	 */
	private static function flatten(Image $image): Image
	{
		if ($image->hasAlpha() === false) {
			return $image;
		}

		// in the range of the image's format
		$white = $image->format === 'ushort' ? 65535 : 255;

		return $image->flatten(['background' => [$white]]);
	}

	/**
	 * The frames to load: all if the plan keeps them
	 * and the loader gives their delays, else one;
	 * checked against the limit at the size libvips
	 * decodes them, as libnsgif grows a GIF's screen
	 * to a larger frame
	 *
	 * @throws LimitException If the frames exceed the limit
	 * @throws VipsException If libvips can't read the source
	 */
	private function frames(Plan $plan): int
	{
		/** @var int|null $limit */
		$limit  = $this->settings['limit'];
		$header = Image::newFromFile($plan->source->file);
		$timed  = $plan->frames === true ? static::timed($header) : 0;
		$frames = max(1, $timed);
		$pixels = $header->width * $header->height;

		if ($limit !== null && $frames * $pixels > $limit) {
			throw new LimitException(
				message: 'Frames above the limit of ' . $limit . ' pixels: ' . $plan->source->file
			);
		}

		return $frames;
	}

	/**
	 * Rec. 601 luma of the gamma-encoded values,
	 * as GD's; alpha is kept
	 */
	private static function gray(Image $image): Image
	{
		if ($image->bands < 3) {
			return $image;
		}

		$luma   = [0.299, 0.587, 0.114];
		$rows   = $image->hasAlpha() === true ? [[...$luma, 0], [0, 0, 0, 1]] : [$luma];
		$matrix = Image::newFromArray($rows);
		$gray   = $image->recomb($matrix);
		$gray   = $gray->cast($image->format);

		// recomb keeps the sRGB tag,
		// with which sharpening makes three bands
		return $gray->copy([
			'interpretation' => $image->format === 'ushort' ? 'grey16' : 'b-w'
		]);
	}

	public function info(): array
	{
		if ($this->isAvailable() === false) {
			return parent::info();
		}

		return [
			'library' => 'libvips',
			'version' => Config::version(),
		];
	}

	public function isAvailable(): bool
	{
		return $this->cache('available', static function (): bool {
			if (class_exists(Image::class) === false) {
				return false;
			}

			// without a language, libvips' gettext asks macOS
			// for one, which hangs forked PHP-FPM workers
			if (getenv('LANG') === false || getenv('LANG') === '') {
				putenv('LANG=C');
			}

			try {
				Config::version();
			} catch (VipsException) {
				return false;
			}

			return true;
		});
	}

	/**
	 * The source at the plan's scale,
	 * with all frames stacked if the plan keeps them
	 *
	 * @throws LimitException If the frames exceed the limit
	 */
	private function load(Plan $plan): Image
	{
		$file   = $plan->source->file;
		$scale  = $plan->scale();
		$frames = $this->frames($plan);

		// only then, as loaders without frames take no `n`
		if ($frames > 1) {
			$file .= '[n=-1]';
		}

		// forced, as the plan has already fitted the size
		return Image::thumbnail($file, $scale->width, [
			'height'    => $scale->height,
			'size'      => 'force',
			'no_rotate' => true,
		]);
	}

	/**
	 * The optional arguments of the saver
	 * that writes the suffix into a target
	 *
	 * @return list<string>
	 * @throws VipsException If there is none
	 */
	private function options(string $suffix): array
	{
		/** @var list<string> */
		return $this->cache($suffix, static function () use ($suffix): array {
			// a miss isn't an error to report later
			FFI::vips()->vips_error_freeze();

			/** @var string|null $saver */
			$saver = FFI::vips()->vips_foreign_find_save_target($suffix);

			FFI::vips()->vips_error_thaw();

			if ($saver === null) {
				throw new VipsException(
					message: 'No saver writes ' . $suffix . ' into a target'
				);
			}

			$introspect = new Introspect($saver);

			return $introspect->optional_input;
		});
	}

	/**
	 * Turns the stored pixels into the displayed image
	 */
	private static function orient(
		Image $image,
		Orientation $orientation
	): Image {
		if ($orientation === Orientation::None) {
			return $image;
		}

		$turns = $orientation->turns();

		// turns and flips read out of order, which a
		// source loaded line by line can't serve
		$image = $image->copyMemory();

		if ($turns > 0) {
			$image = $image->rot('d' . $turns * 90);
		}

		if ($orientation->flips() === true) {
			$image = $image->flip('horizontal');
		}

		return $image;
	}

	/**
	 * libheif applies HEIF's `irot` and `imir`,
	 * libjxl the orientation of JPEG XL
	 */
	public function orients(Source $source): bool
	{
		return match ($source->format()) {
			Format::Avif,
			Format::Heic,
			Format::Jxl => true,
			default     => false
		};
	}

	/**
	 * A white pixel, tagged sRGB
	 * as older HEIF savers reject `multiband`
	 */
	private static function pixel(): Image
	{
		$black = Image::black(1, 1, ['bands' => 3]);
		$white = $black->invert();

		return $white->copy(['interpretation' => 'srgb']);
	}

	/**
	 * Whether libvips gives the animation's frames
	 * with their delays; from the first animation
	 * of the format, as its loader decides
	 */
	private function plays(Source $source): bool
	{
		$format = $source->format();

		try {
			return $this->probe(
				'play ' . $format->name,
				static function () use ($source): bool {
					$image = Image::newFromFile($source->file);

					return static::timed($image) > 0;
				}
			);
		} catch (VipsException) {
			// a file libvips can't read tells nothing about its format
			return false;
		}
	}

	/**
	 * A cached capability, false while libvips isn't available
	 */
	private function probe(string $key, Closure $probe): bool
	{
		return $this->cache(
			$key,
			fn (): bool => $this->isAvailable() === true && $probe() === true
		);
	}

	/**
	 * @throws LimitException Before loading, if the frames exceed the limit
	 * @throws ProcessException If libvips fails
	 */
	public function process(Plan $plan, string $file): void
	{
		// a cache would serve a replaced source from memory
		Config::cacheSetMax(0);

		// for every thumb, as another instance may have set its own
		$threads = $this->threads();
		Config::concurrencySet($threads);

		// libvips' error buffer keeps what earlier calls left,
		// even successful ones, for the next exception's message
		FFI::vips()->vips_error_clear();

		try {
			$image  = $this->load($plan);
			$scale  = $plan->scale();
			$region = $plan->region($scale);
			$frames = [];

			// frames are stacked, each as high as the scale
			for ($top = 0; $top < $image->height; $top += $scale->height) {
				$frame = $image->crop($region->x, $top + $region->y, $region->width, $region->height);
				$frame = static::orient($frame, $plan->orientation);
				$frame = static::filter($frame, $plan);

				$frames[] = $frame;
			}

			$image = Image::arrayjoin($frames, ['across' => 1]);

			// the pixels are oriented; HEIF savers would
			// write the source's orientation regardless
			if ($image->typeOf('orientation') !== 0) {
				$image->remove('orientation');
			}

			// savers without alpha would flatten onto black
			if ($plan->output->format->isOpaque() === true) {
				$image = static::flatten($image);
			}

			$target = Target::newToFile($file);

			// JPEG with chroma at half resolution both ways (4:2:0),
			// which libvips writes whole from quality 90 on
			$this->write($image, $target, $plan->output->format, [
				'Q'              => $plan->output->quality,
				'interlace'      => $plan->output->interlace,
				'keep'           => $plan->output->icc === true ? ForeignKeep::ICC : ForeignKeep::NONE,
				'page_height'    => $plan->size->height,
				'subsample_mode' => $plan->output->format === Format::Jpeg ? 'on' : 'auto',
			]);
		} catch (VipsException $exception) {
			throw new ProcessException(
				message: 'libvips failed on ' . $plan->source->file . ': ' . $exception->getMessage(),
				previous: $exception
			);
		}
	}

	/**
	 * A small image in the format, embedded for AVIF
	 * and HEIC, as libheif may have a format's
	 * decoder but no encoder
	 *
	 * @throws VipsException If libvips can't write the format
	 */
	private static function sample(Format $format): string
	{
		$sample = match ($format) {
			Format::Avif => self::AVIF,
			Format::Heic => self::HEIC,
			default      => null
		};

		if ($sample !== null) {
			return base64_decode($sample);
		}

		$pixel = static::pixel();

		return $pixel->writeToBuffer('.' . $format->extension());
	}

	/**
	 * The setting, else libvips' default, at most four
	 * as each costs memory; read once, as setting it
	 * replaces libvips' default
	 */
	private function threads(): int
	{
		if ($this->settings['threads'] !== null) {
			return (int)$this->settings['threads'];
		}

		return $this->cache('threads', static function (): int {
			// php-vips declares no getter,
			// so it is found in the loaded libvips
			$vips = \FFI::cdef('int vips_concurrency_get(void);');

			/** @var int $threads */
			$threads = $vips->vips_concurrency_get();

			return min($threads, 4);
		});
	}

	/**
	 * The frames with their delays, from a header;
	 * none where the loader gives no delays,
	 * as JPEG XL's before libvips 8.16
	 */
	private static function timed(Image $image): int
	{
		// loaders that give delays also count the frames
		return $image->typeOf('delay') === 0 ? 0 : (int)$image->get('n-pages');
	}

	/**
	 * Writes the image in the format,
	 * with the options its saver knows
	 *
	 * @throws VipsException If libvips fails
	 */
	private function write(
		Image $image,
		Target $target,
		Format $format,
		array $options
	): void {
		$suffix = '.' . $format->extension();
		$known  = $this->options($suffix);

		// `keep` passes unchecked,
		// so a saver that can't strip metadata fails
		$options = [
			...array_intersect_key($options, array_flip($known)),
			'keep' => $options['keep'] ?? ForeignKeep::NONE,
		];

		$image->writeToTarget($target, $suffix, $options);
	}
}
