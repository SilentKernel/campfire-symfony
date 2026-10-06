<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Twig\Environment;

/**
 * `render partial: "users/sidebars/rooms/direct", collection: …, cached: true` with the
 * partial's own `cache membership do … end`: each direct room's HTML is cached under its
 * membership's id and updated_at (Rails' cache key, so it goes stale exactly when Rails' does),
 * read in one multi-get, and only the misses query their members.
 */
final readonly class SidebarRenderer
{
    public const string TEMPLATE = 'users/sidebars/rooms/_direct.html.twig';

    /** Bump when the partial changes (Rails puts the template digest in the key). */
    private const string VERSION = 'v1';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private Sidebar $sidebar,
    ) {
    }

    /** @param list<SidebarRoom> $rooms direct rooms with their membership id and updated_at */
    public function directRooms(Environment $twig, array $rooms, User $user): string
    {
        if ([] === $rooms) {
            return '';
        }

        $keys = [];
        foreach ($rooms as $room) {
            $keys[$room->id] = self::cacheKey($room);
        }
        $items = [];
        foreach ($this->cache->getItems(array_values($keys)) as $key => $item) {
            $items[$key] = $item;
        }

        $misses = array_values(array_filter($rooms, static fn (SidebarRoom $room): bool => !$items[$keys[$room->id]]->isHit()));
        $members = $this->sidebar->directMembers(array_map(static fn (SidebarRoom $room): int => $room->id, $misses), $user);

        $html = '';
        foreach ($rooms as $room) {
            $item = $items[$keys[$room->id]];
            if ($item->isHit()) {
                $html .= (string) $item->get();
                continue;
            }
            $fragment = $twig->render(self::TEMPLATE, ['room' => $room, 'members' => $members[$room->id] ?? [$user]]);
            $this->cache->saveDeferred($item->set($fragment));
            $html .= $fragment;
        }
        $this->cache->commit();

        return $html;
    }

    private static function cacheKey(SidebarRoom $room): string
    {
        return 'views.users.sidebars.rooms.direct.'.self::VERSION.'.memberships.'.$room->membershipId.'-'.$room->membershipUpdatedAt?->format('YmdHisu');
    }
}
