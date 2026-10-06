<?php

declare(strict_types=1);

namespace App\Storage;

/** An unusable transformation (Rails raises ArgumentError or ImageProcessing::Error). */
final class InvalidVariation extends \InvalidArgumentException
{
}
