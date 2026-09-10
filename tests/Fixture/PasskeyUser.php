<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Tests\Fixture;

use MulerTech\PasskeyBundle\Security\PasskeyUserInterface;
use MulerTech\PasskeyBundle\Security\WebauthnUserHandleTrait;

final class PasskeyUser implements PasskeyUserInterface
{
    use WebauthnUserHandleTrait;

    public function __construct(
        private readonly string $identifier,
        private readonly string $displayName,
        ?string $webauthnUserHandle = null,
    ) {
        $this->webauthnUserHandle = $webauthnUserHandle;
    }

    public function getPasskeyDisplayName(): string
    {
        return $this->displayName;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
