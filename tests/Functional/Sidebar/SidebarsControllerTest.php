<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sidebar;

use App\Cable\StreamNames;
use App\Domain\Rooms\Sidebar;
use App\Domain\Rooms\SidebarRenderer;
use App\Entity\User;
use App\Rails\TurboStreamName;
use App\Repository\UserRepository;
use App\Tests\Functional\Rooms\RoomsTestCase;
use Twig\Environment;

/** Users::SidebarsController (reference/app/controllers/users/sidebars_controller.rb). */
final class SidebarsControllerTest extends RoomsTestCase
{
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'user_sidebar'];

    public function testDirectAndSharedRooms(): void
    {
        $this->signIn('users.david');
        $page = $this->crawler($this->get('/users/me/sidebar', self::FRAME));

        // Directs by room.updated_at, newest first
        self::assertSame(
            ['list_rooms_direct_'.self::id('rooms.david_and_kevin'), 'list_rooms_direct_'.self::id('rooms.group_direct'), 'list_rooms_direct_'.self::id('rooms.david_and_jason')],
            $page->filter('#direct_rooms a.direct')->each(static fn ($a): string => (string) $a->attr('id')),
        );
        self::assertSame(['direct unread', 'direct unread', 'direct'], $page->filter('#direct_rooms a.direct')->each(static fn ($a): string => (string) $a->attr('class')));
        self::assertSame('J, K, and J', trim((string) preg_replace('/\s+/', ' ', str_replace('Ping with', '', $page->filter('#list_rooms_direct_'.self::id('rooms.group_direct').' .direct__author')->text()))));

        // Shared rooms by LOWER(name), invisible ones left out (Archive)
        self::assertSame(['All Pets', 'All Talk', 'Broken', 'Designers', 'HQ', 'Quiet Corner'], $page->filter('#shared_rooms a')->each(static fn ($a): string => trim($a->text())));
        self::assertSame(['All Talk'], $page->filter('#shared_rooms a.unread')->each(static fn ($a): string => trim($a->text())));
        self::assertCount(1, $page->filter('a.rooms__new-btn[href="/rooms/opens/new"]'));
    }

    public function testStreams(): void
    {
        $this->signIn('users.kevin');
        $page = $this->crawler($this->get('/users/me/sidebar', self::FRAME));
        $signer = $this->client->getContainer()->get(TurboStreamName::class);
        \assert($signer instanceof TurboStreamName);

        self::assertSame(
            [StreamNames::rooms(), StreamNames::userRooms(self::id('users.kevin'))],
            $page->filter('turbo-cable-stream-source')->each(static fn ($s): ?string => $signer->verify($s->attr('signed-stream-name'))),
        );
    }

    public function testPlaceholdersOfferUsersWithoutADirectRoom(): void
    {
        $this->signIn('users.david');
        $page = $this->crawler($this->get('/users/me/sidebar', self::FRAME));
        // 20 - (4 members of David's direct rooms + David again) = 15, of which 3 remain
        self::assertSame(
            ['/rooms/directs?user_ids%5B%5D='.self::id('users.bender'), '/rooms/directs?user_ids%5B%5D='.self::id('users.deploy_bot'), '/rooms/directs?user_ids%5B%5D='.self::id('users.loner')],
            $page->filter('form.button_to')->each(static fn ($f): string => (string) $f->attr('action')),
        );
        self::assertSame(['Bender', 'Deploy', 'Lonely'], $page->filter('form.button_to .txt-nowrap')->each(static fn ($s): string => trim(str_replace('Start a ping with', '', $s->text()))));
    }

    public function testUsersWithoutDirectRoomsGetNineteenPlaceholdersAtMost(): void
    {
        $this->signIn('users.loner');
        $page = $this->crawler($this->get('/users/me/sidebar', self::FRAME));
        self::assertCount(0, $page->filter('#direct_rooms a'));
        self::assertCount(6, $page->filter('form.button_to'));

        $this->connection()->executeStatement(
            "WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 30) INSERT INTO users (name, role, status, created_at, updated_at) SELECT 'Extra ' || i, 0, 0, '2026-02-10 10:00:00', '2026-02-10 10:00:00' FROM n",
        );
        self::assertCount(19, $this->crawler($this->get('/users/me/sidebar', self::FRAME))->filter('form.button_to'));
    }

    public function testTheNewRoomButtonFollowsTheAccountSetting(): void
    {
        $this->connection()->executeStatement('UPDATE accounts SET settings = ?', ['{"restrict_room_creation_to_administrators":true}']);
        $this->signIn('users.kevin');
        self::assertCount(0, $this->crawler($this->get('/users/me/sidebar', self::FRAME))->filter('a.rooms__new-btn'));
        $this->signIn('users.david');
        self::assertCount(1, $this->crawler($this->get('/users/me/sidebar', self::FRAME))->filter('a.rooms__new-btn'));
    }

    public function testDirectRoomsAreCachedPerMembershipVersion(): void
    {
        $this->signIn('users.david');
        $this->get('/users/me/sidebar', self::FRAME); // sets Current for the renderer's helpers
        $container = $this->client->getContainer();
        $sidebar = $container->get(Sidebar::class);
        $renderer = $container->get(SidebarRenderer::class);
        $twig = $container->get('twig');
        $david = $container->get(UserRepository::class)->find(self::id('users.david'));
        \assert($sidebar instanceof Sidebar && $renderer instanceof SidebarRenderer && $twig instanceof Environment && $david instanceof User);
        $render = static fn (): string => $renderer->directRooms($twig, $sidebar->memberships($david)[0], $david);

        $before = $render();
        self::assertStringContainsString('Jason', $before);
        $this->connection()->executeStatement("UPDATE users SET name = 'Jay' WHERE id = ?", [self::id('users.jason')]);
        // Like Rails' `cache membership`: a member's rename alone doesn't expire the fragment
        self::assertSame($before, $render());

        $this->connection()->executeStatement("UPDATE memberships SET updated_at = '2026-03-02 15:59:00' WHERE id = ?", [self::id('memberships.david_david_and_jason')]);
        $container->get('doctrine.orm.entity_manager')->clear();
        self::assertStringContainsString('Jay', $render());
    }

    public function testTheUserIdSegmentIsIgnored(): void
    {
        $this->signIn('users.kevin');
        $page = $this->crawler($this->get('/users/'.self::id('users.david').'/sidebar', self::FRAME));
        self::assertCount(0, $page->filter('#list_rooms_open_'.self::id('rooms.pets')));
    }

    public function testFullPageAndFormats(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/users/me/sidebar');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<nav id="nav">', (string) $response->getContent());
        self::assertSame(406, $this->get('/users/me/sidebar.json')->getStatusCode());
    }

    public function testSignedOut(): void
    {
        self::assertRedirectsTo('http://localhost/session/new', $this->get('/users/me/sidebar'));
    }
}
