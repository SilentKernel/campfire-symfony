<?php

declare(strict_types=1);

namespace App\Twig\Html;

use App\Rails\GlobalId;
use Twig\Markup;

/**
 * turbo-rails' tag builders (Turbo::Streams::ActionHelper, Turbo::FramesHelper), usable from PHP
 * (controllers, broadcasts) as well as through TurboExtension.
 */
final class TurboStream
{
    /**
     * `turbo_stream_action_tag(action, target:, targets:, template:, **attributes)`:
     * `<turbo-stream {attributes} action="…" target="…"><template>…</template></turbo-stream>`.
     * A record (or [record, prefix]) target becomes its dom_id; record targets get "#" prefixed.
     * The template is inserted as-is (it is already HTML).
     *
     * @param array<string, mixed> $attributes
     */
    public static function actionTag(string $action, mixed $target = null, mixed $targets = null, string|Markup|null $template = null, array $attributes = []): string
    {
        $content = \in_array($action, ['remove', 'refresh'], true) ? '' : '<template>'.$template.'</template>';

        if (null !== $target = self::convertTarget($target)) {
            $attributes['action'] = $action;
            $attributes['target'] = $target;
        } elseif (null !== $targets = self::convertTarget($targets, true)) {
            $attributes['action'] = $action;
            $attributes['targets'] = $targets;
        } else {
            $attributes['action'] = $action;
        }

        return Tag::content('turbo-stream', new Markup($content, 'UTF-8'), $attributes);
    }

    /** `turbo_stream.<action>(target, content, method:)` for the single-target actions. */
    public static function action(string $action, mixed $target, string|Markup|null $content = null, ?string $method = null): string
    {
        return self::actionTag($action, target: $target, template: $content ?? '', attributes: ['method' => $method]);
    }

    /** `turbo_stream.<action>_all(targets, content, method:)`. */
    public static function actionAll(string $action, mixed $targets, string|Markup|null $content = null, ?string $method = null): string
    {
        return self::actionTag($action, targets: $targets, template: $content ?? '', attributes: ['method' => $method]);
    }

    /** `turbo_stream.refresh(request_id:)`. */
    public static function refresh(?string $requestId = null): string
    {
        return self::actionTag('refresh', attributes: ['request-id' => '' === $requestId ? null : $requestId]);
    }

    /**
     * `turbo_frame_tag(*ids, src:, target:, **attributes)` around $content: a record id becomes
     * dom_id(record, prefix), other ids are joined with "_"; id/src/target follow the given
     * attributes unless already among them.
     *
     * @param array<string, mixed> $attributes
     */
    public static function frameTag(mixed $ids, array $attributes = [], string|Markup|null $content = null): string
    {
        $ids = \is_array($ids) ? array_values($ids) : [$ids];
        $id = RecordIdentifier::isRecord($ids[0] ?? null)
            ? RecordIdentifier::domId($ids[0], isset($ids[1]) ? (string) $ids[1] : null)
            : implode('_', array_map(static fn (mixed $part): string => Tag::rubyToS($part), $ids));

        $src = $attributes['src'] ?? null;
        $target = $attributes['target'] ?? null;
        unset($attributes['src'], $attributes['target']);
        $attributes = array_merge($attributes, ['id' => $id, 'src' => '' === $src ? null : $src, 'target' => $target]);

        return Tag::content('turbo-frame', $content instanceof Markup ? $content : new Markup((string) $content, 'UTF-8'), $attributes);
    }

    private static function convertTarget(mixed $target, bool $includeSelector = false): ?string
    {
        if (null === $target) {
            return null;
        }
        $parts = \is_array($target) ? array_values($target) : [$target];
        foreach ($parts as $part) {
            if (RecordIdentifier::isRecord($part)) {
                return ($includeSelector ? '#' : '').RecordIdentifier::domId($parts[0], isset($parts[1]) ? (string) $parts[1] : null);
            }
        }

        return \is_array($target) ? implode(' ', array_map(Tag::rubyToS(...), $target)) : Tag::rubyToS($target);
    }

    /**
     * Turbo::Streams::StreamName#stream_name_from, part by part: records become their
     * to_gid_param (GlobalID uses the STI class name: gid://campfire/Rooms::Open/1), anything else
     * its to_param; nested arrays are flattened. Join with ":" (TurboStreamName::name).
     *
     * @param array<mixed> $streamables
     *
     * @return list<string>
     */
    public static function streamNameParts(array $streamables): array
    {
        $parts = [];
        foreach ($streamables as $streamable) {
            if (\is_array($streamable)) {
                $parts[] = implode(':', self::streamNameParts($streamable));
            } elseif (RecordIdentifier::isRecord($streamable) && method_exists($streamable, 'getId')) {
                $parts[] = GlobalId::param(GlobalId::gid(RecordIdentifier::modelName($streamable::class), $streamable->getId()));
            } else {
                $parts[] = Tag::rubyToS($streamable);
            }
        }

        return $parts;
    }
}
