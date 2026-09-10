<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Security;

use Doctrine\ORM\Mapping as ORM;

/**
 * The user's WebAuthn handle, mapping included.
 *
 * Unique and nullable: it is generated only when the user registers a first key, and two users
 * cannot share one without their keys becoming indistinguishable.
 */
trait WebauthnUserHandleTrait
{
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $webauthnUserHandle = null;

    public function getWebauthnUserHandle(): ?string
    {
        return $this->webauthnUserHandle;
    }

    public function setWebauthnUserHandle(string $webauthnUserHandle): void
    {
        $this->webauthnUserHandle = $webauthnUserHandle;
    }
}
