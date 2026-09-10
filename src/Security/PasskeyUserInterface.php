<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What an application must expose for its users to carry passkeys.
 *
 * The handle is the identifier WebAuthn ties the key to, and it cannot be the email address: a
 * key outlives an address change, and a handle revealing an address would hand it to any site
 * that queries the authenticator.
 *
 * `WebauthnUserHandleTrait` implements it, Doctrine mapping included.
 */
interface PasskeyUserInterface extends UserInterface
{
    public function getWebauthnUserHandle(): ?string;

    public function setWebauthnUserHandle(string $webauthnUserHandle): void;

    /**
     * Name the authenticator shows when picking a key. The login identifier is an acceptable
     * fallback when the application has nothing more telling.
     */
    public function getPasskeyDisplayName(): string;
}
