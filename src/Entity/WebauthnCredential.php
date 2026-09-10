<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\PasskeyBundle\Repository\WebauthnCredentialRepository;
use Webauthn\CredentialRecord;

/**
 * A registered passkey.
 *
 * Every WebAuthn field comes from the mapped superclass of `web-auth`; what this adds is the
 * label the user recognises and the registration date, without which a management page would
 * show nothing but binary identifiers nobody can tell apart.
 */
#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
class WebauthnCredential extends CredentialRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $label = 'Passkey';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Builds the persistable entity from the record the attestation ceremony produces.
     */
    public static function fromRecord(CredentialRecord $record, string $label): self
    {
        $credential = new self(
            $record->publicKeyCredentialId,
            $record->type,
            $record->transports,
            $record->attestationType,
            $record->trustPath,
            $record->aaguid,
            $record->credentialPublicKey,
            $record->userHandle,
            $record->counter,
            $record->otherUI,
            $record->backupEligible,
            $record->backupStatus,
            $record->uvInitialized,
        );
        $credential->label = $label;
        $credential->createdAt = new \DateTimeImmutable();

        return $credential;
    }
}
