<?php

namespace Darkroom\Tools;

/**
 * PQ and HLG light for SDR:
 * up to PEAK, each channel tone-mapped by BT.2390's EETF
 * to the reference white of 203 cd/m² (BT.2408)
 *
 * @internal
 * @psalm-type Row = array{float, float, float}
 * @psalm-type Matrix = array{Row, Row, Row}
 * @psalm-type Chromaticity = array{float, float}
 */
final class Hdr
{
	/**
	 * PQ's constants (SMPTE ST 2084)
	 */
	protected const float C1 = 0.8359375;
	protected const float C2 = 18.8515625;
	protected const float C3 = 18.6875;

	/**
	 * CIE xy of the white of all primaries
	 */
	protected const array D65 = [0.3127, 0.329];

	/**
	 * HLG's transfer characteristics (H.273)
	 */
	public const int HLG = 18;

	/**
	 * PQ's exponents (SMPTE ST 2084)
	 */
	protected const float M1 = 0.1593017578125;
	protected const float M2 = 78.84375;

	/**
	 * The most light kept, in cd/m²:
	 * a usual mastering peak and HLG's nominal one
	 */
	protected const float PEAK = 1000.0;

	/**
	 * PQ's transfer characteristics (H.273)
	 */
	public const int PQ = 16;

	/**
	 * CIE xy of red, green and blue per color primaries (H.273):
	 * BT.709, BT.2020 and Display P3
	 */
	protected const array PRIMARIES = [
		1  => [[0.64, 0.33], [0.3, 0.6], [0.15, 0.06]],
		9  => [[0.708, 0.292], [0.17, 0.797], [0.131, 0.046]],
		12 => [[0.68, 0.32], [0.265, 0.69], [0.15, 0.06]],
	];

	/**
	 * The light SDR shows as white, in cd/m²
	 */
	protected const float WHITE = 203.0;

	/**
	 * PQ's EOTF: a signal from 0 to 1 in cd/m²
	 */
	protected static function decode(float $signal): float
	{
		$signal = min(max($signal, 0.0), 1.0);
		$power  = $signal ** (1.0 / self::M2);
		$ratio  = max($power - self::C1, 0.0) / (self::C2 - self::C3 * $power);

		return 10000.0 * $ratio ** (1.0 / self::M1);
	}

	/**
	 * BT.2390's EETF from PEAK to WHITE on PQ signals:
	 * the same up to a knee, then a spline that ends in WHITE
	 */
	protected static function eetf(float $signal): float
	{
		$peak  = static::encode(self::PEAK);
		$white = static::encode(self::WHITE) / $peak;
		$knee  = 1.5 * $white - 0.5;
		$value = min($signal / $peak, 1.0);

		if ($value <= $knee) {
			return $value * $peak;
		}

		$t = ($value - $knee) / (1.0 - $knee);

		$value =
			(2.0 * $t ** 3.0 - 3.0 * $t ** 2.0 + 1.0) * $knee +
			($t ** 3.0 - 2.0 * $t ** 2.0 + $t) * (1.0 - $knee) +
			(-2.0 * $t ** 3.0 + 3.0 * $t ** 2.0) * $white;

		return $value * $peak;
	}

	/**
	 * PQ's inverse EOTF: cd/m² as a signal from 0 to 1
	 */
	protected static function encode(float $light): float
	{
		$power = (max($light, 0.0) / 10000.0) ** self::M1;

		return ((self::C1 + self::C2 * $power) / (1.0 + self::C3 * $power)) ** self::M2;
	}

	/**
	 * @param Matrix $matrix
	 * @return Matrix
	 */
	protected static function invert(array $matrix): array
	{
		[[$a, $b, $c], [$d, $e, $f], [$g, $h, $i]] = $matrix;

		$determinant = $a * ($e * $i - $f * $h) - $b * ($d * $i - $f * $g) + $c * ($d * $h - $e * $g);
		$factor      = 1.0 / $determinant;

		$adjugate = [
			[$e * $i - $f * $h, $c * $h - $b * $i, $b * $f - $c * $e],
			[$f * $g - $d * $i, $a * $i - $c * $g, $c * $d - $a * $f],
			[$d * $h - $e * $g, $b * $g - $a * $h, $a * $e - $b * $d],
		];

		return static::scale($adjugate, [$factor, $factor, $factor]);
	}

