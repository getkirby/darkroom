<?php

declare(strict_types = 1);

namespace Darkroom\Formats\Png;

use Darkroom\Containers\Bytes;
use Darkroom\Containers\Layout;
use Darkroom\Containers\Splice;
use Darkroom\Formats\Writer as Base;
use Darkroom\Meta\Blocks;

/**
 * @internal
 */
final class Writer extends Base
{
	/**
	 * The ancillary chunks that change how the image looks or prints:
	 * transparency, color, HDR display, animation and resolution
	 */
	protected const array KEEP = [
		'acTL', 'cHRM', 'cICP', 'cLLI', 'fcTL', 'fdAT',
		'gAMA', 'iCCP', 'mDCV', 'pHYs', 'sRGB', 'tRNS'
	];
	public const bool ORIENTS = true;

	/**
	 * An `iCCP` chunk of the profile: a name, a null byte, the compression
	 * method, deflate, and the profile in zlib's format; none without zlib
	 */
	protected static function profile(string $profile): string
	{
		if ($profile === '' || function_exists('gzcompress') === false) {
			return '';
		}

		$data = "icc\0\0" . (string)gzcompress($profile);

		return Layout::Png->chunk('iCCP', $data);
	}

	/**
	 * Keeps the critical chunks and those in `KEEP`, the profile unless
	 * the blocks have one, nothing after the end of the image;
	 * the blocks after the header
	 *
	 * @param resource $handle
	 * @param resource $out
	 */
	public static function write(
		$handle,
		$out,
		Blocks $blocks
	): void {
		$own    = $blocks->icc === null;
		$exif   = $blocks->exif === '' ? '' : Layout::Png->chunk('eXIf', $blocks->exif);
		$after  = $exif . self::profile($blocks->icc ?? '');
		$splice = new Splice($handle, $out);

		// the signature, then the chunks
		$splice->copy(0, 8);
		Bytes::seek($handle, 8);

		foreach (Layout::Png->spans($handle) as $type => [$start, $end]) {
			// critical chunks start with an uppercase letter
			$keep = match (true) {
				(ord($type[0]) & 0x20) === 0 => true,
				$type === 'iCCP'             => $own,
				default                      => in_array($type, self::KEEP, true)
			};

			if ($keep === true) {
				$splice->copy($start, $end);
			}

			if ($type === 'IHDR') {
				$splice->write($after);
			}

			if ($type === 'IEND') {
				break;
			}
		}

		$splice->flush();
	}
}
