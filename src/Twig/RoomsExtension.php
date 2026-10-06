<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Rooms\Epoch;
use App\Domain\Rooms\RoomNames;
use App\Domain\Rooms\SidebarRenderer;
use App\Domain\Rooms\SidebarRoom;
use App\Domain\Rooms\TrackedRoomVisit;
use App\Entity\Enum\Involvement;
use App\Entity\Room;
use App\Entity\User;
use App\Rails\SignedGlobalId;
use App\Twig\Html\RecordIdentifier;
use App\Twig\Html\Tag;
use App\Twig\Html\TurboStream;
use App\Twig\View\ViewContext;
use App\Twig\View\ViewHelpers;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * reference/app/helpers/rooms_helper.rb, rooms/involvements_helper.rb, users/sidebar_helper.rb
 * and users/filter_helper.rb, plus what the room and sidebar views need from their
 * controllers (`last_room_visited`, the cached direct rooms, `turbo_frame_request?`).
 *
 * Helpers that take a block in Rails take its HTML as their last argument (build it with
 * `{% set html %}…{% endset %}`); composer_form_tag returns only the opening form tag.
 */
final class RoomsExtension extends AbstractExtension
{
    /** Rooms::InvolvementsHelper::HUMANIZE_INVOLVEMENT */
    public const array HUMANIZE_INVOLVEMENT = [
        'mentions' => 'Notifying about @ mentions',
        'everything' => 'Notifying about all messages',
        'nothing' => 'Notifications are off',
        'invisible' => 'Notifications are off and room invisible in sidebar',
    ];

    private const array SHARED_INVOLVEMENT_ORDER = ['mentions', 'everything', 'nothing', 'invisible'];
    private const array DIRECT_INVOLVEMENT_ORDER = ['everything', 'nothing'];