	/**
	 * The light of a PQ or HLG signal as a share of PEAK, at most 1;
	 * HLG with its system gamma of 1.2 on each channel, not on luminance
	 */
	public static function light(int $transfer, float $signal): float
	{
		if ($transfer === self::HLG) {
			$scene = static::scene($signal);

			return $scene ** 1.2;
		}

		$light = static::decode($signal);

		return min($light, self::PEAK) / self::PEAK;
	}

	/**
	 * From linear light in the primaries to linear BT.709;
	 * unknown primaries count as BT.2020's
	 *
	 * @return Matrix
	 */
	public static function matrix(int $primaries): array
	{
		$from    = static::xyz(self::PRIMARIES[$primaries] ?? self::PRIMARIES[9]);
		$to      = static::xyz(self::PRIMARIES[1]);
		$inverse = static::invert($to);

		return static::multiply($inverse, $from);
	}

	/**
	 * @param Matrix $a
	 * @param Matrix $b
	 * @return Matrix
	 */
	protected static function multiply(array $a, array $b): array
	{
		$product = [];

		foreach ($a as $row) {
			$product[] = [
				$row[0] * $b[0][0] + $row[1] * $b[1][0] + $row[2] * $b[2][0],
				$row[0] * $b[0][1] + $row[1] * $b[1][1] + $row[2] * $b[2][1],
				$row[0] * $b[0][2] + $row[1] * $b[1][2] + $row[2] * $b[2][2],
			];
		}

		/** @var Matrix */
		return $product;
	}

	/**
	 * CIE XYZ of a chromaticity, with a luminance of 1
	 *
	 * @param Chromaticity $xy
	 * @return Row
	 */
	protected static function point(array $xy): array
	{
		[$x, $y] = $xy;

		return [$x / $y, 1.0, (1.0 - $x - $y) / $y];
	}

	/**
	 * The matrix with each column multiplied by its factor
	 *
	 * @param Matrix $matrix
	 * @param Row $factors
	 * @return Matrix
	 */
	protected static function scale(array $matrix, array $factors): array
	{
		[$x, $y, $z] = $factors;

		return [
			[$matrix[0][0] * $x, $matrix[0][1] * $y, $matrix[0][2] * $z],
			[$matrix[1][0] * $x, $matrix[1][1] * $y, $matrix[1][2] * $z],
			[$matrix[2][0] * $x, $matrix[2][1] * $y, $matrix[2][2] * $z],
		];
	}

	/**
	 * HLG's inverse OETF: a signal as scene light from 0 to 1
	 */
	protected static function scene(float $signal): float
	{
		$a = 0.17883277;
		$b = 1.0 - 4.0 * $a;
		$c = 0.5 - $a * log(4.0 * $a);

		if ($signal <= 0.5) {
			return max($signal, 0.0) ** 2.0 / 3.0;
		}

		return (exp((min($signal, 1.0) - $c) / $a) + $b) / 12.0;
	}

	/**
	 * The sRGB signal of a share of PEAK, tone-mapped to WHITE
	 */
	public static function srgb(float $light): float
	{
		$signal = static::encode($light * self::PEAK);
		$mapped = static::eetf($signal);
		$white  = static::decode($mapped) / self::WHITE;

		if ($white <= 0.0031308) {
			return 12.92 * $white;
		}

		return 1.055 * $white ** (1.0 / 2.4) - 0.055;
	}

	/**
	 * From linear light in the primaries to CIE XYZ, with D65 as white
	 *
	 * @param array{Chromaticity, Chromaticity, Chromaticity} $primaries
	 * @return Matrix
	 */
	protected static function xyz(array $primaries): array
	{
		$red     = static::point($primaries[0]);
		$green   = static::point($primaries[1]);
		$blue    = static::point($primaries[2]);
		$white   = static::point(self::D65);
		$columns = [
			[$red[0], $green[0], $blue[0]],
			[$red[1], $green[1], $blue[1]],
			[$red[2], $green[2], $blue[2]],
		];

		// the primaries' luminances that add up to white
		$inverse = static::invert($columns);
		$weights = array_map(
			fn (array $row): float => $row[0] * $white[0] + $row[1] * $white[1] + $row[2] * $white[2],
			$inverse
		);

		return static::scale($columns, $weights);
	}
}
