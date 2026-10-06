<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\User;
use App\Rails\KeyGenerator;
use App\Rails\SignedId;
use App\Twig\AvatarsExtension;
use App\Twig\HelpersExtension;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

/** Expected markup is copied from pages the reference app rendered (tests/fixtures/rails/layout/). */
final class ViewHelpersTest extends TestCase
{
    public function testImageTag(): void
    {
        $helpers = ViewFactory::helpers($this, new FakeViewContext());

        self::assertSame('<img aria-hidden="true" class="colorize--black" src="/assets/email-6c595bc5.svg" width="24" height="24" />', $helpers->imageTag('email.svg', ['aria' => ['hidden' => true], 'size' => 24, 'class' => 'colorize--black']));
        self::assertSame('<img alt="Campfire logo" width="256" height="216" src="/assets/campfire-icon-3d9986c5.png" />', $helpers->imageTag('campfire-icon.png', ['alt' => 'Campfire logo', 'width' => 256, 'height' => 216]));
        self::assertSame('<img src="/account/logo?v=1" width="20" height="30" />', $helpers->imageTag('/account/logo?v=1', ['size' => '20x30']));
    }

    public function testButtonTo(): void
    {
        $helpers = ViewFactory::helpers($this, new FakeViewContext());

        self::assertSame(
            '<form class="button_to" method="post" action="/account/join_code"><button class="btn btn--regenerate" type="submit"><i></i></button><input type="hidden" name="authenticity_token" value="token:post:/account/join_code" /></form>',
            $helpers->buttonTo(new Markup('<i></i>', 'UTF-8'), '/account/join_code', ['class' => 'btn btn--regenerate']),
        );
        self::assertSame(
            '<form class="button_to" method="post" action="/account/users/1"><input type="hidden" name="_method" value="delete" /><button class="btn" data-turbo-confirm="Sure? It can&#39;t be undone." type="submit">Remove</button><input type="hidden" name="authenticity_token" value="token:delete:/account/users/1" /></form>',
            $helpers->buttonTo('Remove', '/account/users/1', ['method' => 'delete', 'class' => 'btn', 'data' => ['turbo_confirm' => 'Sure? It can\'t be undone.']]),
        );
        self::assertSame(
            '<form class="button_to" method="post" action="/rooms/directs"><button class="direct" type="submit">x</button><input type="hidden" name="authenticity_token" value="token:post:/rooms/directs" /><input type="hidden" name="user_ids[]" value="7" /></form>',
            $helpers->buttonTo('x', '/rooms/directs', ['class' => 'direct', 'params' => ['user_ids' => [7]]]),
        );
    }

    public function testFormWith(): void
    {
        $helpers = ViewFactory::helpers($this, new FakeViewContext());

        self::assertSame(
            '<form class="flex flex-column gap" action="http://localhost:3999/session" accept-charset="UTF-8" method="post"><input type="hidden" name="authenticity_token" value="token:post:http://localhost:3999/session" />',
            $helpers->formWith(['url' => 'http://localhost:3999/session', 'class' => 'flex flex-column gap']),
        );
        self::assertSame(
            '<form data-controller="form" action="/account/users/1" accept-charset="UTF-8" method="post"><input type="hidden" name="_method" value="patch" /><input type="hidden" name="authenticity_token" value="token:patch:/account/users/1" />',
            $helpers->formWith(['url' => '/account/users/1', 'method' => 'patch', 'data' => ['controller' => 'form']]),
        );
        self::assertSame(
            '<form id="f" enctype="multipart/form-data" action="/x" accept-charset="UTF-8" method="get">',
            $helpers->formWith(['url' => '/x', 'method' => 'get', 'id' => 'f', 'multipart' => true]),
        );
    }

    public function testLinkTo(): void
    {
        $helpers = ViewFactory::helpers($this, new FakeViewContext());

        self::assertSame('<a class="btn" href="/a?b=1&amp;c=2">Go &amp; see</a>', $helpers->linkTo('Go & see', '/a?b=1&c=2', ['class' => 'btn']));
        self::assertSame('<a rel="nofollow" data-method="delete" href="/s">x</a>', $helpers->linkTo('x', '/s', ['method' => 'delete']));
    }

    public function testCsrfMetaTags(): void
    {
        self::assertSame(
            "<meta name=\"csrf-param\" content=\"authenticity_token\" />\n<meta name=\"csrf-token\" content=\"masked-token\" />",
            ViewFactory::helpers($this, new FakeViewContext())->csrfMetaTags(),
        );
    }

    public function testAvatarTagMatchesRails(): void
    {
        $context = new FakeViewContext(signedIds: new SignedId(new KeyGenerator('x')));
        $avatars = new AvatarsExtension($context, ViewFactory::helpers($this, $context), ViewFactory::urls());
        $david = Records::saved(new User('David'), 127326141, new \DateTimeImmutable('2026-01-02 16:00:00 UTC'));

        self::assertSame(
            '<a title="David" class="btn avatar" data-turbo-frame="_top" href="/users/127326141"><img aria-hidden="true" src="/users/eyJfcmFpbHMiOnsiZGF0YSI6MTI3MzI2MTQxLCJwdXIiOiJ1c2VyL2F2YXRhciJ9fQ--3251ea28ced80789ca57c1960ecf32bcb14808610e989e2f30602650e86264b0/avatar?v=20260102160000" width="48" height="48" /></a>',
            $avatars->avatarTag($david),
        );
        // Zlib.crc32("127326141") % 18 == 9 in Ruby
        self::assertSame('#736356', AvatarsExtension::avatarBackgroundColor($david));
    }

    public function testTranslationButtonMatchesRails(): void
    {
        $html = (string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/rails/layout/session_new.html');
        preg_match('~<details class="position-relative".*?</details>~s', $html, $rails);

        $extension = new HelpersExtension(ViewFactory::helpers($this, new FakeViewContext()), ViewFactory::assets($this), ViewFactory::urls());
        self::assertSame($rails[0], $extension->translationButton('email_address'));
    }

    public function testLocalDatetimeTag(): void
    {
        self::assertSame(
            '<time class="message__timestamp" datetime="2026-01-01T16:00:00Z" data-local-time-target="date"></time>',
            HelpersExtension::localDatetimeTag(new \DateTimeImmutable('2026-01-01 17:00:00', new \DateTimeZone('Europe/Paris')), 'date', ['class' => 'message__timestamp']),
        );
    }

    public function testButtonToCopyToClipboard(): void
    {
        self::assertSame(
            '<button class="btn" data-controller="copy-to-clipboard" data-action="copy-to-clipboard#copy" data-copy-to-clipboard-success-class="btn--success" data-copy-to-clipboard-content-value="http://x/join/a&amp;b">c</button>',
            HelpersExtension::buttonToCopyToClipboard('http://x/join/a&b', 'c'),
        );
    }
}
