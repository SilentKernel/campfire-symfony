<?php

declare(strict_types=1);

namespace App\Twig\Asset;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The `Link: <…>; rel=preload` header stylesheet_link_tag sends (ActionView::Helpers::AssetTagHelper
 * #send_preload_links_header): links are added until the header would exceed 1000 bytes.
 */
final class PreloadLinks implements ResetInterface
{
    private const MAX_HEADER_SIZE = 1000;

    /** @var list<string> */
    private array $links = [];

    public function add(string $link): void
    {
        $this->links[] = $link;
    }

    #[AsEventListener]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || [] === $this->links) {
            return;
        }

        $header = (string) $event->getResponse()->headers->get('link', '');
        foreach ($this->links as $link) {
            if (\strlen($header) + \strlen($link) > self::MAX_HEADER_SIZE) {
                break;
            }
            $header .= ('' === $header ? '' : ',').$link;
        }
        $event->getResponse()->headers->set('link', $header);
        $this->links = [];
    }

    public function reset(): void
    {
        $this->links = [];
    }
}
