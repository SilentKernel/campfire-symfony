<?php

declare(strict_types=1);

namespace App\Controller\Autocompletable;

use App\Controller\ApplicationController;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Rooms\UserRooms;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Http\Exception\RecordNotFound;
use App\Http\Mime;
use App\Http\Params;
use App\Rails\RailsJson;
use App\Rails\SignedGlobalId;
use App\Twig\AvatarsExtension;
use App\Twig\View\ViewHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autocompletable::UsersController (reference/app/controllers/autocompletable/users_controller.rb):
 * mention and user-picker suggestions, paginated by geared_pagination (`per_page: 20`, with its
 * X-Total-Count and Link headers on JSON responses).
 */
#[Route(defaults: ['_format' => null])]
final class UsersController extends ApplicationController
{
    public const int PER_PAGE = 20;

    public function __construct(
        private readonly UserRooms $userRooms,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly SignedGlobalId $signedGlobalId,
        private readonly AvatarsExtension $avatars,
    ) {
    }

    #[Route('/autocompletable/users.{_format}', name: 'autocompletable_users', methods: ['GET'], priority: 103)]
    public function index(Request $request): Response
    {
        $params = $this->params($request);
        $user = $this->currentUser() ?? throw new \LogicException('No current user.');

        // `params[:room_id].present? ? Current.user.rooms.find(params[:room_id]).users : User.all`
        $roomId = null;
        if (!Params::isBlank($params->get('room_id'))) {
            $room = $this->userRooms->find($user, $params->get('room_id')) ?? throw RecordNotFound::for('Room', $params->get('room_id'));
            $roomId = $room->getId();
        }
        // The rich text editor's mentions prompt filters with `filter`, the autocomplete inputs with `query`
        $query = self::presence($params->get('filter')) ?? self::presence($params->get('query'));
        $page = max(1, (int) min(RubyInteger::cast(RubyInteger::toI(\is_string($params->get('page')) ? $params->get('page') : '')) ?? 1, 1_000_000_000));

        $users = $this->users($roomId, $query, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->respondTo($request, [
            'html' => fn (): Response => $this->render('autocompletable/users/index.html.twig', ['users' => $users]),
            'json' => fn (): Response => $this->renderJson($request, $users, $page, $roomId, $query),
        ]);
    }

    /** @param list<User> $users */
    private function renderJson(Request $request, array $users, int $page, ?int $roomId, ?string $query): Response
    {
        $json = array_map(fn (User $user): array => [
            'name' => ViewHelpers::h($user->getName()),
            'value' => $user->getId(),
            'avatar_url' => $this->avatars->freshUserAvatarUrl($user),
            'sgid' => $this->signedGlobalId->generate('User', $user->getId()),
        ], $users);
        $response = new Response(RailsJson::encode($json), 200, ['Content-Type' => 'application/json; charset=utf-8']);

        // GearedPagination::Headers, for JSON requests
        if ('json' === Mime::format($request)) {
            $count = $this->count($roomId, $query);
            $response->headers->set('X-Total-Count', (string) $count);
            // `page.last?` is `number == page_count`: any other page links to the next one.
            if ($page !== max(1, (int) ceil($count / self::PER_PAGE))) {
                $response->headers->set('Link', \sprintf('<%s>; rel="next"', self::withPage($request, $page + 1)));
            }
        }

        return $response;
    }

    /**
     * `users_scope.active[.filtered_by(query)].ordered` with the page's limit and offset.
     *
     * @return list<User>
     */
    private function users(?int $roomId, ?string $query, int $limit, int $offset): array
    {
        [$sql, $params, $types] = $this->scope($roomId, $query, '"users"."id"');
        $ids = $this->connection->fetchFirstColumn($sql.' ORDER BY LOWER(name) LIMIT ? OFFSET ?', [...$params, $limit, $offset], [...$types, ParameterType::INTEGER, ParameterType::INTEGER]);
        if ([] === $ids) {
            return [];
        }
        $byId = [];
        foreach ($this->em->createQuery('SELECT u FROM '.User::class.' u WHERE u.id IN (:ids)')->setParameter('ids', $ids)->getResult() as $found) {
            \assert($found instanceof User);
            $byId[$found->getId()] = $found;
        }

        return array_values(array_filter(array_map(static fn (mixed $id): ?User => $byId[(int) $id] ?? null, $ids)));
    }

    private function count(?int $roomId, ?string $query): int
    {
        [$sql, $params, $types] = $this->scope($roomId, $query, 'COUNT(*)');

        return (int) $this->connection->fetchOne($sql, $params, $types);
    }

    /** @return array{string, list<mixed>, list<ParameterType>} */
    private function scope(?int $roomId, ?string $query, string $select): array
    {
        $sql = 'SELECT '.$select.' FROM "users"';
        $params = [];
        $types = [];
        if (null !== $roomId) {
            $sql .= ' INNER JOIN "memberships" ON "users"."id" = "memberships"."user_id" WHERE "memberships"."room_id" = ? AND';
            $params[] = $roomId;
            $types[] = ParameterType::INTEGER;
        } else {
            $sql .= ' WHERE';
        }
        $sql .= ' "users"."status" = ?';
        $params[] = UserStatus::Active->value;
        $types[] = ParameterType::INTEGER;
        if (null !== $query) {
            $sql .= ' AND (name like ?)';
            $params[] = '%'.$query.'%';
            $types[] = ParameterType::STRING;
        }

        return [$sql, $params, $types];
    }

    private static function presence(mixed $value): ?string
    {
        return \is_string($value) && !Params::isBlank($value) ? $value : null;
    }

    /** Addressable's `query_values = query_values.merge("page" => page)`: keys sorted, re-encoded. */
    private static function withPage(Request $request, int $page): string
    {
        $values = [];
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = $parts[0];
            $value = $parts[1] ?? null;
            $values[rawurldecode(str_replace('+', ' ', $key))] = null === $value ? null : rawurldecode(str_replace('+', ' ', $value));
        }
        $values['page'] = (string) $page;
        ksort($values, \SORT_STRING);

        $query = [];
        foreach ($values as $key => $value) {
            $query[] = null === $value ? self::encode((string) $key) : self::encode((string) $key).'='.self::encode($value);
        }

        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'?'.implode('&', $query);
    }

    /** Addressable's component encoding for query values (unreserved characters kept). */
    private static function encode(string $value): string
    {
        return (string) preg_replace_callback('/[^A-Za-z0-9\-._~]/', static fn (array $m): string => \sprintf('%%%02X', \ord($m[0])), $value);
    }
}
