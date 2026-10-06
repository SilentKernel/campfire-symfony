<?php

declare(strict_types=1);

namespace App\Cable\Server;

use App\Rails\RailsJson;

/**
 * The actioncable-v1-json messages (ActionCable::INTERNAL), with the key order of the Ruby hashes
 * Connection::Base and Channel::Base transmit.
 */
final class Protocol
{
    public const int BEAT_INTERVAL = 3;

    public const string REASON_UNAUTHORIZED = 'unauthorized';
    public const string REASON_INVALID_REQUEST = 'invalid_request';
    public const string REASON_SERVER_RESTART = 'server_restart';
    public const string REASON_REMOTE = 'remote';

    public static function welcome(): string
    {
        return '{"type":"welcome"}';
    }

    public static function ping(int $unixSeconds): string
    {
        return '{"type":"ping","message":'.$unixSeconds.'}';
    }

    public static function disconnect(?string $reason, bool $reconnect): string
    {
        return '{"type":"disconnect","reason":'.(null === $reason ? 'null' : RailsJson::encode($reason)).',"reconnect":'.($reconnect ? 'true' : 'false').'}';
    }

    public static function confirmation(string $encodedIdentifier): string
    {
        return '{"identifier":'.$encodedIdentifier.',"type":"confirm_subscription"}';
    }

    public static function rejection(string $encodedIdentifier): string
    {
        return '{"identifier":'.$encodedIdentifier.',"type":"reject_subscription"}';
    }

    /** $encodedMessage is already Active Support JSON (a broadcast's payload). */
    public static function message(string $encodedIdentifier, string $encodedMessage): string
    {
        return '{"identifier":'.$encodedIdentifier.',"message":'.$encodedMessage.'}';
    }
}
