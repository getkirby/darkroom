<?php

namespace Darkroom;

use Darkroom\Exception\Exception;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\UnsupportedException;
use InvalidArgumentException;

/**
 * Opens, plans and processes images
 */
final class Darkroom
{
	/**
	 * For every thumb without its own
	 */
	public readonly Defaults $defaults;

	/**
	 * For the darkroom and its drivers
	 */
	public readonly Settings $settings;

	/**
	 * @var array<Driver>
	 */
	private array $instances;

	/**
	 * Temp files of running `::process()` calls
	 *
	 * @var array<string, true>|null
	 */
	private array|null $temps = null;

	/**
	 * @param array<string|class-string<Driver>|Driver> $drivers In order of preference
	 *
	 * @throws InvalidArgumentException For an invalid default or setting, or an unknown driver
	 */
	public function __construct(
		public readonly array $drivers = ['imagick', 'gd', 'vips'],
		Defaults|array $defaults = [],
		Settings|array $settings = [],
	) {
		$this->defaults  = Defaults::from($defaults);
		$this->settings  = Settings::from($settings);
		$this->instances = array_map($this->instance(...), $drivers);
	}

	/**
	 * Removes the temp files a fatal error left behind
	 */
	private function clean(): void
	{
		foreach (array_keys($this->temps ?? []) as $temp) {
			@unlink($temp);
		}
	}

	/**
	 * First available driver that reads `$input` and writes `$output`;
	 * a source also by what its format doesn't tell, such as frames
	 */
	public function driver(
		Format|Source|string $input,
		Format|string $output
	): Driver|null {
		$input  = $input instanceof Source ? $input : static::parse($input);
		$output = static::parse($output);

		foreach ($this->instances as $driver) {
			if (
				$driver->isAvailable() === true &&
				$driver->canRead($input) === true &&
				$driver->canWrite($output) === true
			) {
				return $driver;
			}
		}

		return null;
	}

	/**
	 * The output format, for callers without a file name yet
	 *
	 * @param array<Format|string> $allowed In order of preference after the source's format
	 *
	 * @throws InvalidArgumentException If `$requested` isn't allowed
	 * @throws UnsupportedException Without a driver for the format
	 */
	public function format(
		Source $source,
		Format|string|null $requested = null,
		array $allowed = Format::VIEWABLE
	): Format {
		$input   = $source->format();
		$allowed = array_map(static::parse(...), $allowed);

		if ($requested !== null) {
			$requested = static::parse($requested);

			if (in_array($requested, $allowed, true) === false) {
				throw new InvalidArgumentException(
					message: 'Output format not allowed: ' . $requested->extension()
				);
			}
		}

		// the source's format, else the allowed ones in the caller's order
		$formats = $requested === null ? [$input, ...$allowed] : [$requested];

		foreach ($formats as $format) {
			if (
				in_array($format, $allowed, true) === true &&
				$this->driver($source, $format) !== null
			) {
				return $format;
			}
		}

		throw new UnsupportedException(
			message: 'No driver converts to an allowed format: ' . $source->file
		);
	}

	/**
	 * Details per driver, keyed like `$drivers`
	 */
	public function info(): array
	{
		$info = [];

		foreach ($this->instances as $key => $driver) {
			$available = $driver->isAvailable();
			$read      = [];
			$write     = [];

			// a library that isn't available can't be probed
			foreach ($available === true ? Format::cases() : [] as $format) {
				if ($driver->canRead($format) === true) {
					$read[] = $format->extension();
				}

				if ($driver->canWrite($format) === true) {
					$write[] = $format->extension();
				}
			}

			$info[$key] = [
				'class'     => $driver::class,
				'available' => $available,
				...$driver->info(),
				'formats'   => [
					'read'  => $read,
					'write' => $write,
				],
			];
		}

		return $info;
	}

