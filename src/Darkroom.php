<?php

namespace Darkroom;

use Darkroom\Exception\Exception;
use Darkroom\Exception\LimitException;
use Darkroom\Exception\UnsupportedException;
use InvalidArgumentException;

final class Darkroom
{
	public const array DRIVERS = ['imagick', 'gd', 'vips'];

	public readonly Defaults $defaults;
	public readonly Settings $settings;

	/**
	 * @var array<Driver>
	 */
	protected array $instances;

	/**
	 * @var array<string, true>|null
	 */
	protected array|null $temps = null;

	/**
	 * @param array<string|class-string<Driver>|Driver> $drivers
	 */
	public function __construct(
		public readonly array $drivers = self::DRIVERS,
		Defaults|array $defaults = [],
		Settings|array $settings = [],
	) {
		$this->defaults  = Defaults::from($defaults);
		$this->settings  = Settings::from($settings);
		$this->instances = array_map($this->instance(...), $drivers);
	}

	/**
	 * @throws UnsupportedException Without a readable size
	 * @throws LimitException Above the pixel limit
	 */
	protected function check(Source $source): void
	{
		$limit  = $this->settings->limit ?? PHP_INT_MAX;
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
	}

	protected function clean(): void
	{
		foreach (array_keys($this->temps ?? []) as $temp) {
			@unlink($temp);
		}
	}

	/**
	 * First available driver that
	 * reads `$input` and writes `$output`
	 */
	public function driver(
		Format|Source|string $input,
		Format|string $output
	): Driver|null {
		$input  = $input instanceof Source ? $input : Format::from($input);
		$output = Format::from($output);

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
	 * @param array<Format|string> $allowed
	 *
	 * @throws InvalidArgumentException If `$format` isn't allowed
	 * @throws UnsupportedException Without a driver for the format
	 */
	public function format(
		Source $source,
		Format|string|null $format = null,
		array $allowed = Format::VIEWABLE
	): Format {
		$input   = $source->format();
		$allowed = array_map(Format::from(...), $allowed);

		if ($format !== null) {
			$format = Format::from($format);

			if (in_array($format, $allowed, true) === false) {
				throw new InvalidArgumentException(
					message: 'Output format not allowed: ' . $format->extension()
				);
			}
		}

		// the source's format, else the allowed ones in the caller's order
		$formats = $format === null ? [$input, ...$allowed] : [$format];

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
	protected function instance(Driver|string $driver): Driver
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

	public function open(string $file): Source
	{
		return new Source($file);
	}

	/**
	 * @throws UnsupportedException For an unreadable source
	 * @throws LimitException Above the pixel limit
	 */
	public function plan(
		Source|string $source,
		Options|array $options = []
	): Plan {
		if (is_string($source) === true) {
			$source = $this->open($source);
		}

		$this->check($source);

		$options = Options::from($options);

		return Plan::for($source, $options, $this->defaults);
	}

	/**
	 * Writes the thumb atomically,
	 * in the format of its `$dst`'s extension
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
			$src     = $src->source;
		}

		$source = $src instanceof Source ? $src : $this->open($src);
		$input  = $source->format();

		$this->check($source);

		$options = Options::from($options);
		$output  = Format::from(pathinfo($dst, PATHINFO_EXTENSION));

		if (
			$options->format !== null &&
			$options->format !== $output
		) {
			throw new InvalidArgumentException(
				message: 'Format ' . $options->format->extension() . ' doesn\'t match: ' . $dst
			);
		}

		$driver = $this->driver($source, $output);

		if ($driver === null) {
			throw new UnsupportedException(
				message: 'No driver converts ' . $input->extension() . ' to ' . $output->extension()
			);
		}

		$options = Options::from([...$options->toArray(), 'format' => $output]);
		$plan    = Plan::for($source, $options, $this->defaults, $driver);

		$this->write($driver, $plan, $dst);

		return $plan;
	}

	protected function write(
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
