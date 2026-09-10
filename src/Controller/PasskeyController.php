<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Controller;

use MulerTech\PasskeyBundle\Entity\WebauthnCredential;
use MulerTech\PasskeyBundle\Repository\WebauthnCredentialRepository;
use MulerTech\PasskeyBundle\Security\PasskeyUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;

/**
 * A user's passkeys: list them, delete one.
 *
 * The four ceremony endpoints are not here: `web-auth/webauthn-symfony-bundle` serves them
 * itself, and their paths are declared in the application's own configuration.
 */
#[IsGranted('ROLE_USER')]
final class PasskeyController extends AbstractController
{
    public function __construct(
        private readonly PublicKeyCredentialUserEntityRepositoryInterface $userEntities,
        private readonly WebauthnCredentialRepository $credentials,
        private readonly TranslatorInterface $translator,
        private readonly string $template,
    ) {
    }

    public function index(#[CurrentUser] PasskeyUserInterface $user): Response
    {
        $userEntity = $this->userEntities->findOneByUsername($user->getUserIdentifier());

        return $this->render($this->template, [
            'credentials' => null !== $userEntity ? $this->credentials->findAllForUserEntity($userEntity) : [],
        ]);
    }

    public function delete(
        Request $request,
        WebauthnCredential $credential,
        #[CurrentUser] PasskeyUserInterface $user,
    ): Response {
        // Two conditions, never one: the token proves intent, the handle proves ownership.
        // Without the second, any signed-in user would delete someone else's key by guessing
        // an id.
        if ($this->isCsrfTokenValid('delete-passkey'.$credential->getId(), (string) $request->request->get('_token'))
            && $credential->userHandle === $user->getWebauthnUserHandle()
        ) {
            $this->credentials->remove($credential);
            // Le message est traduit ici, et non laissé sous forme de clé : l'application affiche
            // ses notifications comme elle l'entend, souvent sans `|trans` et jamais avec le
            // domaine de ce bundle. Une clé brute finirait telle quelle sous les yeux de
            // l'utilisateur.
            $this->addFlash('success', $this->translator->trans('passkey.flash.deleted', [], 'MulerTechPasskeyBundle'));
        }

        return $this->redirectToRoute('mulertech_passkey_index');
    }
}
