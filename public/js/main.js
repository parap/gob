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
        const sl = e.target.closest('[data-sell]');
        if (sl) { sellItem(parseInt(sl.dataset.sell)); return; }
        if (e.target.id === 'btn-loot') { searchLoot(); return; }
        const fg = e.target.closest('[data-fight]');
        if (fg) { fight(parseInt(fg.dataset.fight)); return; }
        if (e.target.id === 'btn-explore') { exploreWorld(); return; }
        const dv = e.target.closest('[data-delve]');
        if (dv) { delveSite(parseInt(dv.dataset.delve)); return; }
        const tv = e.target.closest('[data-travel]');
        if (tv) { travelTo(parseInt(tv.dataset.travel)); return; }
        const ask = e.target.closest('[data-ask]');
        if (ask) { openQuestDialog(parseInt(ask.dataset.ask)); return; }
        const qt = e.target.closest('[data-turnin]');
        if (qt) { turnInQuest(parseInt(qt.dataset.turnin)); }
    });

    // Modal / dialogue window: backdrop or [data-modal-close] closes;
    // [data-quest-accept] accepts the offered quest then closes.
    $('modal').addEventListener('click', e => {
        if (e.target.id === 'modal' || e.target.closest('[data-modal-close]')) {
            closeModal();
            return;
        }
        const qa = e.target.closest('[data-quest-accept]');
        if (qa) { acceptQuest(parseInt(qa.dataset.questAccept)); closeModal(); }
    });

    // Esc closes the modal.
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !$('modal').classList.contains('hidden')) closeModal();
    });

    // React to manual URL/hash changes (and back/forward) while in-game.
    window.addEventListener('hashchange', () => {
        if (!$('screen-game').classList.contains('hidden')) {
            switchGamePanel(location.hash.slice(1));
        }
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
