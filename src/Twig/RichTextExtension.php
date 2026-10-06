<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Room;
use App\RichText\RichTextRenderer;
use App\Twig\Html\Tag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * reference/app/helpers/rich_text_helper.rb, and the rich text half of messages_helper.rb's
 * `message_presentation` (the text branch).
 */
final class RichTextExtension extends AbstractExtension
{
    public function __construct(
        private readonly RichTextRenderer $renderer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('rich_text_data_actions', self::richTextDataActions(...)),
            new TwigFunction('mention_prompt_tag', $this->mentionPromptTag(...), $safe),
            new TwigFunction('rich_text_presentation', $this->renderer->render(...), $safe),
            new TwigFunction('rich_text_plain_text', $this->renderer->toPlainText(...)),
            new TwigFunction('editable_body', $this->renderer->forEditor(...)),
        ];
    }

    /**
     * submitByKeyboard runs in the capture phase so it can submit on Enter before the editor
     * turns the keystroke into a newline.
     */
    public static function richTextDataActions(): string
    {
        return 'lexxy:change->typing-notifications#start keydown->composer#submitByKeyboard:capture';
    }

    /** `tag.lexxy_prompt trigger: "@", name: "mention", src: autocompletable_users_path(room_id:), …` */
    public function mentionPromptTag(Room|int $room): string
    {
        return Tag::content('lexxy-prompt', null, [
            'trigger' => '@',
            'name' => 'mention',
            'src' => $this->urlGenerator->generate('autocompletable_users', ['room_id' => $room instanceof Room ? $room->getId() : $room]),
            'remote-filtering' => 'true',
            'empty-results' => 'No matches',
        ]);
    }
}