	/**
	 * @throws InvalidArgumentException For an unknown driver
	 */
	private function instance(Driver|string $driver): Driver
	{
		if ($driver instanceof Driver) {
			return $driver;
		}

		$class = __NAMESPACE__ . '\\Drivers\\' . ucfirst($driver);

		if (class_exists($class) === false) {
			$class = $driver;
		}

		if (is_subclass_of($class, Driver::class) === false) {
			throw new InvalidArgumentException(
				message: 'Unknown driver: ' . $driver
			);
		}

		return new $class($this->settings->toArray());
	}

	/**
	 * Headers only, read when asked
	 */
	public function open(string $file): Source
	{
		return new Source($file);
	}

	private static function parse(Format|string $format): Format
	{
		if (is_string($format) === true) {
			return Format::from($format);
		}

		return  $format;
	}

	/**
	 * For several thumbs of a file, pass the `Source` from `open()`
	 */
	public function plan(Source|string $source, Options|array $options): Plan
	{
		if (is_string($source) === true) {
			$source = $this->open($source);
		}

		$options = Options::from($options);

		return Plan::for($source, $options, $this->defaults);
	}

	/**
	 * Writes the thumb atomically, in the format of `$dst`'s extension
	 *
	 * @throws UnsupportedException For an unreadable source or without a driver
	 * @throws LimitException Above the pixel limit
	 * @throws InvalidArgumentException For invalid options or format, or a plan with options
	 */
	public function process(
		Plan|Source|string $src,
		string $dst,
		Options|array $options = []
	): Plan {
		if ($src instanceof Plan) {
			if ($options !== []) {
				throw new InvalidArgumentException(
					message: 'Either a plan or options, not both'
				);
			}

			$options = $src->options;
			$src   = $src->source;
		}

		$limit  = $this->settings->limit ?? PHP_INT_MAX;
		$source = $src instanceof Source ? $src : $this->open($src);
		$input  = $source->format();
		$pixels = $source->size()->pixels();

		if ($pixels === 0) {
			throw new UnsupportedException(
				message: 'Image without a readable size: ' . $source->file
			);
		}

		if ($pixels > $limit) {
			throw new LimitException(
				message: 'Image above the limit of ' . $limit . ' pixels: ' . $source->file
			);
		}

		$options = Options::from($options);

		$format = Format::from(pathinfo($dst, PATHINFO_EXTENSION));

		if ($options->format !== null && $options->format !== $format) {
			throw new InvalidArgumentException(
				message: 'Format ' . $options->format->extension() . ' doesn\'t match: ' . $dst
			);
		}

		$driver = $this->driver($source, $format);

		if ($driver === null) {
			throw new UnsupportedException(
				message: 'No driver converts ' . $input->extension() . ' to ' . $format->extension()
			);
		}

		$options = Options::from([...$options->toArray(), 'format' => $format]);
		$plan  = Plan::for($source, $options, $this->defaults, $driver);

		$this->write($driver, $plan, $dst);

		return $plan;
	}

	private function write(
		Driver $driver,
		Plan $plan,
		string $file
	): void {
		$dir  = dirname($file);
		$temp = $dir . '/.' . basename($file) . '.tmp-' . bin2hex(random_bytes(6));

		// another process may create it at the same time
		if (
			is_dir($dir) === false &&
			@mkdir($dir, 0777, true) === false &&
			is_dir($dir) === false
		) {
			throw new Exception(
				message: 'Can\'t create the directory: ' . $dir
			);
		}

		// once per instance, as shutdown functions can't be removed
		if ($this->temps === null) {
			register_shutdown_function($this->clean(...));
		}

		$this->temps[$temp] = true;

		try {
			$driver->process($plan, $temp);

			if (@rename($temp, $file) === false) {
				throw new Exception(
					message: 'Can\'t move the thumb to: ' . $file
				);
			}
		} finally {
			unset($this->temps[$temp]);

			if (is_file($temp) === true) {
				unlink($temp);
			}
		}
	}
}
