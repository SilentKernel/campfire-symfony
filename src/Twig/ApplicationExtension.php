<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Twig\Html\Tag;
use App\Twig\View\ViewContext;
use App\Twig\View\ViewHelpers;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * reference/app/helpers/application_helper.rb, cable_helper.rb and version_helper.rb, plus the
 * request state views read (Current.user, flash, platform).
 *
 * Rails views set `@page_title` / `@body_class`; templates set the `page_title` / `body_class`
 * variables at the top level (`{% set page_title = 'Sign in' %}`), which the layout reads.
 */
final class ApplicationExtension extends AbstractExtension
{
    public function __construct(
        private readonly ViewContext $context,
        private readonly ViewHelpers $helpers,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(APP_VERSION)%')]
        private readonly string $appVersion = '',
        #[Autowire('%env(GIT_REVISION)%')]
        private readonly string $gitRevision = '',
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey = '',
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('page_title_tag', $this->pageTitleTag(...), $safe + ['needs_context' => true]),
            new TwigFunction('body_classes', $this->bodyClasses(...), ['needs_context' => true]),
            new TwigFunction('current_user_meta_tags', $this->currentUserMetaTags(...), $safe),
            new TwigFunction('custom_styles_tag', $this->customStylesTag(...), $safe),
            new TwigFunction('script_aware_action_cable_meta_tag', $this->scriptAwareActionCableMetaTag(...), $safe),
            new TwigFunction('vapid_public_key_meta_tag', fn (): string => Tag::void('meta', ['name' => 'vapid-public-key', 'content' => '' === $this->vapidPublicKey ? null : $this->vapidPublicKey]), $safe),
            new TwigFunction('link_back', $this->linkBack(...), $safe),
            new TwigFunction('link_back_to', $this->linkBackTo(...), $safe),
            new TwigFunction('version_badge', $this->versionBadge(...), $safe),
            new TwigFunction('app_version', $this->appVersion(...)),
            new TwigFunction('current_user', $this->context->user(...)),
            new TwigFunction('current_account', $this->context->account(...)),
            new TwigFunction('flash', $this->context->flash(...)),
            new TwigFunction('platform', $this->context->platform(...)),
        ];
    }

    /**
     * `tag.title @page_title || "Campfire"`.
     *
     * @param array<string, mixed> $context
     */
    public function pageTitleTag(array $context): string
    {
        $title = $context['page_title'] ?? null;

        return Tag::content('title', null === $title || false === $title ? 'Campfire' : $title);
    }

    /**
     * `[ @body_class, admin_body_class, account_logo_body_class ].compact.join(" ")`.
     *
     * @param array<string, mixed> $context
     */
    public function bodyClasses(array $context): string
    {
        $classes = [
            $context['body_class'] ?? null,
            true === $this->context->user()?->canAdminister() ? 'admin' : null,
            $this->context->accountHasLogo() ? 'account-has-logo' : null,
        ];

        return implode(' ', array_map(strval(...), array_filter($classes, static fn (mixed $class): bool => null !== $class)));
    }

    public function currentUserMetaTags(): ?string
    {
        if (!($user = $this->context->user()) instanceof User) {
            return null;
        }

        return Tag::legacy('meta', ['name' => 'current-user-id', 'content' => $user->getId()])
            .Tag::legacy('meta', ['name' => 'current-user-name', 'content' => $user->getName()]);
    }

    public function customStylesTag(): ?string
    {
        $customStyles = $this->context->account()?->getCustomStyles();
        if (null === $customStyles) {
            return null;
        }

        return Tag::content('style', new Markup($customStyles, 'UTF-8'), ['data' => ['turbo_track' => 'reload']]);
    }

    /** `Pathname(request.script_name) + Pathname(ActionCable.server.config.mount_path)`: always "/cable". */
    public function scriptAwareActionCableMetaTag(): string
    {
        return Tag::void('meta', ['name' => 'action-cable-url', 'content' => '/cable']);
    }

    public function linkBack(): string
    {
        $request = $this->context->request();
        $backUrl = $request?->headers->get('referer');
        if (null === $backUrl || '' === $backUrl || $backUrl === $request?->getUri()) {
            $backUrl = $this->urlGenerator->generate('root');
        }

        return $this->linkBackTo($backUrl);
    }

    public function linkBackTo(string $destination): string
    {
        $content = $this->helpers->imageTag('arrow-left.svg', ['aria' => ['hidden' => 'true'], 'size' => 20])
            .Tag::content('span', 'Go Back', ['class' => 'for-screen-reader']);

        return $this->helpers->linkTo(new Markup($content, 'UTF-8'), $destination, ['class' => 'btn']);
    }

    /** Rails.application.config.app_version (config/initializers/version.rb). */
    public function appVersion(): string
    {
        return '' !== $this->appVersion ? $this->appVersion : ('' !== $this->gitRevision ? $this->gitRevision : '0');
    }

    public function versionBadge(): string
    {
        return Tag::content('span', $this->appVersion(), ['class' => 'version-badge']);
    }
}
