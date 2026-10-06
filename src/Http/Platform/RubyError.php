<?php

declare(strict_types=1);

namespace App\Http\Platform;

/**
 * Where the useragent gem (or Campfire's ApplicationPlatform) would raise a NoMethodError or an
 * ArgumentError, the port raises this, so a request fails (500) where the Rails app's would.
 */
final class RubyError extends \RuntimeException
{
    public const string NO_METHOD_ERROR = 'NoMethodError';
    public const string ARGUMENT_ERROR = 'ArgumentError';

    public function __construct(public readonly string $rubyClass, string $message = '')
    {
        parent::__construct('' === $message ? $rubyClass : $rubyClass.': '.$message);
    }

    public static function noMethod(string $method, string $receiver = 'nil'): self
    {
        return new self(self::NO_METHOD_ERROR, \sprintf("undefined method '%s' for %s", $method, $receiver));
    }

    public static function argument(string $message): self
    {
        return new self(self::ARGUMENT_ERROR, $message);
    }
}
