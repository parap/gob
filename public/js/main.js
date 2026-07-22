// Entry point: wire up buttons and restore any saved session.

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.auth-tab').forEach(t =>
        t.addEventListener('click', () => showAuthTab(t.dataset.tab)));

    $('btn-login').addEventListener('click', login);
    $('btn-register').addEventListener('click', register);
    $('btn-logout').addEventListener('click', logout);

    // Submit auth forms on Enter.
    document.querySelectorAll('.auth-form input').forEach(input =>
        input.addEventListener('keydown', e => {
            if (e.key !== 'Enter') return;
            const form = input.closest('.auth-form').dataset.form;
            form === 'register' ? register() : login();
        }));

    const saved = localStorage.getItem('gob_session');
    if (saved) {
        try {
            const a = JSON.parse(saved);
            state.playerId = a.id;
            state.username = a.username;
            state.token = a.token;
            enterGame();
            return;
        } catch { localStorage.removeItem('gob_session'); }
    }
    showScreen('auth');
});
