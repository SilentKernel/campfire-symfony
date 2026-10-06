<?php

declare(strict_types=1);

namespace App\Cable\Channel;

use App\Cable\Server\Subscription;
use App\Rails\TurboStreamName;

/**
 * Turbo::StreamsChannel (turbo-rails app/channels/turbo/streams_channel.rb) with
 * RoomStreamsAreAuthorized prepended (reference/app/channels/concerns/room_streams_are_authorized.rb):
 * a room's message stream is only served by RoomMessagesChannel.
 */
final class TurboStreamsChannel extends BaseChannel
{
    public function __construct(private readonly TurboStreamName $turboStreamName)
    {
    }

    public function subscribed(Subscription $subscription): void
    {
        $streamName = self::verifiedStreamNameFromParams($this->turboStreamName, $subscription);
        if (RoomMessagesChannel::isGuardedStream($streamName ?? '')) {
            $subscription->reject();
        } elseif (null !== $streamName) {
            $subscription->streamFrom($streamName);
        } else {
            $subscription->reject();
        }
    }

    public function perform(string $action, array $data, Subscription $subscription): bool
    {
        switch ($action) {
            case 'subscribed':
                $this->subscribed($subscription);

                return true;
            case 'verified_stream_name_from_params':
                self::verifiedStreamNameFromParams($this->turboStreamName, $subscription);

                return true;
            default:
                return false;
        }
    }

    /**
     * `verified_stream_name_from_params`: `params[:signed_stream_name]` verified with
     * Turbo.signed_stream_verifier. A missing name is unverified; a non-string raises, as
     * MessageVerifier#verified does in Ruby.
     */
    public static function verifiedStreamNameFromParams(TurboStreamName $turboStreamName, Subscription $subscription): ?string
    {
        $signed = $subscription->param('signed_stream_name');
        if (null !== $signed && !\is_string($signed)) {
            throw new \InvalidArgumentException('signed_stream_name must be a string');
        }

        return $turboStreamName->verify($signed);
    }
}
