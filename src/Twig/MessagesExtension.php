<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Messages\MessageRichText;
use App\Domain\Messages\Sound;
use App\Entity\ActiveStorage\Attachment;
use App\Entity\Message;
use App\Entity\Room;
use App\Storage\Filename;
use App\Storage\Paths;
use App\Twig\Asset\Assets;
use App\Twig\Html\RecordIdentifier;
use App\Twig\Html\Tag;
use App\Twig\View\ViewHelpers;
use App\View\MessagePresentation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * reference/app/helpers/messages_helper.rb (with Messages::AttachmentPresentation) and
 * searches_helper.rb.
 */
final class MessagesExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageRichText $richText,
        private readonly StorageExtension $storage,
        private readonly Assets $assets,
        private readonly ViewHelpers $helpers,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('message_area_tag', $this->messageAreaTag(...), $safe),
            new TwigFunction('messages_tag', $this->messagesTag(...), $safe),
            new TwigFunction('message_tag', self::messageTag(...), $safe),
            new TwigFunction('message_timestamp', self::messageTimestamp(...), $safe),
            new TwigFunction('message_presentation', $this->messagePresentation(...), $safe),
            new TwigFunction('search_results_tag', self::searchResultsTag(...), $safe),
            new TwigFunction('message_attachment_filename', static fn (Attachment $attachment): string => (new Filename($attachment->getBlob()->getFilename()))->sanitized()),
            new TwigFunction('search_path', static fn (string $query): string => '/searches?q='.Paths::cgiEscape($query)),
            new TwigFunction('epoch_ms', self::epoch(...)),
            new TwigFunction('all_emoji', MessagePresentation::allEmoji(...)),
        ];
    }

    /** `message_area_tag(room, &)` */
    public function messageAreaTag(Room $room, string|Markup|null $content = null): string
    {
        return Tag::content('div', self::markup($content), [
            'id' => 'message-area',
            'class' => 'message-area',
            'contents' => true,
            'data' => [
                'controller' => 'messages presence drop-target',
                'action' => 'turbo:before-stream-render@document->messages#beforeStreamRender keydown.up@document->messages#editMyLastMessage'
                    .' dragenter->drop-target#dragenter dragover->drop-target#dragover drop->drop-target#drop'
                    .' visibilitychange@document->presence#visibilityChanged',
                'messages_first_of_day_class' => 'message--first-of-day',
                'messages_formatted_class' => 'message--formatted',
                'messages_me_class' => 'message--me',
                'messages_mentioned_class' => 'message--mentioned',
                'messages_threaded_class' => 'message--threaded',
                'messages_page_url_value' => $this->urls->generate('room_messages', ['room_id' => $room->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ]);
    }

    /** `messages_tag(room, &)` */
    public function messagesTag(Room $room, string|Markup|null $content = null): string
    {
        return Tag::content('div', self::markup($content), [
            'id' => RecordIdentifier::domId($room, 'messages'),
            'class' => 'messages',
            'data' => [
                'controller' => 'maintain-scroll refresh-room',
                'action' => 'turbo:before-stream-render@document->maintain-scroll#beforeStreamRender visibilitychange@document->refresh-room#visibilityChanged online@window->refresh-room#online',
                'messages_target' => 'messages',
                'refresh_room_loaded_at_value' => self::epoch($room->getUpdatedAt()),
                'refresh_room_url_value' => $this->urls->generate('room_refresh', ['room_id' => $room->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ]);
    }

    /** `message_tag(message, &)`: the message's `<div>` around the block's content. */
    public static function messageTag(MessagePresentation $presentation, string|Markup|null $content = null): string
    {
        $message = $presentation->message;
        $timestamp = self::epoch($message->getCreatedAt());

        return Tag::content('div', self::markup($content), [
            'id' => RecordIdentifier::domId($message),
            'class' => 'message '.($presentation->isAllEmoji() ? 'message--emoji' : ''),
            'data' => [
                'controller' => 'reply',
                'user_id' => $presentation->creator?->getId(),
                'message_id' => $message->getId(),
                'message_timestamp' => $timestamp,
                'message_updated_at' => self::epoch($message->getUpdatedAt()),
                'sort_value' => $timestamp,
                'messages_target' => 'message',
                'search_results_target' => 'message',
                'refresh_room_target' => 'message',
                'reply_composer_outlet' => '#composer',
            ],
        ]);
    }

    /**
     * `message_timestamp(message, **attributes)`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function messageTimestamp(Message $message, array $attributes = []): string
    {
        return HelpersExtension::localDatetimeTag($message->getCreatedAt(), 'time', $attributes);
    }

    /**
     * `message_presentation(message)`: the attachment, the sound, or the rich text. Rails logs and
     * renders nothing when presenting raises.
     */
    public function messagePresentation(MessagePresentation $presentation): string
    {
        try {
            return match ($presentation->contentType()) {
                'attachment' => $this->storage->messageAttachmentPresentation($presentation->message, $presentation->attachment),
                'sound' => $this->soundPresentation($presentation->sound),
                default => $this->richText->render($presentation->body),
            };
        } catch (\Throwable $error) {
            $this->logger?->error(\sprintf('Exception while generating message representation for Message#%d, failed with: %s `%s`', $presentation->message->getId(), $error::class, $error->getMessage()));

            return '';
        }
    }

    /** `search_results_tag(&)` */
    public static function searchResultsTag(string|Markup|null $content = null): string
    {
        return Tag::content('div', self::markup($content), [
            'id' => 'search-results',
            'class' => 'messages searches__results',
            'data' => [
                'controller' => 'search-results',
                'search_results_target' => 'messages',
                'search_results_me_class' => 'message--me',
                'search_results_threaded_class' => 'message--threaded',
                'search_results_mentioned_class' => 'message--mentioned',
                'search_results_formatted_class' => 'message--formatted',
            ],
        ]);
    }

    /** `time.to_fs(:epoch)`: `(time.to_f * 1000).to_i` (reference/config/initializers/time_formats.rb). */
    public static function epoch(\DateTimeInterface $time): int
    {
        $float = (float) ($time->format('U').'.'.$time->format('u'));

        return (int) ($float * 1000);
    }

    /** `message_sound_presentation`: a play button, then the sound's image or text. */
    private function soundPresentation(?Sound $sound): string
    {
        if (null === $sound) {
            return '';
        }
        $button = Tag::content('button', '🔊', ['class' => 'btn btn--plain', 'data' => ['action' => 'sound#play']]);
        $after = null !== $sound->image
            ? $this->helpers->imageTag($sound->image[0], ['width' => $sound->image[1], 'height' => $sound->image[2], 'class' => 'align--middle'])
            : ViewHelpers::h($sound->text);

        return Tag::content('div', new Markup($button.$after, 'UTF-8'), [
            'class' => 'sound',
            'data' => ['controller' => 'sound', 'action' => 'messages:play->sound#play', 'sound_url_value' => $this->assets->path($sound->assetPath())],
        ]);
    }

    private static function markup(string|Markup|null $content): ?Markup
    {
        return null === $content ? null : ($content instanceof Markup ? $content : new Markup($content, 'UTF-8'));
    }
}
