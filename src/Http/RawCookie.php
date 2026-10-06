<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * A Set-Cookie header already formatted the way Rack formats it (App\Rails\RailsCookies), sent
 * verbatim. Symfony's Cookie would reorder the attributes and add Max-Age.
 */
final class RawCookie extends Cookie
{
    public function __construct(string $name, private readonly string $header, string $path = '/', ?string $domain = null)
    {
        parent::__construct($name, null, 0, $path, $domain, false, false, true, null);
    }

    public function __toString(): string
    {
        return $this->header;
    }

    public function withHeader(string $header): self
    {
        return new self($this->getName(), $header, $this->getPath(), $this->getDomain());
    }
}