    public function __construct(
        private readonly ViewContext $context,
        private readonly ViewHelpers $helpers,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ApplicationExtension $application,
        private readonly RoomNames $roomNames,
        private readonly TrackedRoomVisit $trackedRoomVisit,
        private readonly SidebarRenderer $sidebarRenderer,
        private readonly SignedGlobalId $signedGlobalId,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('room_display_name', $this->roomDisplayName(...)),
            new TwigFunction('room_display_name_for', $this->roomNames->displayName(...)),
            new TwigFunction('link_to_room', $this->linkToRoom(...), $safe),
            new TwigFunction('link_to_edit_room', $this->linkToEditRoom(...), $safe),
            new TwigFunction('link_back_to_last_room_visited', $this->linkBackToLastRoomVisited(...), $safe),
            new TwigFunction('last_room_visited', $this->trackedRoomVisit->lastRoomVisited(...)),
            new TwigFunction('button_to_delete_room', $this->buttonToDeleteRoom(...), $safe),
            new TwigFunction('button_to_jump_to_newest_message', $this->buttonToJumpToNewestMessage(...), $safe),
            new TwigFunction('submit_room_button_tag', $this->submitRoomButtonTag(...), $safe),
            new TwigFunction('composer_form_tag', $this->composerFormTag(...), $safe),
            new TwigFunction('turbo_frame_for_involvement_tag', $this->turboFrameForInvolvementTag(...), $safe),
            new TwigFunction('button_to_change_involvement', $this->buttonToChangeInvolvement(...), $safe),
            new TwigFunction('sidebar_turbo_frame_tag', self::sidebarTurboFrameTag(...), $safe),
            new TwigFunction('user_filter_menu_tag', self::userFilterMenuTag(...), $safe),
            new TwigFunction('user_filter_search_tag', self::userFilterSearchTag(...), $safe),
            new TwigFunction('sidebar_direct_rooms', $this->sidebarDirectRooms(...), $safe + ['needs_environment' => true]),
            new TwigFunction('attachable_sgid', $this->attachableSgid(...)),
            new TwigFunction('turbo_frame_request', $this->turboFrameRequest(...)),
            new TwigFunction('account_logo_attached', $this->context->accountHasLogo(...)),
            new TwigFunction('epoch_milliseconds', Epoch::milliseconds(...)),
            new TwigFunction('first_name', self::firstName(...)),
            new TwigFunction('direct_members_initials', self::directMembersInitials(...)),
        ];
    }

    /** `room_display_name(room)`, for Current.user (use room_display_name_for for another user or nil). */
    public function roomDisplayName(Room $room): string
    {
        return $this->roomNames->displayName($room, $this->context->user());
    }

    /**
     * `link_to_room(room, **attributes, &)`: a link to the room with the rooms-list data
     * attributes, merged before the given ones (a given `data` keeps its position).
     *
     * @param array<string, mixed> $attributes
     */
    public function linkToRoom(Room|SidebarRoom $room, array $attributes = [], string|Markup|null $content = null): string
    {
        $id = $room instanceof Room ? $room->getId() : $room->id;
        $data = ['rooms_list_target' => 'room', 'room_id' => $id, 'badge_dot_target' => 'unread', 'sorted_list_target' => 'item'];
        $attributes['data'] = array_merge($data, $attributes['data'] ?? []);

        return $this->helpers->linkTo(self::markup($content), $this->urlGenerator->generate('room', ['id' => $id]), $attributes);
    }

    /** `link_to [ :edit, @room ], class: "btn", style:, data: { room_id: }, &` */
    public function linkToEditRoom(Room $room, string|Markup|null $content = null): string
    {
        $route = match (true) {
            $room->isOpen() => 'edit_rooms_open',
            $room->isClosed() => 'edit_rooms_closed',
            default => 'edit_rooms_direct',
        };

        return $this->helpers->linkTo(self::markup($content), $this->urlGenerator->generate($route, ['id' => $room->getId()]), [
            'class' => 'btn',
            'style' => 'view-transition-name: edit-room-'.$room->getId(),
            'data' => ['room_id' => $room->getId()],
        ]);
    }

    public function linkBackToLastRoomVisited(): string
    {
        $lastRoom = $this->trackedRoomVisit->lastRoomVisited();

        return $this->application->linkBackTo(null !== $lastRoom
            ? $this->urlGenerator->generate('room', ['id' => $lastRoom->getId()])
            : $this->urlGenerator->generate('root'));
    }

    public function buttonToDeleteRoom(Room $room, ?string $url = null): string
    {
        $content = $this->helpers->imageTag('trash.svg', ['aria' => ['hidden' => 'true'], 'size' => 20])
            .Tag::content('span', $this->roomDisplayName($room), ['class' => 'overflow-ellipsis']);

        return $this->helpers->buttonTo(new Markup($content, 'UTF-8'), $url ?? $this->urlGenerator->generate('room', ['id' => $room->getId()], UrlGeneratorInterface::ABSOLUTE_URL), [
            'method' => 'delete',
            'class' => 'btn btn--negative max-width',
            'aria' => ['label' => 'Delete '.$room->getName()],
            'data' => ['turbo_confirm' => 'Are you sure you want to delete this room and all messages in it? This can’t be undone.'],
        ]);
    }

    public function buttonToJumpToNewestMessage(): string
    {
        $content = $this->helpers->imageTag('arrow-down.svg', ['aria' => ['hidden' => 'true'], 'size' => 20])
            .Tag::content('span', 'Jump to newest message', ['class' => 'for-screen-reader']);

        return Tag::content('button', new Markup($content, 'UTF-8'), [
            'class' => 'message-area__return-to-latest btn',
            'data' => ['action' => 'messages#returnToLatest', 'messages_target' => 'latest'],
            'hidden' => true,
        ]);
    }

    /** `button_tag class: "btn btn--reversed txt-large center", type: "submit"` (button_tag adds name="button"). */
    public function submitRoomButtonTag(): string
    {
        $content = $this->helpers->imageTag('check.svg', ['aria' => ['hidden' => 'true'], 'size' => 20])
            .Tag::content('span', 'Save', ['class' => 'for-screen-reader']);

        return Tag::content('button', new Markup($content, 'UTF-8'), ['name' => 'button', 'type' => 'submit', 'class' => 'btn btn--reversed txt-large center']);
    }

    /** The opening of `form_with model: Message.new, url: room_messages_path(room), id: "composer", …`. */
    public function composerFormTag(Room $room): string
    {
        $actions = implode(' ', [
            'dragenter->drop-target#dragenter dragover->drop-target#dragover drop->drop-target#drop',
            'drop-target:drop@window->composer#dropFiles',
            'lexxy:file-accept->composer#preventAttachment refresh-room:online@window->composer#online',
            'typing-notifications#stop paste->composer#pasteFiles turbo:submit-end->composer#submitEnd refresh-room:offline@window->composer#offline',
        ]);

        return $this->helpers->formWith([
            'url' => $this->urlGenerator->generate('room_messages', ['room_id' => $room->getId()]),
            'id' => 'composer',
            'class' => 'margin-block flex-item-grow contain',
            'data' => [
                'controller' => 'composer drop-target',
                'action' => $actions,
                'composer_messages_outlet' => '#message-area',
                'composer_toolbar_class' => 'composer--rich-text',
                'composer_room_id_value' => $room->getId(),
            ],
        ]);
    }

    public function turboFrameForInvolvementTag(Room $room, string|Markup|null $content = null): string
    {
        return TurboStream::frameTag(RecordIdentifier::domId($room, 'involvement'), ['data' => [
            'controller' => 'turbo-frame',
            'action' => 'notifications:ready@window->turbo-frame#load',
            'turbo_frame_url_param' => $this->urlGenerator->generate('room_involvement', ['room_id' => $room->getId()]),
        ]], $content);
    }

    public function buttonToChangeInvolvement(Room $room, Involvement|string|null $involvement): string
    {
        $involvement = $involvement instanceof Involvement ? $involvement->value : $involvement;
        if (null === $involvement || !isset(self::HUMANIZE_INVOLVEMENT[$involvement])) {
            // `ORDER.index(nil) + 1`
            throw new \LogicException("undefined method '+' for nil");
        }

        $content = $this->helpers->imageTag('notification-bell-'.$involvement.'.svg', ['aria' => ['hidden' => 'true'], 'size' => 20])
            .Tag::content('span', self::HUMANIZE_INVOLVEMENT[$involvement], ['class' => 'for-screen-reader', 'id' => RecordIdentifier::domId($room, 'involvement_label')]);

        return $this->helpers->buttonTo(new Markup($content, 'UTF-8'), $this->urlGenerator->generate('room_involvement', ['room_id' => $room->getId(), 'involvement' => self::nextInvolvementFor($room, $involvement)]), [
            'method' => 'put',
            'role' => 'checkbox',
            'aria' => ['checked' => true, 'labelledby' => RecordIdentifier::domId($room, 'involvement_label')],
            'tabindex' => 0,
            'class' => 'btn '.$involvement,
        ]);
    }

    public static function nextInvolvementFor(Room $room, string $involvement): string
    {
        $order = $room->isDirect() ? self::DIRECT_INVOLVEMENT_ORDER : self::SHARED_INVOLVEMENT_ORDER;
        $index = array_search($involvement, $order, true);
        if (false === $index) {
            throw new \LogicException("undefined method '+' for nil");
        }

        return $order[$index + 1] ?? $order[0];
    }

    public static function sidebarTurboFrameTag(?string $src = null, string|Markup|null $content = null): string
    {
        return TurboStream::frameTag('user_sidebar', ['src' => $src, 'target' => '_top', 'data' => [
            'turbo_permanent' => true,
            'controller' => 'rooms-list read-rooms turbo-frame',
            'rooms_list_unread_class' => 'unread',
            'action' => new Markup('presence:present@window->rooms-list#read read-rooms:read->rooms-list#read turbo:frame-load->rooms-list#loaded refresh-room:visible@window->turbo-frame#reload', 'UTF-8'),
        ]], $content);
    }

    public static function userFilterMenuTag(string|Markup|null $content = null): string
    {
        return Tag::content('menu', self::markup($content), [
            'class' => 'flex flex-column gap margin-none pad overflow-y constrain-height',
            'data' => ['controller' => 'filter', 'filter_active_class' => 'filter--active', 'filter_selected_class' => 'selected'],
        ]);
    }

    public static function userFilterSearchTag(): string
    {
        return Tag::void('input', [
            'type' => 'search', 'id' => 'search', 'autocorrect' => 'off', 'autocomplete' => 'off', 'data-1p-ignore' => 'true',
            'class' => 'input input--transparent full-width', 'placeholder' => 'Filter…', 'data' => ['action' => 'input->filter#filter'],
        ]);
    }

    /** @param list<SidebarRoom> $rooms */
    public function sidebarDirectRooms(Environment $twig, array $rooms): string
    {
        $user = $this->context->user() ?? throw new \LogicException('No current user.');

        return $this->sidebarRenderer->directRooms($twig, $rooms, $user);
    }

    /** `user.attachable_sgid` */
    public function attachableSgid(User $user): string
    {
        return $this->signedGlobalId->generate('User', $user->getId());
    }

    /** turbo-rails' `turbo_frame_request?`: the request carries a Turbo-Frame header. */
    public function turboFrameRequest(): bool
    {
        $frame = $this->context->request()?->headers->get('Turbo-Frame');

        return null !== $frame && '' !== trim($frame);
    }

    /** `name.split(' ')[0]` (Ruby's awk-style split on whitespace), "" when there is no word. */
    public static function firstName(string $name): string
    {
        return self::words($name)[0] ?? '';
    }

    /**
     * users/sidebars/rooms/_direct: `members.map { |m| m.name.split(' ')[0, 3].map { |str|
     * str[0].capitalize }.join }.to_sentence(two_words_connector: '+')`.
     *
     * @param list<User> $members
     */
    public static function directMembersInitials(array $members): string
    {
        $initials = array_map(
            static fn (User $member): string => implode('', array_map(
                static fn (string $word): string => mb_convert_case(mb_substr($word, 0, 1), \MB_CASE_TITLE),
                \array_slice(self::words($member->getName()), 0, 3),
            )),
            $members,
        );

        return RoomNames::toSentence($initials, '+');
    }

    /** @return list<string> */
    private static function words(string $value): array
    {
        return preg_split('/[ \t\n\v\f\r]+/', trim($value, " \t\n\v\f\r"), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function markup(string|Markup|null $content): Markup
    {
        return $content instanceof Markup ? $content : new Markup((string) $content, 'UTF-8');
    }
}
