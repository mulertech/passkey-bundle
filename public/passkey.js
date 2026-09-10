import {
    startRegistration,
    startAuthentication,
    browserSupportsWebAuthn,
} from '@simplewebauthn/browser';

/*
 * Drives the WebAuthn (passkey) ceremonies against the bundle endpoints.
 * Vanilla JS: each container carries its configuration as data-* attributes.
 *
 *   <div data-passkey
 *        data-passkey-redirect-url="/"
 *        data-passkey-remember-me-selector="#…"   (login page, optional)
 *        data-passkey-error-message="…"
 *        data-passkey-already-registered-message="…">
 *       <button data-passkey-action="login">…</button>   (login page)
 *       <button data-passkey-action="register">…</button> (management page)
 *       <p data-passkey-status hidden></p>
 *   </div>
 */

const DEFAULTS = {
    registerOptionsUrl: '/passkey/register/options',
    registerUrl: '/passkey/register',
    loginOptionsUrl: '/passkey/login/options',
    loginUrl: '/passkey/login',
    redirectUrl: '/',
};

function config(container) {
    const d = container.dataset;

    return {
        registerOptionsUrl: d.passkeyRegisterOptionsUrl || DEFAULTS.registerOptionsUrl,
        registerUrl: d.passkeyRegisterUrl || DEFAULTS.registerUrl,
        loginOptionsUrl: d.passkeyLoginOptionsUrl || DEFAULTS.loginOptionsUrl,
        loginUrl: d.passkeyLoginUrl || DEFAULTS.loginUrl,
        redirectUrl: d.passkeyRedirectUrl || DEFAULTS.redirectUrl,
        rememberMeSelector: d.passkeyRememberMeSelector || '',
        errorMessage: d.passkeyErrorMessage || '',
        alreadyRegisteredMessage: d.passkeyAlreadyRegisteredMessage || '',
    };
}

/*
 * Symfony only issues a remember-me cookie when the request carries _remember_me.
 * The bundle's authenticator builds its RememberMeBadge without parameters, so the
 * JSON body is never inspected: the flag has to travel in the query string.
 */
function withRememberMe(cfg, url) {
    if (!cfg.rememberMeSelector) {
        return url;
    }

    const checkbox = document.querySelector(cfg.rememberMeSelector);

    return checkbox?.checked ? `${url}?_remember_me=1` : url;
}

async function postJson(url, payload) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        // The body carries what the server refused, and it is the only place that says why:
        // a ceremony rejected server-side reaches the browser as a plain HTTP failure.
        const detail = await response.text().catch(() => '');
        const error = new Error(`Request to ${url} failed with status ${response.status}`);
        error.name = `HTTP ${response.status}`;
        error.detail = detail.slice(0, 300);
        throw error;
    }

    return response.json();
}

function setStatus(container, message) {
    const status = container.querySelector('[data-passkey-status]');
    if (status) {
        status.textContent = message;
        status.hidden = false;
    }
}

function clearStatus(container) {
    const status = container.querySelector('[data-passkey-status]');
    if (status) {
        status.textContent = '';
        status.hidden = true;
    }
}

/*
 * Turns a WebAuthn ceremony failure into a user-facing status:
 *  - user cancelled / timed out  -> stay silent;
 *  - a passkey already exists on this device -> gentle, explicit message;
 *  - anything else -> generic message with the technical error name appended.
 */
function reportError(container, cfg, error) {
    const name = error?.name ?? 'Error';

    if (name === 'NotAllowedError' || name === 'AbortError') {
        return;
    }

    if (name === 'InvalidStateError' && cfg.alreadyRegisteredMessage) {
        setStatus(container, cfg.alreadyRegisteredMessage);
        return;
    }

    if (cfg.errorMessage) {
        // The technical name makes the message its own lead: "(HTTP 400)" sends you to the server
        // log, "(NotSupportedError)" to the browser. A bare "(Error)" sends you nowhere.
        setStatus(container, `${cfg.errorMessage} (${name})`);
        console.error(cfg.errorMessage, error?.detail ?? error);
    }
}

async function run(container, action) {
    clearStatus(container);
    container.dataset.busy = 'true';
    try {
        await action();
    } catch (error) {
        reportError(container, config(container), error);
    } finally {
        delete container.dataset.busy;
    }
}

async function register(container) {
    const cfg = config(container);
    await run(container, async () => {
        const optionsJSON = await postJson(cfg.registerOptionsUrl, {});
        const attResp = await startRegistration({ optionsJSON });
        await postJson(cfg.registerUrl, attResp);
        window.location.reload();
    });
}

async function login(container) {
    const cfg = config(container);
    await run(container, async () => {
        const optionsJSON = await postJson(cfg.loginOptionsUrl, {});
        const authResp = await startAuthentication({ optionsJSON });
        await postJson(withRememberMe(cfg, cfg.loginUrl), authResp);
        window.location.href = cfg.redirectUrl;
    });
}

function init() {
    const containers = document.querySelectorAll('[data-passkey]');
    if (containers.length === 0) {
        return;
    }

    // Hide passkey affordances on browsers without WebAuthn support.
    if (!browserSupportsWebAuthn()) {
        containers.forEach((container) => {
            container.hidden = true;
        });
        return;
    }

    containers.forEach((container) => {
        container.querySelectorAll('[data-passkey-action]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                if ('register' === button.dataset.passkeyAction) {
                    register(container);
                } else if ('login' === button.dataset.passkeyAction) {
                    login(container);
                }
            });
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
