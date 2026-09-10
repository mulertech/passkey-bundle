<?php

declare(strict_types=1);

namespace MulerTech\PasskeyBundle\Tests\Controller;

use MulerTech\PasskeyBundle\Controller\PasskeyController;
use MulerTech\PasskeyBundle\Entity\WebauthnCredential;
use MulerTech\PasskeyBundle\Repository\WebauthnCredentialRepository;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use MulerTech\PasskeyBundle\Tests\Fixture\PasskeyUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyControllerTest extends TestCase
{
    public function testListsTheKeysOfTheCurrentUser(): void
    {
        $credential = $this->credential('handle-1');

        $userEntities = $this->createStub(PublicKeyCredentialUserEntityRepositoryInterface::class);
        $userEntities->method('findOneByUsername')->willReturn(
            PublicKeyCredentialUserEntity::create('ada@example.com', 'handle-1', 'Ada'),
        );

        $credentials = $this->createStub(WebauthnCredentialRepository::class);
        $credentials->method('findAllForUserEntity')->willReturn([$credential]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with('@MulerTechPasskey/passkey/index.html.twig', ['credentials' => [$credential]])
            ->willReturn('<html></html>');

        $controller = $this->controller($userEntities, $credentials, ['twig' => $twig]);

        self::assertSame('<html></html>', $controller->index($this->user('handle-1'))->getContent());
    }

    /**
     * A user who has never registered a key has no WebAuthn identity yet: the page must open all
     * the same, since that is where the first key gets registered.
     */
    public function testListsNothingForAUserWithoutAWebauthnIdentity(): void
    {
        $userEntities = $this->createStub(PublicKeyCredentialUserEntityRepositoryInterface::class);
        $userEntities->method('findOneByUsername')->willReturn(null);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(self::anything(), ['credentials' => []])
            ->willReturn('<html></html>');

        $controller = $this->controller($userEntities, $this->createStub(WebauthnCredentialRepository::class), ['twig' => $twig]);

        self::assertSame('<html></html>', $controller->index($this->user(null))->getContent());
    }

    public function testDeletesTheKeyOfItsOwner(): void
    {
        $credentials = $this->createMock(WebauthnCredentialRepository::class);
        $credentials->expects(self::once())->method('remove');

        $controller = $this->controller(
            $this->createStub(PublicKeyCredentialUserEntityRepositoryInterface::class),
            $credentials,
            $this->servicesForDelete(tokenIsValid: true),
        );

        $response = $controller->delete($this->request(), $this->credential('handle-1'), $this->user('handle-1'));

        self::assertSame('/passkeys', $response->headers->get('Location'));
    }

    public function testRefusesToDeleteWithoutAValidToken(): void
    {
        $credentials = $this->createMock(WebauthnCredentialRepository::class);
        $credentials->expects(self::never())->method('remove');

        $controller = $this->controller(
            $this->createStub(PublicKeyCredentialUserEntityRepositoryInterface::class),
            $credentials,
            $this->servicesForDelete(tokenIsValid: false),
        );

        $controller->delete($this->request(), $this->credential('handle-1'), $this->user('handle-1'));
    }

    /**
     * The token proves intent, not ownership: with a valid token of their own, a signed-in user
     * must still not reach somebody else's key.
     */
    public function testRefusesToDeleteAKeyBelongingToSomeoneElse(): void
    {
        $credentials = $this->createMock(WebauthnCredentialRepository::class);
        $credentials->expects(self::never())->method('remove');

        $controller = $this->controller(
            $this->createStub(PublicKeyCredentialUserEntityRepositoryInterface::class),
            $credentials,
            $this->servicesForDelete(tokenIsValid: true),
        );

        $controller->delete($this->request(), $this->credential('handle-of-someone-else'), $this->user('handle-1'));
    }

    /**
     * @param array<string, object> $services
     */
    private function controller(
        PublicKeyCredentialUserEntityRepositoryInterface $userEntities,
        WebauthnCredentialRepository|MockObject $credentials,
        array $services,
    ): PasskeyController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Passkey deleted.');

        $controller = new PasskeyController(
            $userEntities,
            $credentials,
            $translator,
            '@MulerTechPasskey/passkey/index.html.twig',
        );

        $container = new Container();
        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * @return array<string, object>
     */
    private function servicesForDelete(bool $tokenIsValid): array
    {
        $tokens = $this->createStub(CsrfTokenManagerInterface::class);
        $tokens->method('isTokenValid')->willReturn($tokenIsValid);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/passkeys');

        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        return [
            'security.csrf.token_manager' => $tokens,
            'router' => $router,
            'request_stack' => $requestStack,
        ];
    }

    private function request(): Request
    {
        return new Request(request: ['_token' => 'a-token']);
    }

    private function user(?string $handle): PasskeyUser
    {
        return new PasskeyUser('ada@example.com', 'Ada Lovelace', $handle);
    }

    private function credential(string $userHandle): WebauthnCredential
    {
        return WebauthnCredential::fromRecord(
            new \Webauthn\CredentialRecord(
                'credential-id',
                'public-key',
                ['internal'],
                'none',
                new EmptyTrustPath(),
                Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                'public-key-bytes',
                $userHandle,
                0,
            ),
            'MacBook',
        );
    }
}
