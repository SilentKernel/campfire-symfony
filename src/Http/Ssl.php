<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `config.assume_ssl = config.force_ssl = ENV["DISABLE_SSL"].blank?`
 * (reference/config/environments/production.rb).
 *
 * AssumeSSL marks every request as HTTPS, so ActionDispatch::SSL never redirects (nor would its
 * `/up` exclusion matter: `ssl_options` is commented out). What remains is HSTS on every response,
 * the session cookie's `secure` option and "; secure" appended to every other Set-Cookie.
 */
final readonly class Ssl
{
    /** ActionDispatch::SSL::HSTS_EXPIRES_IN with the default `subdomains: true`. */
    public const string HSTS = 'max-age=63072000; includeSubDomains';

    public bool $enabled;

    public function __construct(#[Autowire('%env(DISABLE_SSL)%')] string $disableSsl)
    {
        $this->enabled = '' === trim($disableSsl);
    }
}
