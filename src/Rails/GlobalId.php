<?php

declare(strict_types=1);

namespace App\Rails;

/**
 * GlobalID URIs (globalid 1.3: lib/global_id/global_id.rb, uri/gid.rb): "gid://campfire/User/1".
 */
final class GlobalId
{
    /** GlobalID.app, from the application name (Campfire::Application). */
    public const string APP = 'campfire';

    /** record.to_gid.to_s. */
    public static function gid(string $modelName, int|string $id): string
    {
        return 'gid://'.self::APP.'/'.$modelName.'/'.str_replace('%7E', '~', urlencode((string) $id));
    }

    /** record.to_gid_param: URL-safe Base64 without padding, as Turbo stream names use. */
    public static function param(string $gid): string
    {
        return Base64::urlsafeEncode($gid, false);
    }

    /**
     * GlobalID.parse: a gid:// URI, or else a to_gid_param string. Query params (attachable SGIDs
     * carry "?expires_in") and the app are ignored by the default locator, which finds
     * model_name.constantize.find(id) whatever the app.
     *
     * @return array{app: string, model: string, id: string}|null
     */
    public static function parse(string $gid): ?array
    {
        return self::parseUri($gid) ?? self::fromParam($gid);
    }

    /** @return array{app: string, model: string, id: string}|null */
    public static function fromParam(string $param): ?array
    {
        $decoded = Base64::urlsafeDecode($param);

        return null === $decoded ? null : self::parseUri($decoded);
    }

    /**
     * URI::GID.parse for single-column ids; composite ids are not supported (Campfire has none).
     *
     * @return array{app: string, model: string, id: string}|null
     */
    public static function parseUri(string $gid): ?array
    {
        $pattern = '~\Agid://([A-Za-z0-9](?:[A-Za-z0-9\-.]*[A-Za-z0-9])?)/([^/?#\s]+)/([^?#\s]+)(?:\?[^#\s]*)?\z~';
        if (!preg_match($pattern, $gid, $m)) {
            return null;
        }
        $ids = array_values(array_filter(explode('/', $m[3]), static fn (string $part): bool => '' !== trim($part)));
        if (1 !== \count($ids)) {
            return null;
        }

        return ['app' => $m[1], 'model' => $m[2], 'id' => urldecode($ids[0])];
    }
}
