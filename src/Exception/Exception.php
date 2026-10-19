<?php

namespace Darkroom\Exception;

use RuntimeException;

/**
 * Also thrown when a file can't be written;
 * invalid options throw `\InvalidArgumentException`
 */
class Exception extends RuntimeException
{
}
