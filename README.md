# Darkroom

A PHP library that reads, resizes, crops, orients and filters images into thumbnails. It runs on GD, Imagick or libvips.

```php
$darkroom = new Darkroom();
$source   = $darkroom->open('photo.jpg');
$plan     = $darkroom->plan($source, ['width' => 400]);

$darkroom->process($plan, 'thumbs/photo-400.webp');
```

> [!WARNING]
> Work in progress: the API may change without notice until 1.0.

## Install

```sh
composer require getkirby/darkroom
```

It needs PHP 8.3 or newer and one of the [drivers](#drivers).

## Usage

```php
use Darkroom\Darkroom;

$darkroom = new Darkroom();
$options  = ['width' => 800, 'height' => 600, 'crop' => true];
$plan     = $darkroom->process('photo.jpg', 'thumbs/photo.webp', $options);

$source = $darkroom->open('photo.jpg');
$plan   = $darkroom->plan($source, ['width' => 400]);
$format = $darkroom->format($source);

$darkroom->process($plan, 'thumbs/photo-400.' . $format->extension());
```

`::process()` writes the thumb in the format of the target's extension, creates missing directories and only shows the file once it is complete. It returns the `Plan`, with the thumb's `->size`.

`::plan()` gives the same without processing, e.g. for `->width` and `->height` attributes; for several plans, pass the `Source` from `::open()`, which reads the file's header once.

`::format()` picks the output format before there is a file name: the source's format if it is allowed and a driver converts to it, else the first allowed one a driver converts to. The allowed formats default to `Format::VIEWABLE`, those every major browser shows: WebP, JPEG, PNG, GIF and AVIF.

## Options

| Option | Value |
|---|---|
| `width`, `height` | Pixels. Without `crop`, the image fits into both. |
| `crop` | `true` (center), a position (`top left`, `top`, … `bottom right`) or a focus point (`70% 30%`). |
| `blur` | Gaussian sigma in output pixels, 1–100; `true` is 10. |
| `grayscale` | `true`; also as `greyscale` or `bw`. |
| `sharpen` | 1–100; `true` is 50. |
| `quality` | 1–100, for JPEG, WebP, AVIF, HEIC and JPEG XL. |
| `format` | An extension or MIME type; in `process()`, it must match the target's extension. |
| `interlace` | Progressive JPEG, interlaced PNG and GIF. |

Images are never enlarged, not even by a crop: it is exactly `width` × `height`, or the largest rectangle of that ratio in a smaller source. A crop with one side only is a square.

## Settings

| Setting | Default | |
|---|---|---|
| `quality` | 90 | For thumbs without their own. |
| `interlace` | `false` | For thumbs without their own. |
| `icc` | `true` | Keeps the source's color profile. |
| `threads` | Imagick 1, vips libvips' choice up to 4 | Threads per thumb; each costs memory. |

`quality`, `interlace` and `icc` go into `new Darkroom(defaults: […])`, `threads` and `limit` into `settings: […]`, where `limit` is the most pixels a source, or all frames of an animation together, may have: 200 million by default, `null` for no limit.

## Drivers

| Driver | Needs | Good at |
|---|---|---|
| `gd` | `ext-gd` | Runs almost everywhere. |
| `imagick` | `ext-imagick`, ImageMagick 6.9.10 or 7.0.8 or newer | Large JPEGs, animations, color profiles, CMYK and HDR. |
| `vips` | libvips 8.15 or newer, `ext-ffi`, `jcupitt/vips` | Speed and memory on large images. |

The `drivers` option can be a list of aliases, class names or direct `Darkroom\Driver` instances (default: `['imagick', 'gd', 'vips']`). Darkroom automatically selects the first available one that reads the source and writes the target's format.

**Gd** writes the first frame of an animation and keeps only RGB color profiles, between JPEG, PNG and WebP. It throws a `LimitException` for an image too large for `memory_limit` instead of trying the next driver, so list it after `imagick` and `vips`. Sources it would get wrong, such as HDR ones, go to the next driver.

**Imagick** keeps the frames of animated GIF, WebP and JPEG XL, but not of APNG, AVIF or HEIC. It reads and writes AVIF and HEIC through libheif's codec plugins, e.g. on Debian and Ubuntu `libheif-plugin-libde265`, `-x265`, `-dav1d` and `-aomenc`. ImageMagick 6.9.12-84 to 6.9.13-0 (Ubuntu 24.04) writes WebP at quality 75, whatever the setting.

**Vips** needs `ffi.enable = true` for web requests, as PHP allows FFI by default only on the command line. A new PHP-FPM worker starts libvips with its first thumb, in about 20–60 ms, so put `vips` first only where its speed advantages matter. It hands HDR sources to the next driver, and JPEG XL animations before libvips 8.16.

## Exceptions

- `Darkroom\Exception\Exception`: the base class, a `\RuntimeException`; also when the target can't be written.
- `UnsupportedException`: the source can't be read, or no driver converts between the formats.
- `LimitException`: the source is above `limit`, or too large for `memory_limit` in Gd.
- `ProcessException`: the image library failed; its exception is the previous one.
- `\InvalidArgumentException`: an invalid option, setting, driver or format.

## Scope

Darkroom won't do SVG, PDF, video, image editing (text, watermarks, shapes, color extraction), caching, URLs, job files or file naming.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE.md)
