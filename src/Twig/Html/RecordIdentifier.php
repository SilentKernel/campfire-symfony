<?php

declare(strict_types=1);

namespace App\Twig\Html;

use App\Entity;

/**
 * ActionView::RecordIdentifier (dom_id / dom_class) over Doctrine entities, with Rails' model
 * names: STI subclasses keep their own name ("rooms_open_1"), engine models use relative naming
 * (ActionText::RichText → "rich_text"), and a record's key is its `to_key` — `toKey()` when the
 * entity defines it (messages: client_message_id), otherwise its id once saved.
 */
final class RecordIdentifier
{
    /** Rails model names for entities whose PHP class does not spell them. */
    private const MODEL_NAMES = [
        Entity\PushSubscription::class => 'Push::Subscription',
    ];

    /** `model_name.param_key` for engine models (isolate_namespace → relative naming). */
    private const PARAM_KEYS = [
        Entity\RichText::class => 'rich_text',
        Entity\ActiveStorage\Attachment::class => 'attachment',
        Entity\ActiveStorage\Blob::class => 'blob',
        Entity\ActiveStorage\VariantRecord::class => 'variant_record',
    ];

    /** @var array<class-string, string> */
    private static array $paramKeys = [];

    /** @param object|class-string $recordOrClass */
    public static function domId(object|string $recordOrClass, ?string $prefix = null): string
    {
        $recordId = \is_string($recordOrClass) ? null : self::recordKey($recordOrClass);

        return null !== $recordId
            ? self::domClass($recordOrClass, $prefix).'_'.$recordId
            : self::domClass($recordOrClass, $prefix ?? 'new');
    }

    /** @param object|class-string $recordOrClass */
    public static function domClass(object|string $recordOrClass, ?string $prefix = null): string
    {
        $singular = self::paramKey($recordOrClass);

        return null !== $prefix && '' !== $prefix ? $prefix.'_'.$singular : $singular;
    }

    /** @param object|class-string $recordOrClass */
    public static function paramKey(object|string $recordOrClass): string
    {
        $class = \is_string($recordOrClass) ? ltrim($recordOrClass, '\\') : $recordOrClass::class;

        return self::$paramKeys[$class] ??= self::PARAM_KEYS[$class]
            ?? str_replace('/', '_', Inflector::underscore(self::modelName($class)));
    }

    /** The Rails class name: "App\Entity\Rooms\Open" → "Rooms::Open". */
    public static function modelName(string $class): string
    {
        if (isset(self::MODEL_NAMES[$class])) {
            return self::MODEL_NAMES[$class];
        }
        if (\defined($class.'::TYPE') && \is_string($type = \constant($class.'::TYPE'))) {
            return $type;
        }
        if (str_starts_with($class, 'App\\Entity\\')) {
            $class = substr($class, \strlen('App\\Entity\\'));
        }

        return str_replace('\\', '::', $class);
    }

    /** `record_key_for_dom_id`: the to_key parts joined by "_", or null for a new record. */
    public static function recordKey(object $record): ?string
    {
        if (method_exists($record, 'toKey')) {
            $key = $record->toKey();
        } elseif (method_exists($record, 'isNewRecord') && method_exists($record, 'getId')) {
            $key = $record->isNewRecord() ? null : [$record->getId()];
        } elseif (method_exists($record, 'getId')) {
            $key = null !== $record->getId() ? [$record->getId()] : null;
        } else {
            throw new \InvalidArgumentException(\sprintf('dom_id cannot identify a %s.', $record::class));
        }

        if (null === $key || \in_array(null, $key, true) || \in_array(false, $key, true)) {
            return null;
        }

        return implode('_', array_map(static fn (mixed $part): string => Tag::rubyToS($part), $key));
    }

    /** True when the value is a record (responds to to_key), as turbo-rails checks. */
    public static function isRecord(mixed $value): bool
    {
        return \is_object($value) && !$value instanceof \Stringable && (method_exists($value, 'toKey') || method_exists($value, 'getId'));
    }
}
