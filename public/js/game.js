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

// Format an item's bonuses like "+1 str, +10 hp".
function bonusText(bonuses) {
    const keys = Object.keys(bonuses || {});
    if (!keys.length) return '';
    return keys.map(k => `${bonuses[k] > 0 ? '+' : ''}${bonuses[k]} ${k}`).join(', ');
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

    // Show effective value, with the base in parentheses when gear changed it.
    const statRow = (label, base, eff) => {
        const extra = eff !== base ? ` <em>(${base})</em>` : '';
        return `<div class="stat"><span>${label}</span><b>${eff}${extra}</b></div>`;
    };

    $('char-stats').innerHTML = Object.keys(c.stats)
        .map(k => statRow(k.toUpperCase(), c.stats[k], c.stats_effective[k]))
        .join('');

    $('char-substats').innerHTML = Object.keys(c.substats)
        .map(k => statRow(k[0].toUpperCase() + k.slice(1), c.substats[k], c.substats_effective[k]))
        .join('');

    $('char-skills').innerHTML = Object.entries(c.skills)
        .map(([k, val]) => `<div class="skill"><span>${k}</span><b>${val}</b></div>`)
        .join('');

    // Equipped items: click a filled slot to unequip.
    $('char-equipment').innerHTML = Object.entries(c.equipment)
        .map(([slot, item]) => item
            ? `<div class="slot filled" data-unequip="${slot}" title="Click to unequip">
                   <span>${slotLabel(slot)}</span><b>${item.name}</b>
               </div>`
            : `<div class="slot empty"><span>${slotLabel(slot)}</span><b>—</b></div>`)
        .join('');

    // Backpack: click Equip to wear an item.
    $('char-inventory').innerHTML = c.inventory.length
        ? c.inventory.map(it => {
            const b = bonusText(it.bonuses);
            return `<div class="inv-item">
                <span class="inv-name">${it.name}</span>
                <span class="inv-bonus">${b}</span>
                <button class="btn-mini" data-equip="${it.char_item_id}">Equip</button>
            </div>`;
        }).join('')
        : '<p class="muted">Empty.</p>';
}

async function equipItem(charItemId) {
    const { status, body } = await req('POST', '/items/equip', { char_item_id: charItemId });
    if (status === 200) { state.character = body; renderCharacter(); }
}

async function unequipSlot(slot) {
    const { status, body } = await req('POST', '/items/unequip', { slot });
    if (status === 200) { state.character = body; renderCharacter(); }
}

async function searchLoot() {
    const { status, body } = await req('POST', '/loot/search');
    if (status === 200) {
        const f = body.found;
        $('loot-result').textContent = `Found: ${f.name} (${f.rarity})`;
        await loadCharacter();            // refresh backpack
        startLootCooldown(body.cooldown_seconds);
    } else if (status === 429) {
        $('loot-result').textContent = body.error;
        startLootCooldown(body.retry_after);
    }
}

// Disable the button and count down; re-enable when the cooldown ends.
function startLootCooldown(seconds) {
    const btn = $('btn-loot');
    let left = seconds;
    const tick = () => {
        if (left <= 0) { btn.disabled = false; btn.textContent = 'Search for loot'; return; }
        btn.disabled = true;
        btn.textContent = `Resting (${left}s)`;
        left--;
        setTimeout(tick, 1000);
    };
    tick();
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
