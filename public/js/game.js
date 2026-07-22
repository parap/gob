// The in-game view: load settlements and keep resources ticking.

async function enterGame() {
    $('topbar-username').textContent = state.username;
    showScreen('game');
    await Promise.all([loadSettlements(), loadCharacter(), loadMonsters(), loadLocations()]);
    startTicker();
}

async function loadMonsters() {
    const { status, body } = await req('GET', '/monsters');
    if (status !== 200 || !Array.isArray(body)) return;
    state.monsters = body;
    $('monster-list').innerHTML = body.map(m => `
        <div class="monster">
            <div class="monster-info">
                <span class="monster-name">${m.name} <em>Lv${m.level}</em></span>
                <span class="monster-stats">${m.hp} hp · atk ${m.attack} · def ${m.defense} · ${m.reward_gold}g</span>
            </div>
            <button class="btn-mini" data-fight="${m.id}">Fight</button>
        </div>`).join('');
}

async function fight(monsterId) {
    const { status, body } = await req('POST', '/combat/attack', { monster_id: monsterId });
    if (status !== 200) return;
    state.character = body.character;   // hp/skills/loot already updated server-side
    renderCharacter();
    await loadSettlements();            // gold reward may have landed
    renderCombat(body);
}

function renderCombat(r) {
    const rw = r.rewards;
    const rewardBits = [];
    if (rw.gold) rewardBits.push(`+${rw.gold} gold`);
    if (rw.skills && rw.skills.length) rewardBits.push(`trained ${rw.skills.join(' & ')}`);
    if (rw.items && rw.items.length) rewardBits.push(`found loot!`);

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
    state.character = body.character;
    renderCharacter();
    state.locations = body.locations;
    renderLocations();
    await loadSettlements();
    renderCombat(body.combat);
    if (body.cleared) {
        const r = body.completion || {};
        const bits = [];
        if (r.rate) {
            const parts = Object.entries(r.rate).filter(([, v]) => v).map(([k, v]) => `+${v} ${k}/hr`);
            if (parts.length) bits.push(parts.join(', '));
        }
        if (r.item) bits.push('reward item');
        $('explore-result').textContent = `Cleared ${body.location_name}! ${bits.join(' · ')}`;
    }
}

function rateRewardText(reward) {
    const parts = [];
    if (reward.gold_rate) parts.push(`+${reward.gold_rate} gold/hr`);
    if (reward.wood_rate) parts.push(`+${reward.wood_rate} wood/hr`);
    if (reward.stone_rate) parts.push(`+${reward.stone_rate} stone/hr`);
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
    state.ticker = setInterval(updateResources, 1000);
}
