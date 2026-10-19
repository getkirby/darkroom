<?php

namespace Darkroom\Playground;

use Darkroom\Darkroom;
use Darkroom\Exception\UnsupportedException;
use Darkroom\Format;
use Darkroom\Orientation;
use Darkroom\Plan;
use Darkroom\Size;
use Darkroom\Source;
use Imagick;
use Throwable;

/**
 * One thumb of one source by one driver
 */
final class Cell
{
	/**
	 * @param $info The driver's entry of `Darkroom::info()`
	 */
	public function __construct(
		private readonly Darkroom $darkroom,
		private readonly array $info,
		private readonly Source $source,
		private readonly array $options,
	) {
	}

	/**
	 * Why the driver can't, by its probes
	 */
	private function cant(): string|null
	{
		if ($this->info['available'] !== true) {
			return 'unavailable';
		}

		$input = $this->source->format()->extension();
		$write = $this->info['formats']['write'];

		if (in_array($input, $this->info['formats']['read'], true) === false) {
			return 'can\'t read ' . $input;
		}

		// what the format doesn't tell, such as frames
		if (
			$write !== [] &&
			$this->darkroom->driver($this->source, $write[0]) === null
		) {
			return 'can\'t read this ' . $input;
		}

		$format = static::format($this->darkroom, $this->source, $this->options);
		$output = $format?->extension();

		if ($output === null) {
			$viewable = array_map(
				fn (Format $format): string => $format->extension(),
				Format::VIEWABLE
			);

			return 'can\'t write ' . implode(', ', $viewable);
		}

		if (in_array($output, $write, true) === false) {
			return 'can\'t write ' . $output;
		}

		return null;
	}

	/**
	 * What the library reads from a file's header, and its frames
	 */
	public static function facts(string $file): array
	{
		$source = new Source($file);

		try {
			$format = $source->format();
		} catch (UnsupportedException) {
			$format = null;
		}

		return [
			'format'      => $format?->extension(),
			'size'        => static::size($source->size()),
			'orientation' => $source->orientation()->value,
			'animated'    => $source->isAnimated(),
			'frames'      => static::frames($file),
			'bytes'       => filesize($file),
		];
	}

	private static function format(
		Darkroom $darkroom,
		Source $source,
		array $options
	): Format|null {
		if (isset($options['format']) === true) {
			return Format::from($options['format']);
		}

		try {
			return $darkroom->format($source);
		} catch (UnsupportedException) {
			return null;
		}
	}

	/**
	 * Frames in a file, as Imagick counts them; null without it
	 */
	private static function frames(string $file): int|null
	{
		if (extension_loaded('imagick') === false) {
			return null;
		}

		try {
			$image = new Imagick();
			$image->pingImage($file);

			return $image->getNumberImages();
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * What the output differs in from the plan
	 */
	private function issues(array $plan, array $output): array
	{
		$issues = [];
		$source = static::frames($this->source->file);

		if ($output['format'] !== $plan['format']) {
			$issues[] = 'is ' . ($output['format'] ?? 'no image') . ', planned ' . $plan['format'];
		}

		if ($output['size'] !== $plan['size']) {
			$issues[] = 'is ' . implode('×', $output['size']) . ', planned ' . implode('×', $plan['size']);
		}

		if (
			$plan['frames'] === true &&
			$output['animated'] === false
		) {
			$frames   = $output['frames'] ?? 1;
			$issues[] = $frames > 1
				? $frames . ' frames, not read as an animation'
				: 'lost the animation';
		}

		if (
			$plan['frames'] === false &&
			$output['animated'] === true
		) {
			$issues[] = 'animated, planned one frame';
		}

		if (
			$plan['frames'] === true &&
			$output['frames'] !== null &&
			$output['frames'] !== $source
		) {
			$issues[] = $output['frames'] . ' of ' . $source . ' frames';
		}

		if ($output['orientation'] !== Orientation::None->value) {
			$issues[] = 'orientation tag ' . $output['orientation'] . ' left';
		}

		return $issues;
	}

	/**
	 * Writes the thumb to `$path` plus the format's extension
	 * and checks it against its plan
	 *
	 * @param $url Where `$path` is served
	 */
	public function make(string $path, string $url): array
	{
		$reason = $this->cant();

		if ($reason !== null) {
			return [
				'state'  => 'unsupported',
				'issues' => [],
				'notes'  => [$reason],
			];
		}

		$format    = static::format($this->darkroom, $this->source, $this->options);
		$extension = '.' . $format->extension();
		$start     = hrtime(true);

		try {
			$plan = $this->darkroom->process($this->source->file, $path . $extension, $this->options);
		} catch (Throwable $exception) {
			return [
				'state'  => 'issues',
				'issues' => [$exception::class . ': ' . $exception->getMessage()],
				'notes'  => [],
			];
		}

		$time   = (hrtime(true) - $start) / 1e6;
		$plan   = static::plan($plan);
		$output = static::facts($path . $extension);
		$issues = $this->issues($plan, $output);
		$notes  = [];

		if (
			$plan['frames'] === false &&
			$this->source->isAnimated() === true
		) {
			$notes[] = 'first frame only';
		}

		return [
			'state'       => $issues === [] ? 'passed' : 'issues',
			'url'         => $url . $extension,
			'ms'          => round($time, 1),
			'output'      => $output,
			'delta'       => null,
			'differences' => [],
			'issues'      => $issues,
			'notes'       => $notes,
		];
	}

	/**
	 * What the playground checks of a plan,
	 * the only place that reads one
	 */
	public static function plan(Plan $plan): array
	{
		return [
			'size'   => static::size($plan->size),
			'format' => $plan->output->format->extension(),
			'frames' => $plan->frames,
		];
	}

	/**
	 * @return array{int, int}
	 */
	private static function size(Size $size): array
	{
		return [$size->width, $size->height];
	}
}
