<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Users\Transfers;
use App\Entity\Enum\UserRole;
use App\Entity\Room;
use App\Entity\User;
use App\Http\Platform\BrowserBlocker;
use App\Http\Platform\RubyError;
use App\Repository\UserRepository;
use App\Storage\Attachments;
use App\Storage\StorageUrls;
use App\Twig\Html\Tag;
use App\Twig\View\ViewContext;
use App\Twig\View\ViewHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * reference/app/helpers/users_helper.rb, users/profiles_helper.rb and the bits of the identity
 * and account views that call model methods (transfer_id, avatar.attached?, mail_to).
 */
final class IdentityExtension extends AbstractExtension
{
    public function __construct(
        private readonly ViewContext $context,
        private readonly ViewHelpers $helpers,
        private readonly UrlGeneratorInterface $urls,
        private readonly Transfers $transfers,
        private readonly Attachments $attachments,
        private readonly StorageUrls $storageUrls,
        private readonly UserRepository $users,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('button_to_direct_room_with', $this->buttonToDirectRoomWith(...), $safe),
            new TwigFunction('profile_form_with', $this->profileFormWith(...), $safe),
            new TwigFunction('profile_form_submit_button', $this->profileFormSubmitButton(...), $safe),
            new TwigFunction('web_share_session_button', self::webShareSessionButton(...), $safe),
            new TwigFunction('mail_to', self::mailTo(...), $safe),
            new TwigFunction('help_contact_owner', fn (): ?User => $this->users->findOneBy(['role' => UserRole::Administrator], ['id' => 'ASC'])),
            new TwigFunction('bot_rooms', $this->botRooms(...)),
            new TwigFunction('transfer_id', $this->transfers->transferId(...)),
            new TwigFunction('user_avatar_attached', fn (User $user): bool => null !== $this->attachments->find('User', $user->getId(), 'avatar')),
            new TwigFunction('user_avatar_url', $this->userAvatarUrl(...)),
            new TwigFunction('allow_browser_versions', static fn (): array => array_filter(BrowserBlocker::VERSIONS, static fn (string|false $version): bool => false !== $version)),
        ];
    }

    public function getFilters(): array
    {
        return [
            // Ruby's String#capitalize.
            new TwigFilter('ruby_capitalize', static fn (?string $value): string => null === $value ? throw RubyError::noMethod('capitalize') : mb_strtoupper(mb_substr($value, 0, 1)).mb_strtolower(mb_substr($value, 1))),
        ];
    }

    /** `button_to rooms_directs_path(user_ids: [user.id]), class: "btn btn--primary full-width txt--large"` */
    public function buttonToDirectRoomWith(User $user): string
    {
        return $this->helpers->buttonTo(
            new Markup($this->helpers->imageTag('messages.svg'), 'UTF-8'),
            // Rails writes array params as `user_ids%5B%5D=`.
            $this->urls->generate('rooms_directs.post').'?user_ids%5B%5D='.$user->getId(),
            ['class' => 'btn btn--primary full-width txt--large'],
        );
    }

    /**
     * The opening of `profile_form_with(@user, **params)`: `form_with model: @user, url:
     * user_profile_path, method: :patch, data: { controller: "form" }`.
     *
     * @param array<string, mixed> $options
     */
    public function profileFormWith(array $options = []): string
    {
        return $this->helpers->formWith(['url' => $this->urls->generate('user_profile'), 'method' => 'patch', 'data' => ['controller' => 'form']] + $options);
    }

    public function profileFormSubmitButton(): string
    {
        return Tag::content('button', new Markup(
            $this->helpers->imageTag('check.svg', ['aria' => ['hidden' => 'true'], 'size' => 20]).Tag::content('span', 'Save changes', ['class' => 'for-screen-reader']),
            'UTF-8',
        ), ['class' => 'btn btn--reversed center txt-large', 'type' => 'submit']);
    }

    public static function webShareSessionButton(string $url, string $title, string $text, string|Markup $content = ''): string
    {
        return Tag::content('button', $content instanceof Markup ? $content : new Markup($content, 'UTF-8'), ['class' => 'btn', 'hidden' => true, 'data' => [
            'controller' => 'web-share', 'action' => 'web-share#share',
            'web_share_url_value' => $url,
            'web_share_text_value' => $text,
            'web_share_title_value' => $title,
        ]]);
    }

    /** `mail_to email` */
    public static function mailTo(?string $email, string|Markup|null $name = null): string
    {
        return Tag::content('a', $name ?? (string) $email, ['href' => 'mailto:'.$email]);
    }

    /** `url_for(user.avatar)` (image_tag of an attachment): the blob redirect URL, absolute. */
    public function userAvatarUrl(User $user): ?string
    {
        $attachment = $this->attachments->find('User', $user->getId(), 'avatar');
        if (null === $attachment) {
            return null;
        }

        return ($this->context->request()?->getSchemeAndHttpHost() ?? '').$this->storageUrls->blobPath($attachment);
    }

    /**
     * `bot.rooms.without_directs.ordered`, in the order SQLite returns Rails' query.
     *
     * @return list<Room>
     */
    public function botRooms(User $bot): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT "rooms"."id" FROM "rooms" INNER JOIN "memberships" ON "rooms"."id" = "memberships"."room_id" WHERE "memberships"."user_id" = ? AND "rooms"."type" != ? ORDER BY LOWER(name)',
            [$bot->getId(), 'Rooms::Direct'],
        );

        return array_values(array_filter(array_map(fn (mixed $id): ?Room => $this->em->find(Room::class, (int) $id), $ids)));
    }
}
