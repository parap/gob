// Entry point: wire up buttons and restore any saved session.

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.auth-tab').forEach(t =>
        t.addEventListener('click', () => showAuthTab(t.dataset.tab)));

    $('btn-login').addEventListener('click', login);
    $('btn-register').addEventListener('click', register);
    $('btn-logout').addEventListener('click', logout);

    // Equip (backpack button) / unequip (click a filled slot).
    $('screen-game').addEventListener('click', e => {
        const tab = e.target.closest('.game-tab');
        if (tab) { switchGamePanel(tab.dataset.panel); return; }
        const eq = e.target.closest('[data-equip]');
        if (eq) { equipItem(parseInt(eq.dataset.equip)); return; }
        const un = e.target.closest('[data-unequip]');
        if (un) { unequipSlot(un.dataset.unequip); return; }
        const us = e.target.closest('[data-use]');
        if (us) { useItem(parseInt(us.dataset.use)); return; }
        if (e.target.id === 'btn-loot') { searchLoot(); return; }
        const fg = e.target.closest('[data-fight]');
        if (fg) { fight(parseInt(fg.dataset.fight)); return; }
        if (e.target.id === 'btn-explore') { explore(); return; }
        const av = e.target.closest('[data-advance]');
        if (av) { advance(parseInt(av.dataset.advance)); }
    });

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
