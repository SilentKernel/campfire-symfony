<?php

declare(strict_types=1);

namespace App\Http\Attribute;

/**
 * Rails `allow_bot_access` (reference/app/controllers/concerns/authentication.rb): skips `deny_bots`,
 * so a request authenticated by `params[:bot_key]` is not answered with 403. Requests authenticated
 * by bot key also skip CSRF verification (`protect_from_forgery ... unless: bot_key?`).
 * On a class it covers every action; on a method that action (`only:`).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class AllowBotAccess
{
}
