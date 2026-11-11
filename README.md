# Darkroom

Thumbnails for PHP. Darkroom resizes, crops, orients and converts images with GD, ImageMagick or libvips, and all three make the same thumb.

```php
$darkroom = new Darkroom\Darkroom();
$darkroom->process('photo.jpg', 'thumbs/photo.webp', ['width' => 800]);
```

> [!WARNING]
> Work in progress: the API may change without notice until 1.0.

- **Formats**: JPEG, PNG, GIF, WebP, AVIF, HEIC, JPEG XL and JPEG 2000, as long as the [driver](#drivers) supports them
- **Quality**: one perceptual [scale](#quality), the same look in every format
- **Color**: color profiles kept, CMYK converted to sRGB, HDR tone-mapped
- **Orientation**: EXIF, HEIF and JPEG XL orientation applied to the pixels
- **Animation**: frames of GIF, WebP and JPEG XL kept where the driver can
- **Safety**: a pixel limit before decoding, resource limits, atomic writes

## Install

```sh
composer require getkirby/darkroom
```

PHP 8.4 or newer and one of the [drivers](#drivers).

## Usage

```php
use Darkroom\Darkroom;

$darkroom = new Darkroom();
$source   = $darkroom->open('photo.jpg');
$plan     = $darkroom->plan($source, ['width' => 400, 'height' => 300, 'crop' => true]);

// size, format and name are known before any pixel is decoded
$width = $plan->width();
$file  = 'thumbs/photo-' . $plan->key() . '.' . $plan->format->extension();

$darkroom->process($plan, $file);
```

| Method | Returns |
|---|---|
| `open($file)` | A [`Source`](src/Source.php), from the file's header: `format()`, `size()`, `orientation()`, `exif()`, `icc()`, `isAnimated()`, `isHdr()` |
| `plan($source, $options)` | A [`Plan`](src/Plan.php): the thumb's `width()`, `height()`, `format`, `quality` and `key()`; throws what `process()` would |
| `tryPlan($source, $options)` | The same, or `null` for a source that is unreadable or above the limit |
| `process($source, $file, $options)` | Writes the thumb in the format of the file's extension, creating directories; returns the `Plan`; takes a `Plan` instead of source and options |
| `format($source, $format, $allowed)` | The format a plan gets: `$format` if a driver writes it, else the source's if allowed, else the first allowed one a driver writes; `$allowed` defaults to [`Format::VIEWABLE`](src/Format.php): WebP, JPEG, PNG, GIF, AVIF |
| `info()` | Each driver's library, version and the formats it reads and writes |

`Options::from($plan->key())` reads a key back, so a thumb can be made later from its file name alone.

## Options

| Option | Value |
|---|---|
| `width`, `height` | Pixels; without `crop`, the thumb fits into both |
| `crop` | `true`, a position (`center`, `top`, `top left`, … `bottom right`), a focus point (`'70% 30%'`) or a [`Darkroom\Rect`](src/Rect.php) in pixels |
| `quality` | 1–100, see [Quality](#quality) |
| `format` | An extension or MIME type; with `process()`, it must match the file's extension |
| `blur` | Gaussian sigma in output pixels, 1–100; `true` is 10 |
| `sharpen` | 1–100; `true` is 50 |
| `grayscale` | `true`; also `greyscale` or `bw` |
| `interlace` | `true` for progressive JPEG, interlaced PNG and GIF |
| `icc` | `false` converts to sRGB, without a profile |

Thumbs are never enlarged, not even by a crop: it is exactly `width` × `height`, or the largest part of that ratio a smaller source has. A crop with one side is a square.

## Quality

`quality` is a [SSIMULACRA2](https://github.com/cloudinary/ssimulacra2) score: how close the thumb stays to its source, on one scale for every format. An average observer at 1:1 notices the distortion:

| Score | | |
|---|---|---|
| 90 | visually lossless | not even when flickering between the two |
| **85** | **excellent** (default) | not when switching in place |
| 80 | very high | not side by side |
| 70 | high | barely side by side, not without the source |
| 50 | medium | as slightly annoying artifacts |

Each driver turns the score into [its encoder's quality and chroma subsampling](src/Tools/Quality.php), so that the median [CID22](https://cloudinary.com/labs/cid22) validation image reaches it.

<details>
<summary>Encoder settings and sizes</summary>

| Score | JPEG | WebP | AVIF | HEIC | JPEG XL |
|---|--:|--:|--:|--:|--:|
| 70 | 61 | 65 | 49 | 41 | 63 |
| 80 | 82 | 85 | 63 | 51 | 80 |
| 85 | 90 | 92 | 74 | 57 | 87 |
| 90 | 93 | 99 | 88 | 67 | 93 |
| Size at 85 | 100 % | 92 % | 57 % | 64 % | 67 % |

Chroma is at half resolution (4:2:0) unless whole chroma (4:4:4) is smaller for the score: AVIF from 20, HEIC from 80, JPEG from 90. GD encodes AVIF on libgd's own scale, 82 at 85, and JPEG at 4:2:0 unless libgd keeps chroma whole itself. Sizes are the mean over the CID22 images; single images vary.

</details>

## Settings

```php
$darkroom = new Darkroom(
	drivers:  ['vips', 'imagick'],
	defaults: ['quality' => 80, 'format' => 'webp'],
	settings: ['limit' => 50_000_000],
);
```

| Key | In | Default | |
|---|---|---|---|
| `quality` | `defaults` | 85 | For thumbs without their own |
| `format` | `defaults` | | For plans without their own; else as `format()` picks |
| `interlace` | `defaults` | `false` | For thumbs without their own |
| `icc` | `defaults` | `true` | Keeps the color profile |
| `limit` | `settings` | 200 million | The most pixels of a source, all frames together; `null` for none |
| `threads` | `settings` | ImageMagick 1, libvips its own up to 4 | Threads per thumb; each costs memory |

## Drivers

| Driver | Needs | Good at |
|---|---|---|
| [`gd`](src/Drivers/Gd.php) | `ext-gd` | Runs almost everywhere |
| [`imagick`](src/Drivers/Imagick.php) | `ext-imagick`, ImageMagick 6.9.10 or 7.0.8 or newer | Animations, color profiles, CMYK and HDR |
| [`vips`](src/Drivers/Vips.php) | libvips 8.15 or newer, `ext-ffi`, `jcupitt/vips` | Speed and memory on large images |

`drivers` takes aliases, class names or [`Darkroom\Driver`](src/Driver.php) instances, by default `['imagick', 'gd', 'vips']`. Each thumb goes to the first available one that reads the source and writes the format. One that can't animate the format writes the first frame.


<details>
<summary>GD</summary>

Keeps only RGB color profiles, in JPEG, PNG and WebP, and keeps them even with `icc: false`, as it can't convert to sRGB. Checks the image against `memory_limit` before decoding and throws a `LimitException` instead of trying the next driver, so list it after `imagick` and `vips`. Sources it would get wrong, such as HDR ones, go to the next driver.

</details>

<details>
<summary>ImageMagick</summary>

Converts to sRGB for `icc: false` only when built with LCMS; without it, the profile stays. Reads and writes AVIF and HEIC through libheif's plugins, e.g. on Debian and Ubuntu `libheif-plugin-libde265`, `-x265`, `-dav1d` and `-aomenc`. ImageMagick 6.9.12-84 to 6.9.13-0 (Ubuntu 24.04) writes WebP at quality 75, whatever the setting.

</details>

<details>
<summary>libvips</summary>

Needs `ffi.enable = true` for web requests, as PHP allows FFI by default only on the command line. A new PHP-FPM worker starts libvips with its first thumb, in about 20–60 ms, so put `vips` first only where its speed matters. Hands HDR sources to the next driver, and JPEG XL animations before libvips 8.16.

</details>

### Formats

| | GD | ImageMagick | libvips |
|---|:-:|:-:|:-:|
| JPEG, PNG, GIF, WebP, AVIF | ✓ | ✓ | ✓ |
| HEIC, JPEG XL, JPEG 2000 | | ✓ | ✓ |
| Animation | | GIF, WebP, JPEG XL, AVIF | GIF, WebP, JPEG XL |


## How a thumb is made

1. **Read**: `open()` reads the header only: format, size, orientation, color profile.
2. **Plan**: the size is fitted, the crop placed, format and encoder settings chosen.
3. **Decode**: JPEGs at a reduced size where the driver can (ImageMagick, libvips).
4. **Process**: crop, resize, convert to sRGB where needed, orient, filter.
5. **Encode**: without metadata but the color profile; JPEG flattened on white.
6. **Write**: to a temporary file, renamed once complete.

## Scope

Darkroom won't do SVG, PDF, video, image editing (text, watermarks, shapes, color extraction), caching, URLs or job files.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE.md)
