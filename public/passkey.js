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

/*
 * One delegated listener on the document, bound as soon as the module is evaluated.
 *
 * Binding on the buttons themselves ties the page to the DOM present at that instant, and a
 * library that replaces the body between navigations (Turbo, and any morphing equivalent) leaves
 * the new buttons unbound: the module is already in the module map, so it is never evaluated a
 * second time. Clicking then does nothing at all, without an error anywhere. Delegation holds
 * whatever replaces the markup.
 */
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-passkey-action]');
    const container = button?.closest('[data-passkey]');

    if (!container) {
        return;
    }

    event.preventDefault();

    if ('register' === button.dataset.passkeyAction) {
        register(container);
    } else if ('login' === button.dataset.passkeyAction) {
        login(container);
    }
});

// Hide passkey affordances on browsers without WebAuthn support.
function hideUnsupported() {
    if (browserSupportsWebAuthn()) {
        return;
    }

    document.querySelectorAll('[data-passkey]').forEach((container) => {
        container.hidden = true;
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hideUnsupported);
} else {
    hideUnsupported();
}
