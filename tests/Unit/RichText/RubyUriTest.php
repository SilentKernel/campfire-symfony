<?php

declare(strict_types=1);

namespace App\Tests\Unit\RichText;

use App\RichText\RichTextError;
use App\RichText\RubyUri;
use PHPUnit\Framework\TestCase;

/** Ruby's `URI.parse`, as the reference image answers. */
final class RubyUriTest extends TestCase
{
    public function testParsesLikeRuby(): void
    {
        $uri = RubyUri::parse('https://x.com/dhh/status/1?s=20');
        $this->assertNotNull($uri);
        $this->assertSame('x.com', $uri->host);
        $this->assertSame('s=20', $uri->query);
        $this->assertNull(RubyUri::parse('http://exa mple.com/ '));
        $this->assertNull(RubyUri::parse('https:/rooms/1')?->host);
        $this->assertSame('rooms/1', RubyUri::parse('https:rooms/1')?->opaque);
        $this->assertSame('', RubyUri::parse('https://')?->host);
        $this->assertSame('[::1]', RubyUri::parse('http://[::1]/x')?->host);
        $this->assertNotNull(RubyUri::parse('mailto:a@b.com'));
        $this->assertSame('https://x.com/a', RubyUri::parse('https://x.com:443/a')?->toS());
        $this->assertSame('http://X.com/a', RubyUri::parse('HTTP://X.com:80/a')?->toS());
        $this->assertSame('file:///etc', RubyUri::parse('FILE:///etc')?->toS());
    }

    public function testMailtoWithoutAnAddressRaises(): void
    {
        $this->expectException(RichTextError::class);
        RubyUri::parse('mailto:foo');
    }
}
