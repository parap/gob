// Login / register / logout and screen switching.

function showScreen(name) {
    ['auth', 'game'].forEach(id =>
        $('screen-' + id).classList.toggle('hidden', id !== name));
    $('topbar').classList.toggle('hidden', name !== 'game');
}

function showAuthTab(tab) {
    document.querySelectorAll('.auth-tab').forEach(t =>
        t.classList.toggle('active', t.dataset.tab === tab));
    document.querySelectorAll('.auth-form').forEach(f =>
        f.classList.toggle('active', f.dataset.form === tab));
}

async function register() {
    $('auth-error').textContent = '';
    const { status, body } = await req('POST', '/auth/register', {
        username: $('reg-username').value,
        email:    $('reg-email').value,
        password: $('reg-password').value,
    });
    if (status === 201 && body.token) onLoggedIn(body);
    else $('auth-error').textContent = (body && body.error) || 'Registration failed.';
}

async function login() {
    $('auth-error').textContent = '';
    const { status, body } = await req('POST', '/auth/login', {
        username: $('login-username').value,
        password: $('login-password').value,
    });
    if (status === 200 && body.token) onLoggedIn(body);
    else $('auth-error').textContent = (body && body.error) || 'Login failed.';
}

async function logout() {
    await req('POST', '/auth/logout');
    if (state.ticker) clearInterval(state.ticker);
    state.playerId = state.username = state.token = null;
    state.settlements = []; state.current = null;
    localStorage.removeItem('gob_session');
    showScreen('auth');
}

function onLoggedIn(account) {
    state.playerId = account.id;
    state.username = account.username;
    state.token    = account.token;
    localStorage.setItem('gob_session', JSON.stringify(account));
    enterGame();
}
