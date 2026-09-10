# Release notes for passkey-bundle

## v2.0.0 - 2026-09-10

### Breaking

- `register_options_url` and `register_url` are now required. The management page reads the ceremony endpoints from the configuration instead of guessing them, so a path that does not match `webauthn.controllers.creation` is refused when the container compiles rather than answering `404` after the browser has already accepted the fingerprint.

### Upgrading

Declare both paths once and feed them to the two bundles:

```yaml
# config/packages/mulertech_passkey.yaml
parameters:
    app.passkey_register_options_path: /passkey/register/options
    app.passkey_register_path: /passkey/register

mulertech_passkey:
    user_class: App\Entity\User
    register_options_url: '%app.passkey_register_options_path%'
    register_url: '%app.passkey_register_path%'

```
```yaml
# config/packages/webauthn.yaml
webauthn:
    controllers:
        creation:
            default:
                options_path: '%app.passkey_register_options_path%'
                result_path: '%app.passkey_register_path%'

```
The passkey_register_options_url and passkey_register_url Twig globals are no longer read: remove them.

## v1.0.0 - 2026-09-10

- Added: passkey (WebAuthn) sign-in for a Symfony application — the credential entity and its two repositories, the user handle, the key management page, and the ceremony asset.
- Note: the four ceremony endpoints are not reimplemented here. `web-auth/webauthn-symfony-bundle` already serves them and their paths are declared in the application's own configuration; what this bundle carries is everything a project otherwise copies, measured at around three hundred lines of PHP per project plus the credential table.
- Added: `PasskeyUserInterface` and `WebauthnUserHandleTrait`. The trait brings the `webauthnUserHandle` column, unique and nullable, generated the first time a user registers a key. The handle cannot be the email address: a key outlives an address change, and a handle revealing one would hand it to any site that queries the authenticator.
- Added: a key management page — list, register, delete — behind a routes file the application imports under the prefix of its choice. Deletion requires both a valid CSRF token and a handle matching the current user: the token proves intent, the handle proves ownership.
- Added: the ceremony asset, framework-free, configured entirely through `data-*` attributes so it knows nothing of the application's paths. It hides the passkey affordances on browsers without WebAuthn, stays silent when the user cancels, and names the technical error otherwise — an HTTP status sends you to the server log, a `NotAllowedError` to the browser.
- Added: a "stay signed in" checkbox for the sign-in ceremony. Symfony only issues a remember-me cookie when the request carries `_remember_me`, and the authenticator builds its `RememberMeBadge` without parameters and never inspects the JSON body, so the flag travels in the query string.
- Added: the bundle declares its own Doctrine mapping and names its repositories to `webauthn`, both before the application's configuration so a project wanting its own wins.
