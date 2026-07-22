// The in-game view: load settlements and keep resources ticking.

function switchGamePanel(name) {
    document.querySelectorAll('.game-tab').forEach(t =>
        t.classList.toggle('active', t.dataset.panel === name));
    document.querySelectorAll('.game-panel').forEach(p =>
        p.classList.toggle('active', p.dataset.panel === name));
    // Vitals (HP especially) regenerate server-side — refetch so they're current.
    // Guard on an already-loaded character to avoid double-loading on entry.
    if (state.character) loadCharacter();
}

async function enterGame() {
    $('topbar-username').textContent = state.username;
    showScreen('game');
    switchGamePanel('settlement');
    await Promise.all([loadSettlements(), loadCharacter(), loadMonsters(), loadLocations()]);
    startTicker();
}

// Race + alignment + remaining tags as small badges.
function monsterBadges(m) {
    const badges = [];
    if (m.race) badges.push(`<span class="badge badge-race">${esc(m.race)}</span>`);
    if (m.alignment) badges.push(`<span class="badge badge-${esc(m.alignment)}">${esc(m.alignment)}</span>`);
    (m.tags || [])
        .filter(t => t !== m.race && t !== m.alignment)
        .forEach(t => badges.push(`<span class="badge">${esc(t)}</span>`));
    return badges.length ? `<span class="badges">${badges.join('')}</span>` : '';
}

async function loadMonsters() {
    const { status, body } = await req('GET', '/monsters');
    if (status !== 200 || !Array.isArray(body)) return;
    state.monsters = body;
    $('monster-list').innerHTML = body.map(m => `
        <div class="monster">
            <div class="monster-info">
                <span class="monster-name">${esc(m.name)} <em>Lv${m.level}</em>${infoIcon(m.description)}</span>
                <span class="monster-stats">${m.hp} hp · atk ${m.attack} · def ${m.defense} · ${m.reward_gold}g</span>
                ${monsterBadges(m)}
            </div>
            <button class="btn-mini" data-fight="${m.id}">Fight</button>
        </div>`).join('');
}

async function fight(monsterId) {
    const { status, body } = await req('POST', '/combat/attack', { monster_id: monsterId });
    if (status !== 200) return;
    setCharacter(body.character);   // hp/skills/loot already updated server-side
    renderCharacter();
    await loadSettlements();            // gold reward may have landed
    renderCombat(body);
}

function renderCombat(r) {
    const rw = r.rewards;
    const rewardBits = [];
    if (rw.gold) rewardBits.push(`+${rw.gold} gold`);
    if (rw.skills && rw.skills.length) rewardBits.push(`trained ${rw.skills.join(' & ')}`);
    if (rw.items && rw.items.length) rewardBits.push(`found ${rw.items.join(', ')}`);

    const head = `<div class="combat-head ${r.outcome}">
        ${r.outcome === 'win' ? 'Victory' : 'Defeat'} vs ${r.monster.name}
        — ${r.rounds} rounds${rewardBits.length ? ' · ' + rewardBits.join(', ') : ''}
    </div>`;

    const lines = r.log.map(e => {
        const who = e.actor === 'hero' ? 'You' : r.monster.name;
        const tgt = e.actor === 'hero' ? r.monster.name : 'you';
        return `<div class="combat-line ${e.actor}">R${e.round}: ${who} hit ${tgt} for ${e.damage} (${tgt} ${e.target_hp} hp)</div>`;
    }).join('');

    $('combat-log').innerHTML = head + lines;
}

