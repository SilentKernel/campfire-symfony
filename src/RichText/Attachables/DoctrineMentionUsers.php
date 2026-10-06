<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

use App\Entity\User;
use App\Rails\SignedGlobalId;
use App\Repository\UserRepository;
use App\Twig\AvatarsExtension;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Users as mentions see them: `User.find(id)` (any status, as Active Record finds them), with
 * `attachable_sgid` and `avatar_tag` for reference/app/views/users/_mention.html.erb.
 */
#[AsAlias(MentionUsers::class)]
final readonly class DoctrineMentionUsers implements MentionUsers
{
    public function __construct(
        private UserRepository $users,
        private SignedGlobalId $signedGlobalIds,
        private AvatarsExtension $avatars,
    ) {
    }

    public function find(int $id): ?MentionUser
    {
        $user = $this->users->find($id);

        return $user instanceof User ? $this->mentionUser($user) : null;
    }

    public function mentionUser(User $user): MentionUser
    {
        return new MentionUser(
            $user->getId(),
            $user->getName(),
            $this->signedGlobalIds->generate('User', $user->getId()),
            $this->avatars->avatarTag($user),
        );
    }
}
