<?php

declare(strict_types=1);

namespace App\Opengraph;

/**
 * DNS for the private network guard: every address a hostname resolves to (A then AAAA), in
 * resolver order. Tests swap in a fixed table.
 */
interface HostResolver
{
    /** @return list<string> IP addresses; empty when the name does not resolve */
    public function resolve(string $hostname): array;
}
