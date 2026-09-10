# PasskeyBundle

___
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mulertech/passkey-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/passkey-bundle)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/passkey-bundle/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mulertech/passkey-bundle/actions/workflows/tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/passkey-bundle/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/mulertech/passkey-bundle/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mulertech/passkey-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/passkey-bundle)
[![Test Coverage](https://raw.githubusercontent.com/mulertech/passkey-bundle/badge/badge-coverage.svg)](https://packagist.org/packages/mulertech/passkey-bundle)
___

Passkey (WebAuthn) sign-in for a Symfony application: credential storage, a key management page,
and the ceremony asset.

## What this bundle is, and is not

`web-auth/webauthn-symfony-bundle` already serves the four ceremony endpoints, and their paths are
declared in your own configuration. This bundle does not reimplement them. It carries what every
project otherwise copies: the credential entity and its two repositories, the user handle, the
management page, and the JavaScript that drives the ceremonies.

## Requirements

- PHP 8.4+
- Symfony 6.4+, 7.x or 8.x
- Doctrine ORM 3, DoctrineBundle 2.12+ or 3.x
- `web-auth/webauthn-symfony-bundle` 5.3+

## Installation

```bash
composer require mulertech/passkey-bundle
```

### 1. Register the bundle

```php
// config/bundles.php
return [
    // …
    Webauthn\Bundle\WebauthnBundle::class => ['all' => true],
    MulerTech\PasskeyBundle\MulerTechPasskeyBundle::class => ['all' => true],
];
```

### 2. Prepare the user entity

```php
use MulerTech\PasskeyBundle\Security\PasskeyUserInterface;
use MulerTech\PasskeyBundle\Security\WebauthnUserHandleTrait;

class User implements PasskeyUserInterface
{
    use WebauthnUserHandleTrait;

    public function getPasskeyDisplayName(): string
    {
        return $this->fullName ?? $this->email;
    }
}
```

The trait brings the `webauthnUserHandle` column, unique and nullable: it is generated the first
time the user registers a key.

### 3. Configure

```yaml
# config/packages/mulertech_passkey.yaml
mulertech_passkey:
    user_class: App\Entity\User
```

`user_provider` defaults to `security.user.provider.concrete.app_user_provider`, and `template` to
the page shipped here. Declare either only if yours differs.

### 4. Import the routes, under the prefix you want

```yaml
# config/routes/mulertech_passkey.yaml
mulertech_passkey:
    resource: "@MulerTechPasskeyBundle/config/routes.yaml"
    prefix: /passkeys
```

The management page then answers on `/passkeys/`, and Symfony redirects the bare prefix to it.

### 5. Declare the ceremonies

These belong to `web-auth`, and the paths are yours to choose. The bundle points its repositories
at `web-auth` on its own; what remains is the profile and the endpoints.

```yaml
# config/packages/webauthn.yaml
webauthn:
    creation_profiles:
        default:
            rp:
                id: '%env(WEBAUTHN_RP_ID)%'
            authenticator_selection_criteria:
                authenticator_attachment: platform
                user_verification: required
                resident_key: required
                require_resident_key: true
    request_profiles:
        default:
            user_verification: required
    controllers:
        enabled: true
        creation:
            default:
                profile: default
                user_entity_guesser: Webauthn\Bundle\Security\Guesser\CurrentUserEntityGuesser
                options_path: /passkey/register/options
                result_path: /passkey/register
```

The ceremony endpoints are served by a dedicated route loader, not by a routes file. Without this
import they simply do not exist, and the ceremony answers `404`:

```yaml
# config/routes/webauthn.yaml
webauthn_controllers:
    resource: .
    type: webauthn
```

```yaml
# config/packages/security.yaml
security:
    firewalls:
        main:
            webauthn:
                authentication:
                    enabled: true
                    routes:
                        options_path: /passkey/login/options
                        result_path: /passkey/login
                registration:
                    enabled: false
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 2592000
```

The sign-in ceremony must be reachable without a session — it addresses someone who is not signed
in yet. Registering a key, on the other hand, belongs to an open session:

```yaml
security:
    access_control:
        - { path: ^/login$, roles: PUBLIC_ACCESS }
        - { path: ^/passkey/login, roles: PUBLIC_ACCESS }
        - { path: ^/, roles: ROLE_USER }
```

Behind `ROLE_USER`, the ceremony redirects to the login page instead of starting, and the browser
reports whatever that redirect leads to rather than the real cause.

`remember_me` is what the "stay signed in" checkbox below relies on.

### Local development over plain HTTP

WebAuthn requires an HTTPS origin, and the library refuses the ceremony without one — `localhost`
included. A development server answering in plain HTTP therefore fails the ceremony with
`Invalid scheme. HTTPS required.`, after the fingerprint prompt has already succeeded. Declare the
local origin, and only in development:

```yaml
# config/packages/webauthn.yaml
when@dev:
    webauthn:
        allowed_origins:
            - '%env(WEBAUTHN_ALLOWED_ORIGIN)%'   # http://localhost:8000
```

In production the origin is HTTPS and the list stays empty, which restores the default
requirement.

### 6. Create the table

The bundle maps its entity; the migration belongs to your project:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

### 7. Serve the asset

```bash
php bin/console importmap:require @simplewebauthn/browser
```

The pages shipped here carry Tailwind utility classes, which are inert markup without Tailwind.
A project using Tailwind has to declare them, since `vendor/` is not scanned:

```css
/* assets/styles/app.css */
@source "../../vendor/mulertech/passkey-bundle/templates";
```

```twig
{# in your layout, or on the two pages that need it #}
<script type="module" nonce="{{ csp_nonce('main') }}">import '{{ asset('bundles/mulertechpasskey/passkey.js') }}';</script>
```

## Usage

### Sign-in page

```twig
<div data-passkey
     data-passkey-login-options-url="/passkey/login/options"
     data-passkey-login-url="/passkey/login"
     data-passkey-redirect-url="{{ path('app_home') }}"
     data-passkey-remember-me-selector="#remember_me_passkey"
     data-passkey-error-message="{{ 'passkey.error'|trans }}">
    {# No name attribute: this box is never submitted, the asset reads it to append ?_remember_me=1 #}
    <input type="checkbox" id="remember_me_passkey">
    <label for="remember_me_passkey">Stay signed in without asking for my passkey again</label>

    <button type="button" data-passkey-action="login">Sign in with a passkey</button>
    <p data-passkey-status hidden></p>
</div>
```

Symfony only issues a remember-me cookie when the request carries `_remember_me`. The authenticator
of `web-auth` builds its `RememberMeBadge` without parameters and never inspects the JSON body, so
the flag travels in the query string, which is what the asset does when the box is ticked.

### Management page

`mulertech_passkey_index` renders the list and the "add a passkey" button. To place it inside your
site, override the layout alone:

```twig
{# templates/bundles/MulerTechPasskeyBundle/passkey/layout.html.twig #}
{% extends 'base.html.twig' %}
{% block body %}
    {% for message in app.flashes('success') %}<div>{{ message }}</div>{% endfor %}
    {% block passkey_content %}{% endblock %}
{% endblock %}
```

Deleting a key adds a flash message. Render it where this layout puts it, unless your base template
already does: otherwise the message waits for the next page that renders one, and the deletion looks
like it did nothing.

### Under a nonce-based CSP

The asset is a module served from `'self'`, so `script-src 'self'` covers it. It writes no inline
style and installs no inline handler.

## Testing

```bash
./vendor/bin/mtdocker test-ai
```
