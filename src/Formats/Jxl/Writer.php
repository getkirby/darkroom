<?php

declare(strict_types = 1);

namespace Darkroom\Formats\Jxl;

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
	 * The boxes of the image: signature, file type, level,
	 * the codestream, whole or in parts, and its frame index
	 */
	protected const array KEEP = ['JXL ', 'ftyp', 'jxlc', 'jxli', 'jxll', 'jxlp'];

	/**
	 * Keeps the boxes in `KEEP` and adds the blocks' `Exif` box after the
	 * file type, in a container for a bare codestream; not the data to
	 * rebuild a JPEG, which holds that JPEG's meta; the codestream
	 * holds orientation and color profile
	 *
	 * @param resource $handle
	 * @param resource $out
	 */
	public static function write(
		$handle,
		$out,
		Blocks $blocks
	): void {
		$exif   = $blocks->exif === '' ? '' : Layout::Box->chunk('Exif', pack('N', 0) . $blocks->exif);
		$splice = new Splice($handle, $out);

		rewind($handle);

		$head = (string)fread($handle, 12);

		rewind($handle);

		if ($head === Reader::SIGNATURE) {
			foreach (Layout::Box->spans($handle) as $type => [$start, $end]) {
				if (in_array($type, self::KEEP, true) === true) {
					$splice->copy($start, $end);
				}

				if ($type === 'ftyp') {
					$splice->write($exif);
				}
			}

			$splice->flush();
			return;
		}

		// a bare codestream in a container, after the EXIF data,
		// in a last box without a size, to the end of the file
		if ($exif !== '' && str_starts_with($head, Codestream::SIGNATURE) === true) {
			$ftyp = Layout::Box->chunk('ftyp', 'jxl ' . pack('N', 0) . 'jxl ');

			fwrite($out, Reader::SIGNATURE . $ftyp . $exif . pack('N', 0) . 'jxlc');
		}

		stream_copy_to_stream($handle, $out);
	}
}
