<?php

namespace Darkroom;

use InvalidArgumentException;

/**
 * What a `Darkroom` and its drivers run with
 */
final readonly class Settings
{
	public const int LIMIT = 200_000_000;

	/**
	 * @param $limit Pixel cap for a source and all its frames
	 * @param $other Each driver's own settings, such as `threads`
	 *
	 * @throws InvalidArgumentException For a limit below 1
	 */
	public function __construct(
		public int|null $limit = self::LIMIT,
		public array $other = [],
	) {
		if ($limit !== null && $limit < 1) {
			throw static::error($limit);
		}
	}

	private static function error(mixed $limit): InvalidArgumentException
	{
		$value = is_scalar($limit) === true ? var_export($limit, true) : get_debug_type($limit);

		return new InvalidArgumentException(
			message: 'Invalid limit: ' . $value
		);
	}

	/**
	 * From settings as configuration gives them: `LIMIT`
	 * without a `limit`, none for `null`
	 *
	 * @throws InvalidArgumentException For an invalid limit
	 */
	public static function from(Settings|array $settings): self
	{
		if ($settings instanceof self) {
			return $settings;
		}

		$limit = array_key_exists('limit', $settings) === true ? $settings['limit'] : self::LIMIT;

		if ($limit !== null && is_int($limit) === false) {
			throw static::error($limit);
		}

		unset($settings['limit']);

		return new self($limit, $settings);
	}

	/**
	 * What each driver is constructed with
	 */
	public function toArray(): array
	{
		return [...$this->other, 'limit' => $this->limit];
	}
}
