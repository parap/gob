// The in-game view: load settlements and keep resources ticking.

const GAME_PANELS = ['settlement', 'character', 'adventure', 'exploration'];

function switchGamePanel(name) {
    if (!GAME_PANELS.includes(name)) name = 'settlement';
    document.querySelectorAll('.game-tab').forEach(t =>
        t.classList.toggle('active', t.dataset.panel === name));
    document.querySelectorAll('.game-panel').forEach(p =>
        p.classList.toggle('active', p.dataset.panel === name));
    // Reflect the tab in the URL so a reload restores it (no navigation/event).
    if (location.hash.slice(1) !== name) history.replaceState(null, '', '#' + name);
    // Vitals (HP especially) regenerate server-side — refetch so they're current.
    // Guard on an already-loaded character to avoid double-loading on entry.
    if (state.character) loadCharacter();
}

async function enterGame() {
    $('topbar-username').textContent = state.username;
    showScreen('game');
    switchGamePanel(location.hash.slice(1) || 'settlement');
    await Promise.all([loadSettlements(), loadCharacter(), loadMonsters(), loadWorld()]);
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

async function loadWorld() {
    const { status, body } = await req('GET', '/world');
    if (status !== 200) return;
    state.world = body;
    renderWorld();
}

// Qualitative exploration stage instead of a raw percentage.
function exploreLabel(pct) {
    if (pct >= 100) return 'Fully explored';
    if (pct <= 0)   return 'Unexplored';
    if (pct < 20)   return 'Barely explored';
    if (pct < 40)   return 'Slightly explored';
    if (pct < 60)   return 'Somewhat explored';
    if (pct < 80)   return 'Mostly explored';
    return 'Nearly explored';
}

function rewardText(reward) {
    const parts = [];
    if (reward.gold_rate) parts.push(`+${reward.gold_rate} gold/hr`);
    if (reward.wood_rate) parts.push(`+${reward.wood_rate} wood/hr`);
    if (reward.stone_rate) parts.push(`+${reward.stone_rate} stone/hr`);
    if (reward.regen) parts.push(`+${reward.regen} regen/min`);
    if (reward.gold) parts.push(`${reward.gold}g`);
    if (reward.item_id) parts.push('loot');
    return parts.join(', ');
}

function renderWorld() {
    const w = state.world;
    if (!w) return;
    const cur = (w.provinces || []).find(p => p.is_current) || w.provinces[0];
    if (!cur) return;

    $('province-name').textContent = cur.name;
    $('province-terrain').textContent = cur.terrain;
    $('province-level').textContent = cur.level;
    $('explore-bar').style.width = cur.explored_pct + '%';
    $('explore-pct').textContent = exploreLabel(cur.explored_pct);

    // Sites discovered in the current province.
    const sites = (w.sites && w.sites[cur.id]) || [];
    const actionable = sites.filter(s => s.type !== 'road');
    $('site-list').innerHTML = actionable.length
        ? actionable.map(s => {
            const action = s.state === 'cleared'
                ? `<span class="loc-cleared">Cleared ✓</span>`
                : `<button class="btn-mini" data-delve="${s.id}">Delve</button>`;
            const next = s.state === 'found' && s.next_monster
                ? `<span class="loc-next">Guarded by ${esc(s.next_monster)}</span>` : '';
            const typeTag = s.type === 'minor' ? '' : ` <em>${s.type}</em>`;
            return `<div class="location loc-${s.type} ${s.state}">
                <div class="loc-info">
                    <span class="loc-name">${esc(s.name)}${typeTag}</span>
                    ${next}
                </div>
                <div class="loc-action">${action}</div>
            </div>`;
        }).join('')
        : '<p class="muted">Nothing uncovered here yet — keep exploring.</p>';

    // The province map: current + travel to others.
    $('province-list').innerHTML = (w.provinces || []).map(p => {
        const tag = p.is_current
            ? `<span class="loc-cleared">You are here</span>`
            : `<button class="btn-mini btn-sell" data-travel="${p.id}">Travel</button>`;
        return `<div class="location ${p.is_current ? 'current' : ''}">
            <div class="loc-info">
                <span class="loc-name">${esc(p.name)} <em>${p.terrain} · Lv${p.level}${p.is_home ? ' · home' : ''}</em></span>
                <span class="loc-progress">${exploreLabel(p.explored_pct)}${p.is_current ? '' : ' · REMOTE — TO BE TRAVELLED'}</span>
            </div>
            <div class="loc-action">${tag}</div>
        </div>`;
    }).join('');
}

const SEARCH_MS = 3500;   // full-sweep search animation

async function exploreWorld() {
    const btn = $('btn-explore');
    if (btn.disabled) return;
    btn.disabled = true;
    btn.textContent = 'Searching…';
    const res = $('explore-result');
    res.innerHTML = '';

    const bar = $('search-bar');
    bar.style.transition = 'none';
    bar.style.width = '0%';
    void bar.offsetWidth;                       // reflow so the reset takes

    const { status, body } = await req('POST', '/world/explore');
    if (status !== 200) {
        btn.disabled = false;
        btn.textContent = 'Explore';
        if (status === 429) res.textContent = body.error;
        return;
    }

    // Sweep the whole area; each finding pops when the bar reaches its position.
    bar.style.transition = `width ${SEARCH_MS}ms linear`;
    bar.style.width = '100%';

    (body.found || []).forEach(f => {
        const at = Math.min(100, Math.max(0, f.at || 0));
        setTimeout(() => {
            const label = f.type === 'road'
                ? `🛣 ${esc(f.name)}`
                : `Found ${esc(f.name)}${f.type === 'minor' ? '' : ' — ' + f.type}`;
            res.insertAdjacentHTML('beforeend', `<div class="find-line">${label}</div>`);
        }, (at / 100) * SEARCH_MS);
    });

    // At 100% the search ends: reconcile authoritative state and wrap up.
    setTimeout(async () => {
        btn.disabled = false;
        btn.textContent = 'Explore';
        bar.style.transition = 'none';
        bar.style.width = '0%';

        setCharacter(body.character);
        renderCharacter();
        await Promise.all([loadWorld(), loadSettlements()]);

        if (body.new_province) {
            res.insertAdjacentHTML('beforeend', `<div class="find-line">→ new province: ${esc(body.new_province.name)} (${body.new_province.terrain})</div>`);
        }
        if (body.raid) {
            const r = body.raid;
            renderCombat(r.combat);
            res.insertAdjacentHTML('beforeend', `<div class="find-line">⚔ raid by ${esc(r.monster)}: ${r.combat.outcome}${r.lost_site ? ` (lost ${esc(r.lost_site)}!)` : ''}</div>`);
        }
        if (!(body.found || []).length && !body.new_province && !body.raid) {
            res.textContent = 'Found nothing this time.';
        }
    }, SEARCH_MS);
}

async function travelTo(provinceId) {
    const { status } = await req('POST', '/world/travel', { province_id: provinceId });
    if (status === 200) { $('explore-result').textContent = ''; await loadWorld(); }
}

async function delveSite(siteId) {
    const { status, body } = await req('POST', '/world/sites/advance', { site_id: siteId });
    if (status !== 200) return;
    setCharacter(body.character);
    renderCharacter();
    await Promise.all([loadWorld(), loadSettlements()]);
    renderCombat(body.combat);

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
        if (r.gold) bits.push(`+${r.gold}g`);
        if (r.item) bits.push(`found ${r.item}`);
        msg = `Cleared ${esc(body.site.name)}! ${bits.join(' · ')}`;
    }
    $('explore-result').textContent = msg;
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
