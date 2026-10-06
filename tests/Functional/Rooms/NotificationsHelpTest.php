<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

/** The notifications help in every room page (reference/app/views/pwa/_{browser,system}_settings.html.erb). */
final class NotificationsHelpTest extends RoomsTestCase
{
    private const string ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';
    private const string MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
    private const string MAC_FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:140.0) Gecko/20100101 Firefox/140.0';

    public function testTheSwitchIsAnImageNotEscapedText(): void
    {
        $this->signIn('users.david');
        $html = (string) $this->get('/rooms/'.self::id('rooms.watercooler'), ['HTTP_USER_AGENT' => self::ANDROID_CHROME])->getContent();

        self::assertSame(3, preg_match_all('#<li>Tap <em><img alt="the switch" src="/assets/external/switch-[^"]+\.svg" width="22" height="22" /></em> (to|next to) #', $html));
        self::assertStringNotContainsString('&lt;img', $html);
    }

    public function testTheAppleMenuGlyph(): void
    {
        $this->signIn('users.david');
        foreach ([self::MAC_CHROME, self::MAC_FIREFOX] as $userAgent) {
            $html = (string) $this->get('/rooms/'.self::id('rooms.watercooler'), ['HTTP_USER_AGENT' => $userAgent])->getContent();

            // U+F8FF, the Apple logo in Apple fonts (bytes EF A3 BF in the ERB).
            self::assertStringContainsString("<li>Click <em aria-label=\"the Apple menu\">\u{F8FF}</em> in the top left.</li>", $html);
            self::assertStringNotContainsString('<em aria-label="the Apple menu"></em>', $html);
            self::assertMatchesRegularExpression('#<li>Click <em><img alt="the switch" src="/assets/external/switch-[^"]+\.svg" width="22" height="22" /></em> to <em>Allow notifications</em>.</li>#', $html);
        }
    }
}
