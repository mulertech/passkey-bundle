<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\PasskeyBundle\Security\PasskeyUserInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\Bundle\Repository\CanRegisterUserEntity;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Bridges the application's users onto the WebAuthn user model.
 *
 * Lookup by identifier goes through the application's user provider rather than a query written
 * here: the field serving as the identifier, email or name, belongs to the project and the
 * bundle has no business knowing it.
 */
final readonly class WebauthnUserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface, CanRegisterUserEntity
{
    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     * @param class-string<PasskeyUserInterface>   $userClass
     */
    public function __construct(
        private UserProviderInterface $userProvider,
        private EntityManagerInterface $entityManager,
        private string $userClass,
    ) {
    }

    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        try {
            $user = $this->userProvider->loadUserByIdentifier($username);
        } catch (UserNotFoundException) {
            return null;
        }

        return $user instanceof PasskeyUserInterface ? $this->toUserEntity($user) : null;
    }

    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        $user = $this->entityManager
            ->getRepository($this->userClass)
            ->findOneBy(['webauthnUserHandle' => $userHandle]);

        return $user instanceof PasskeyUserInterface ? $this->toUserEntity($user) : null;
    }

    /**
     * The handle is written by ensureHandle() when it is generated, and the user itself is
     * created by the application: there is nothing to save here.
     */
    public function saveUserEntity(PublicKeyCredentialUserEntity $userEntity): void
    {
    }

    private function toUserEntity(PasskeyUserInterface $user): PublicKeyCredentialUserEntity
    {
        $displayName = $user->getPasskeyDisplayName();

        return PublicKeyCredentialUserEntity::create(
            $user->getUserIdentifier(),
            $this->ensureHandle($user),
            '' !== $displayName ? $displayName : $user->getUserIdentifier(),
        );
    }

    private function ensureHandle(PasskeyUserInterface $user): string
    {
        $handle = $user->getWebauthnUserHandle();

        if (null === $handle) {
            $handle = Uuid::v4()->toRfc4122();
            $user->setWebauthnUserHandle($handle);
            $this->entityManager->flush();
        }

        return $handle;
    }
}
