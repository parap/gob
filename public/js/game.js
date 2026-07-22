// The in-game view: load settlements and keep resources ticking.

async function enterGame() {
    $('topbar-username').textContent = state.username;
    showScreen('game');
    await Promise.all([loadSettlements(), loadCharacter()]);
    startTicker();
}

async function loadCharacter() {
    const { status, body } = await req('GET', '/character/me');
    if (status === 401) { logout(); return; }
    if (status !== 200) return;
    state.character = body;
    renderCharacter();
}

// Turn a slot key like "ring_1" / "head" into a readable label.
function slotLabel(slot) {
    return slot.replace(/_(\d)$/, ' $1').replace(/^\w/, c => c.toUpperCase());
}

function vitalBar(label, value, max) {
    const pct = max > 0 ? Math.round((value / max) * 100) : 0;
    return `<div class="vital">
        <span class="vital-label">${label}</span>
        <span class="vital-track"><span class="vital-fill vital-${label.toLowerCase()}" style="width:${pct}%"></span></span>
        <span class="vital-num">${value}/${max}</span>
    </div>`;
}

function renderCharacter() {
    const c = state.character;
    if (!c) return;
    $('char-name').textContent = c.name;

    const v = c.vitals;
    $('char-vitals').innerHTML =
        vitalBar('HP', v.hp, v.hp_max) +
        vitalBar('Mana', v.mana, v.mana_max) +
        vitalBar('Courage', v.courage, v.courage_max);

    $('char-stats').innerHTML = Object.entries(c.stats)
        .map(([k, val]) => `<div class="stat"><span>${k.toUpperCase()}</span><b>${val}</b></div>`)
        .join('');

    $('char-skills').innerHTML = Object.entries(c.skills)
        .map(([k, val]) => `<div class="skill"><span>${k}</span><b>${val}</b></div>`)
        .join('');

    $('char-equipment').innerHTML = Object.entries(c.equipment)
        .map(([slot, item]) =>
            `<div class="slot ${item ? 'filled' : 'empty'}">
                <span>${slotLabel(slot)}</span>
                <b>${item ? '#' + item : '—'}</b>
            </div>`)
        .join('');
}

async function loadSettlements() {
    const { status, body } = await req('GET', '/settlements/me');
    if (status === 401) { logout(); return; }
    if (status !== 200 || !Array.isArray(body)) return;

    const now = Date.now();
    state.settlements = body.map(s => ({ ...s, fetchedAt: now }));
    state.current = state.settlements[0] || null;
    renderSettlement();
}

function renderSettlement() {
    const s = state.current;
    if (!s) { $('settlement-name').textContent = 'No settlement'; return; }
    $('settlement-name').textContent = s.name;
    $('settlement-terrain').textContent = s.terrain;
    $('rate-gold').textContent  = s.rate_gold_per_hour;
    $('rate-wood').textContent  = s.rate_wood_per_hour;
    $('rate-stone').textContent = s.rate_stone_per_hour;
    updateResources();
}

// Called every second: recompute projected amounts and paint them.
function updateResources() {
    const s = state.current;
    if (!s) return;
    const r = liveResources(s);
    $('res-gold').textContent  = r.gold.toLocaleString();
    $('res-wood').textContent  = r.wood.toLocaleString();
    $('res-stone').textContent = r.stone.toLocaleString();
    $('g-gold').textContent  = r.gold.toLocaleString();
    $('g-wood').textContent  = r.wood.toLocaleString();
    $('g-stone').textContent = r.stone.toLocaleString();
}

function startTicker() {
    if (state.ticker) clearInterval(state.ticker);
    state.ticker = setInterval(updateResources, 1000);
}
