<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\Boost;
use App\Entity\Message;
use App\Http\Current;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * `render partial: "messages/message", collection: messages, cached: true` with Rails'
 * fragment caching, as reference commit 659f957 does it: one multi-read of the cache for the
 * whole page, then presentation data (creator, rich text, attachment and blob, boosts and
 * boosters, room) loaded in batches for the misses only, which are rendered and written back.
 *
 * Fragments are keyed by the message's cache key (id and updated_at, which every change to the
 * message, its rich text, attachment or boosts touches), "presentation-v3" and a digest of the
 * templates, so a deploy that changes the markup starts afresh. As in Rails, the cache holds
 * whichever rendering came first: a page rendered in a request (with per-form authenticity
 * tokens) or a broadcast rendered detached (none).
 */
final class MessageRenderer
{
    /** Bump when the presentation changes in PHP (the templates are digested automatically). */
    public const string VERSION = 'presentation-v3';

    private const array TEMPLATES = [
        'messages/_message.html.twig',
        'messages/_actions.html.twig',
        'messages/_presentation.html.twig',
        'messages/_unrenderable.html.twig',
        'messages/boosts/_boosts.html.twig',
        'messages/boosts/_boost.html.twig',
    ];

    /** Computed once per process: the templates never change while it runs. */
    private ?string $digest = null;

    public function __construct(
        private readonly Environment $twig,
        private readonly MessagePresentationSource $loader,
        private readonly Current $current,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The messages/_message partials of a page, in order, each as Rails renders it ("\n  <div …>").
     *
     * @param list<Message> $messages
     */
    public function render(array $messages, bool $detached = false): string
    {
        if ([] === $messages) {
            return '';
        }

        $keys = [];
        foreach ($messages as $message) {
            $keys[$message->getId()] = $this->cacheKey($message);
        }
        $items = [];
        foreach ($this->cache->getItems(array_values(array_unique($keys))) as $key => $item) {
            $items[$key] = $item;
        }

        $fragments = [];
        $misses = [];
        foreach ($messages as $message) {
            $item = $items[$keys[$message->getId()]];
            if ($item->isHit()) {
                $fragments[$message->getId()] = (string) $item->get();
            } else {
                $misses[$message->getId()] = $message;
            }
        }

        if ([] !== $misses) {
            $presentations = $this->loader->load(array_values($misses));
            foreach ($misses as $id => $message) {
                $fragment = $this->renderFragment($presentations[$id], $detached);
                $item = $items[$keys[$id]];
                $item->set($fragment);
                $this->cache->saveDeferred($item);
                $fragments[$id] = $fragment;
            }
            $this->cache->commit();
        }

        $html = '';
        foreach ($messages as $message) {
            $html .= $fragments[$message->getId()];
        }

        return $html;
    }

    /** `render message` */
    public function renderOne(Message $message, bool $detached = false): string
    {
        return $this->render([$message], $detached);
    }

    /**
     * The partial as a broadcast renders it (ApplicationController.renderer): no request, so no
     * authenticity tokens, and URLs on the request's host without its port.
     */
    public function renderDetached(Message $message): string
    {
        return $this->renderOne($message, true);
    }

    /** messages/_presentation alone (the broadcast after an edit), uncached as in Rails. */
    public function renderPresentation(Message $message): string
    {
        $presentation = $this->loader->load([$message])[$message->getId()];

        return $this->twig->render('messages/_presentation.html.twig', ['p' => $presentation, 'detached' => true]);
    }

    /** messages/boosts/_boost, for a boost's broadcast (detached) or a page. */
    public function renderBoost(Boost $boost, bool $detached = true): string
    {
        return $this->twig->render('messages/boosts/_boost.html.twig', ['boost' => $boost, 'detached' => $detached]);
    }

    /** messages/boosts/_boosts for one message (the boosts index). */
    public function renderBoosts(Message $message): string
    {
        $presentation = $this->loader->load([$message])[$message->getId()];

        return $this->twig->render('messages/boosts/_boosts.html.twig', ['p' => $presentation, 'detached' => false]);
    }

    /** The presentation data of one message, for views that render parts of it (edit, show). */
    public function presentation(Message $message): MessagePresentation
    {
        return $this->loader->load([$message])[$message->getId()];
    }

    public function cacheKey(Message $message): string
    {
        return \sprintf('message.%d.%s.%s', $message->getId(), $message->getUpdatedAt()->format('YmdHisu'), $this->digest());
    }

    private function renderFragment(MessagePresentation $presentation, bool $detached): string
    {
        if ($presentation->isRenderable()) {
            try {
                return $this->twig->render('messages/_message.html.twig', [
                    'p' => $presentation,
                    'detached' => $detached,
                    'url_base' => $this->urlBase($detached),
                ]);
            } catch (\Throwable $error) {
                $this->logger?->error(\sprintf('Exception while rendering message Message#%d, failed with: %s `%s`', $presentation->message->getId(), $error::class, $error->getMessage()));
            }
        } else {
            $this->logger?->error(\sprintf('Exception while rendering message Message#%d, failed with: missing creator', $presentation->message->getId()));
        }

        // message_tag rescues and renders messages/_unrenderable in place of the message.
        return "\n  ".$this->twig->render('messages/_unrenderable.html.twig');
    }

    /**
     * Where `room_at_message_url` points: the request's base URL, or for a detached rendering
     * (SetCurrentRequest#default_url_options) its protocol and host without the port.
     */
    private function urlBase(bool $detached): string
    {
        $request = $this->current->request();
        if (null === $request) {
            return 'http://example.org';
        }

        return $detached ? $request->getScheme().'://'.$request->getHost() : $request->getSchemeAndHttpHost();
    }

    private function digest(): string
    {
        if (null === $this->digest) {
            $loader = $this->twig->getLoader();
            $sources = self::VERSION;
            foreach (self::TEMPLATES as $template) {
                $sources .= "\0".$loader->getSourceContext($template)->getCode();
            }
            $this->digest = substr(hash('xxh128', $sources), 0, 16);
        }

        return $this->digest;
    }
}