async function loadCharacter() {
    const { status, body } = await req('GET', '/character/me');
    if (status === 401) { logout(); return; }
    if (status !== 200) return;
    setCharacter(body);
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

// Store a fresh character and stamp when its vitals were fetched, so HP can be
// projected forward from the regen rate (same idea as live resources).
function setCharacter(c) {
    c.vitalsFetchedAt = Date.now();
    state.character = c;
}

// Project current HP forward from the regen rate since the last fetch.
function liveHp() {
    const c = state.character;
    if (!c) return 0;
    const v = c.vitals;
    const elapsedMin = (Date.now() - c.vitalsFetchedAt) / 60000;
    const hp = v.hp + (v.hp_regen_per_min || 0) * elapsedMin;
    return Math.min(v.hp_max, Math.max(0, Math.floor(hp)));
}

// Paint the always-visible vitals bars using the projected HP.
function renderVitals() {
    const c = state.character;
    if (!c) return;
    const v = c.vitals;
    $('char-vitals').innerHTML =
        vitalBar('HP', liveHp(), v.hp_max) +
        vitalBar('Mana', v.mana, v.mana_max) +
        vitalBar('Courage', v.courage, v.courage_max);
}

// A hover-only info icon whose tooltip is the given description.
function infoIcon(desc) {
    return desc ? ` <span class="info" data-tip="${esc(desc)}">&#9432;</span>` : '';
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

    renderVitals();

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

    // Equipped items: description in the tooltip; click a filled slot to unequip.
    $('char-equipment').innerHTML = Object.entries(c.equipment)
        .map(([slot, item]) => item
            ? `<div class="slot filled" data-unequip="${slot}"
                    title="${esc(item.name + (item.description ? ' — ' + item.description : '') + ' (click to unequip)')}">
                   <span>${slotLabel(slot)}</span><b>${esc(item.name)}</b>
               </div>`
            : `<div class="slot empty"><span>${slotLabel(slot)}</span><b>—</b></div>`)
        .join('');

    // Backpack: Equip gear or Use consumables; each shows its description.
    $('char-inventory').innerHTML = c.inventory.length
        ? c.inventory.map(it => {
            const consumable = it.kind === 'consumable';
            const info = consumable ? `heals ${it.heal}` : bonusText(it.bonuses);
            const btn = consumable
                ? `<button class="btn-mini" data-use="${it.char_item_id}">Use</button>`
                : `<button class="btn-mini" data-equip="${it.char_item_id}">Equip</button>`;
            const sell = `<button class="btn-mini btn-sell" data-sell="${it.char_item_id}">Sell ${it.sell_value}g</button>`;
            return `<div class="inv-item">
                <div class="inv-main">
                    <span class="inv-head"><span class="inv-name">${esc(it.name)}</span>${infoIcon(it.description)} <span class="inv-bonus">${esc(info)}</span></span>
                </div>
                <div class="inv-actions">${btn}${sell}</div>
            </div>`;
        }).join('')
        : '<p class="muted">Empty.</p>';
}

async function equipItem(charItemId) {
    const { status, body } = await req('POST', '/items/equip', { char_item_id: charItemId });
    if (status === 200) { setCharacter(body); renderCharacter(); }
}

async function unequipSlot(slot) {
    const { status, body } = await req('POST', '/items/unequip', { slot });
    if (status === 200) { setCharacter(body); renderCharacter(); }
}

async function useItem(charItemId) {
    const { status, body } = await req('POST', '/items/use', { char_item_id: charItemId });
    if (status === 200) { setCharacter(body.character); renderCharacter(); }
}

async function sellItem(charItemId) {
    const { status, body } = await req('POST', '/items/sell', { char_item_id: charItemId });
    if (status === 200) {
        setCharacter(body.character);
        renderCharacter();
        loadSettlements();   // gold went to the settlement
    }
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

// Disable a button and count down; restore its label when the cooldown ends.
function startCooldown(btnId, label, seconds) {
    const btn = $(btnId);
    let left = seconds;
    const tick = () => {
        if (left <= 0) { btn.disabled = false; btn.textContent = label; return; }
        btn.disabled = true;
        btn.textContent = `Resting (${left}s)`;
        left--;
        setTimeout(tick, 1000);
    };
    tick();
}
const startLootCooldown = (s) => startCooldown('btn-loot', 'Search for loot', s);

async function loadLocations() {
    const { status, body } = await req('GET', '/locations');
    if (status !== 200 || !Array.isArray(body)) return;
    state.locations = body;
    renderLocations();
}

async function explore() {
    const { status, body } = await req('POST', '/explore');
    if (status === 429) {
        $('explore-result').textContent = body.error;
        startCooldown('btn-explore', 'Explore', body.retry_after);
        return;
    }
    if (status !== 200) return;
    $('explore-result').textContent = body.found
        ? `Discovered a ${body.found.type}: ${body.found.name} (Lv${body.found.level})!`
        : body.message;
    state.locations = body.locations;
    renderLocations();
    startCooldown('btn-explore', 'Explore', body.cooldown_seconds);
}

async function advance(playerLocationId) {
    const { status, body } = await req('POST', '/locations/advance', { player_location_id: playerLocationId });
    if (status !== 200) return;
    setCharacter(body.character);
    renderCharacter();
    state.locations = body.locations;
    renderLocations();
    await loadSettlements();
    renderCombat(body.combat);   // detailed log (visible on the Adventure tab)

    // Inline summary so the result is visible without leaving the Exploration tab.
    const cmb = body.combat;
    let msg = `${cmb.monster.name}: ${cmb.outcome === 'win' ? 'Victory' : 'Defeat'} — ${cmb.hero_hp_after} hp`;
    if (body.cleared) {
        const r = body.completion || {};
        const bits = [];
        if (r.rate) {
            const parts = Object.entries(r.rate).filter(([, v]) => v).map(([k, v]) => `+${v} ${k}/hr`);
            if (parts.length) bits.push(parts.join(', '));
        }
        if (r.regen) bits.push(`+${r.regen} regen/min`);
        if (r.item) bits.push(`found ${r.item}`);
        msg = `Cleared ${body.location_name}! ${bits.join(' · ')}`;
    }
    $('explore-result').textContent = msg;
}

function rateRewardText(reward) {
    const parts = [];
    if (reward.gold_rate) parts.push(`+${reward.gold_rate} gold/hr`);
    if (reward.wood_rate) parts.push(`+${reward.wood_rate} wood/hr`);
    if (reward.stone_rate) parts.push(`+${reward.stone_rate} stone/hr`);
    if (reward.regen) parts.push(`+${reward.regen} regen/min`);
    return parts.join(', ');
}

function renderLocations() {
    if (!state.locations.length) {
        $('location-list').innerHTML = '<p class="muted">Explore to discover sites and dungeons.</p>';
        return;
    }
    $('location-list').innerHTML = state.locations.map(l => {
        const reward = l.type === 'site' && rateRewardText(l.reward)
            ? `<span class="loc-reward">${rateRewardText(l.reward)} when cleared</span>` : '';
        const body = l.state === 'cleared'
            ? `<span class="loc-cleared">Cleared ✓</span>`
            : `<span class="loc-next">Next: ${l.next_monster ?? '—'}</span>
               <button class="btn-mini" data-advance="${l.id}">Delve</button>`;
        return `<div class="location loc-${l.type} ${l.state}">
            <div class="loc-info">
                <span class="loc-name">${l.name} <em>${l.type} · Lv${l.level}</em></span>
                <span class="loc-progress">${l.progress}/${l.total_stages} stages</span>
                ${reward}
            </div>
            <div class="loc-action">${body}</div>
        </div>`;
    }).join('');
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
    state.ticker = setInterval(() => { updateResources(); renderVitals(); }, 1000);
}
