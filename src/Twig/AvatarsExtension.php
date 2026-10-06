<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Twig\Html\Tag;
use App\Twig\View\ViewContext;
use App\Twig\View\ViewHelpers;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * reference/app/helpers/users/avatars_helper.rb and accounts_helper.rb, and the `direct` routes
 * fresh_user_avatar / fresh_account_logo (reference/config/routes.rb), which cache-bust with the
 * record's updated_at (`to_fs(:number)`).
 *
 * Routes used: user {id}, user_avatar {user_id, v}, account_logo {v, size}.
 */
final class AvatarsExtension extends AbstractExtension
{
    public const AVATAR_COLORS = [
        '#AF2E1B', '#CC6324', '#3B4B59', '#BFA07A', '#ED8008', '#ED3F1C', '#BF1B1B', '#736B1E', '#D07B53',
        '#736356', '#AD1D1D', '#BF7C2A', '#C09C6F', '#698F9C', '#7C956B', '#5D618F', '#3B3633', '#67695E',
    ];

    public function __construct(
        private readonly ViewContext $context,
        private readonly ViewHelpers $helpers,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('avatar_tag', $this->avatarTag(...), $safe),
            new TwigFunction('avatar_background_color', self::avatarBackgroundColor(...)),
            new TwigFunction('fresh_user_avatar_path', $this->freshUserAvatarPath(...)),
            new TwigFunction('fresh_user_avatar_url', $this->freshUserAvatarUrl(...)),
            new TwigFunction('fresh_account_logo_path', $this->freshAccountLogoPath(...)),
            new TwigFunction('account_logo_tag', $this->accountLogoTag(...), $safe),
        ];
    }

    /** `AVATAR_COLORS[Zlib.crc32(user.to_param) % AVATAR_COLORS.size]` */
    public static function avatarBackgroundColor(User $user): string
    {
        return self::AVATAR_COLORS[crc32((string) $user->getId()) % \count(self::AVATAR_COLORS)];
    }

    /** @param array<string, mixed> $options extra image_tag options */
    public function avatarTag(User $user, array $options = []): string
    {
        $image = $this->helpers->imageTag($this->freshUserAvatarPath($user), array_merge(['aria' => ['hidden' => 'true'], 'size' => 48], $options));

        return $this->helpers->linkTo(new Markup($image, 'UTF-8'), $this->urlGenerator->generate('user', ['id' => $user->getId()]), [
            'title' => $user->title(),
            'class' => 'btn avatar',
            'data' => ['turbo_frame' => '_top'],
        ]);
    }

    public function freshUserAvatarPath(User $user): string
    {
        return $this->urlGenerator->generate('user_avatar', ['user_id' => $this->context->avatarToken($user), 'v' => $user->getUpdatedAt()->format('YmdHis')]);
    }

    public function freshUserAvatarUrl(User $user): string
    {
        return $this->urlGenerator->generate('user_avatar', ['user_id' => $this->context->avatarToken($user), 'v' => $user->getUpdatedAt()->format('YmdHis')], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** reference/config/routes.rb `direct :fresh_account_logo`; Rails sorts the query keys (size before v). */
    public function freshAccountLogoPath(int|string|null $size = null): string
    {
        return $this->urlGenerator->generate('account_logo', ['size' => $size, 'v' => $this->context->account()?->getUpdatedAt()->format('YmdHis')]);
    }

    /** `tag.figure image_tag(fresh_account_logo_path, alt: "Account logo", size: 300), class: "account-logo avatar #{style}"` */
    public function accountLogoTag(?string $style = null): string
    {
        return Tag::content('figure', new Markup($this->helpers->imageTag($this->freshAccountLogoPath(), ['alt' => 'Account logo', 'size' => 300]), 'UTF-8'), ['class' => 'account-logo avatar '.$style]);
    }
}
