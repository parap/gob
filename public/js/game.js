// The in-game view: load settlements and keep resources ticking.

const GAME_PANELS = ['settlement', 'village', 'character', 'adventure', 'exploration'];

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
    await Promise.all([loadSettlements(), loadCharacter(), loadMonsters(), loadWorld(), loadVillage(), loadRelations()]);
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

const FIGHT_MS = 1600;   // blow-by-blow bar; shorter than a full area sweep

// Sweep a transient action bar 0 -> 100%, the way the explore search bar does.
// Resolves when the sweep finishes, so an action can wait for the animation
// and the request both: whichever takes longer sets the pace.
function sweepBar(barId, ms) {
    const bar = $(barId);
    if (!bar) return Promise.resolve();
    bar.style.transition = 'none';
    bar.style.width = '0%';
    void bar.offsetWidth;                       // reflow so the reset takes
    bar.style.transition = `width ${ms}ms linear`;
    bar.style.width = '100%';
    return new Promise(resolve => setTimeout(() => {
        bar.style.transition = 'none';
        bar.style.width = '0%';
        resolve();
    }, ms));
}

// "Fighting Goblin Scout…" while the bar fills, then "… Victory!" the moment
// it lands. Announcing the outcome as a verdict keeps the result readable at a
// glance without having to parse the blow-by-blow log underneath.
function setFightStatus(id, name, outcome) {
    const el = $(id);
    if (!el) return;
    const who = `Fighting ${esc(name)}…`;
    el.innerHTML = outcome
        ? `${who} <span class="verdict ${outcome === 'win' ? 'win' : 'loss'}">${outcome === 'win' ? 'Victory!' : 'Defeat!'}</span>`
        : who;
}

// Disable every button matching a selector for the duration of an action, so a
// second fight can't be started on top of the first. Returns the undo.
function lockButtons(selector) {
    const btns = [...document.querySelectorAll(selector)];
    const labels = btns.map(b => b.textContent);
    btns.forEach(b => { b.disabled = true; });
    return () => btns.forEach((b, i) => { b.disabled = false; b.textContent = labels[i]; });
}

async function fight(monsterId) {
    const btn = document.querySelector(`[data-fight="${monsterId}"]`);
    if (btn && btn.disabled) return;
    const unlock = lockButtons('[data-fight]');
    if (btn) btn.textContent = 'Fighting…';
    clearMercyBox();

    const name = state.monsters.find(m => m.id === monsterId)?.name || 'it';
    setFightStatus('fight-status', name);
    const sweep = sweepBar('fight-bar', FIGHT_MS);
    const { status, body } = await req('POST', '/combat/attack', { monster_id: monsterId });
    await sweep;
    unlock();

    if (status !== 200) { $('fight-status').innerHTML = ''; return; }
    setFightStatus('fight-status', name, body.outcome);
    setCharacter(body.character);   // hp/skills/loot already updated server-side
    renderCharacter();
    await loadSettlements();            // gold reward may have landed
    loadVillage();                      // a kill may have advanced a quest
    loadRelations();                    // the deed moved how the race sees you
    renderCombat(body);
}

// ── Mercy ────────────────────────────────────────────────────────────────────
// The stance is a plain toggle with no explanation attached: what sparing costs
// and what it buys is for the player to find out.

async function toggleMercy() {
    const { status, body } = await req('POST', '/character/mercy', { on: !state.character?.mercy });
    if (status !== 200) return;
    setCharacter(body.character);
    renderCharacter();
}

function renderMercyBar() {
    const on = !!state.character?.mercy;
    const btn = $('btn-mercy');
    if (!btn) return;
    btn.textContent = `Mercy: ${on ? 'on' : 'off'}`;
    btn.classList.toggle('active', on);
}

// The 30s window after a spare: kill it after all, or let it crawl away. Runs
// off a deadline rather than a counter, so re-rendering never rewinds it.
function renderMercyWindow(monsterName) {
    const box = $('mercy-window');
    if (!box) return;
    if (state.mercyTimer) { clearInterval(state.mercyTimer); state.mercyTimer = null; }

    const w = state.character?.mercy_window;
    if (!w || !state.mercyDeadline) {
        // A window that just closed leaves its outcome on screen until the next
        // fight clears it; only wipe the box when there's nothing to say.
        if (!box.querySelector('.mercy-closed')) box.innerHTML = '';
        return;
    }

    const name = monsterName
        || state.monsters.find(m => m.id === w.monster_id)?.name
        || 'It';

    const paint = () => {
        const left = Math.ceil((state.mercyDeadline - Date.now()) / 1000);
        if (left <= 0) {
            clearInterval(state.mercyTimer);
            state.mercyTimer = null;
            state.mercyDeadline = null;
            box.innerHTML = `<span class="mercy-closed muted">${esc(name)} crawls away into the dark.</span>`;
            loadCharacter();     // settles the spare server-side
            loadRelations();
            return;
        }
        box.innerHTML = `<span class="mercy-open">${esc(name)} lies at your mercy — ${left}s</span>
            <button class="btn-mini" id="btn-finish">Finish it</button>
            <button class="btn-mini" id="btn-leave">Leave</button>`;
    };
    paint();
    state.mercyTimer = setInterval(paint, 1000);
}

async function finishSpared() {
    const { status, body } = await req('POST', '/combat/finish');
    if (status !== 200) { loadCharacter(); return; }
    setCharacter(body.character);
    renderCharacter();
    await loadSettlements();
    loadVillage();
    loadRelations();

    const bits = [];
    if (body.rewards.gold) bits.push(`+${body.rewards.gold} gold`);
    if (body.rewards.items.length) bits.push(`took ${body.rewards.items.join(', ')}`);
    if (state.mercyTimer) { clearInterval(state.mercyTimer); state.mercyTimer = null; }
    $('mercy-window').innerHTML =
        `<span class="mercy-closed">You finish ${esc(body.finished)}.${bits.length ? ' ' + esc(bits.join(', ')) : ''}</span>`;
}

// Walk away deliberately instead of waiting the countdown out. Same outcome as
// letting it expire, so the player can choose mercy rather than just run out
// the clock on it.
async function leaveSpared() {
    const { status, body } = await req('POST', '/combat/leave');
    if (status !== 200) { loadCharacter(); return; }
    setCharacter(body.character);
    renderCharacter();
    loadRelations();

    if (state.mercyTimer) { clearInterval(state.mercyTimer); state.mercyTimer = null; }
    $('mercy-window').innerHTML =
        `<span class="mercy-closed">You leave ${esc(body.left)} where it lies.</span>`;
}

// Wipe the mercy bar (and any countdown) — a new fight supersedes whatever the
// last one left there.
function clearMercyBox() {
    if (state.mercyTimer) { clearInterval(state.mercyTimer); state.mercyTimer = null; }
    state.mercyDeadline = null;
    const box = $('mercy-window');
    if (box) box.innerHTML = '';
}

// One line describing what the stance did to the loser, or '' when it did
// nothing (stance off, or the fight was lost).
function mercyLine(r) {
    const m = r.mercy;
    if (!m || r.outcome !== 'win') return '';
    if (m.outcome === 'spared') {
        return `<div class="combat-mercy">You let ${esc(r.monster.name)} live — no gold, no loot.</div>`;
    }
    if (m.outcome === 'fanatic') {
        return `<div class="combat-mercy">${esc(r.monster.name)} fights to the last and dies.</div>`;
    }
    if (m.outcome === 'unsparable') {
        return `<div class="combat-mercy">There was never anyone there to spare.</div>`;
    }
    return '';
}

function renderCombat(r, targetId = 'combat-log') {
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

    $(targetId).innerHTML = head + mercyLine(r) + lines;
    renderMercyWindow(r.monster.name);
}

// ── Tuition: the one training slot ───────────────────────────────────────────

function clock(seconds) {
    const m = Math.floor(seconds / 60), s = seconds % 60;
    return m ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
}

// Ticked once a second alongside the vitals. When the lesson's time is up the
// skill is banked server-side on the next character read, so we fetch once.
function renderTraining() {
    const box = $('training-slot');
    if (!box) return;
    const t = state.character?.training;
    if (!t || !state.trainingDeadline) { box.innerHTML = ''; return; }

    const left = Math.ceil((state.trainingDeadline - Date.now()) / 1000);
    if (left <= 0) {
        box.innerHTML = `<span class="mercy-closed">You finish studying ${esc(t.label)}.</span>`;
        if (!state.trainingSettling) {   // one fetch, not one per tick
            state.trainingSettling = true;
            loadCharacter().then(loadVillage);
        }
        return;
    }
    box.innerHTML = `<span class="training-open">Studying ${esc(t.label)} — ${clock(left)} left (+${t.gain})</span>`;
}

async function startTraining(npcId) {
    const { status, body } = await req('POST', '/training/start', { npc_id: npcId });
    if (status !== 201 && status !== 200) return;
    setCharacter(body.character);
    renderCharacter();
    await Promise.all([loadSettlements(), loadVillage()]);   // fee left the coffers
}

// ── Standing: how each race currently sees you ───────────────────────────────

async function loadRelations() {
    const { status, body } = await req('GET', '/relations');
    if (status !== 200 || !Array.isArray(body)) return;
    state.relations = body;
    renderRelations();
}

function renderRelations() {
    const el = $('char-standing');
    if (!el) return;
    el.innerHTML = state.relations.length
        ? state.relations.map(r => `<div class="skill" title="hostility ${r.hostility} · trust ${r.trust}">
               <span>${esc(r.race)}</span><b>${esc(r.stage_label)}</b>
           </div>`).join('')
        : '<p class="muted">Nobody has an opinion of you yet.</p>';
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
    // Turn the server's remaining seconds into local deadlines once, so the
    // countdowns survive unrelated re-renders.
    state.mercyDeadline = c.mercy_window ? Date.now() + c.mercy_window.seconds_left * 1000 : null;
    state.trainingDeadline = c.training ? Date.now() + c.training.seconds_left * 1000 : null;
    if (c.training) state.trainingSettling = false;
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
    renderMercyBar();
    renderMercyWindow();

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
            const trophy = it.kind === 'trophy';
            const info = consumable ? `heals ${it.heal}` : (trophy ? 'trophy' : bonusText(it.bonuses));
            const btn = consumable
                ? `<button class="btn-mini" data-use="${it.char_item_id}">Use</button>`
                : trophy
                    ? ''
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

// One discovered-site row (shared by the site list and the "delving" section).
function siteRow(s) {
    const action = s.state === 'cleared'
        ? `<span class="loc-cleared">Cleared ✓</span>`
        : `<button class="btn-mini" data-delve="${s.id}">Delve</button>`;
    // Current level for multi-stage sites in progress (no total shown).
    const level = (s.total_stages > 1 && s.state === 'found') ? `Level ${s.progress + 1} · ` : '';
    const next = s.state === 'found' && s.next_monster
        ? `<span class="loc-next">${level}Guarded by ${esc(s.next_monster)}${infoIcon(s.next_monster_desc)}</span>` : '';
    const typeTag = s.type === 'minor' ? '' : ` <em>${s.type}</em>`;
    const inProg = (s.progress > 0 && s.state === 'found') ? ' in-progress' : '';
    return `<div class="location loc-${s.type} ${s.state}${inProg}">
        <div class="loc-info">
            <span class="loc-name">${esc(s.name)}${typeTag}</span>
            ${next}
        </div>
        <div class="loc-action">${action}</div>
    </div>`;
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
    // Sort: fresh finds newest-first, cleared at the bottom. Ordering is by
    // when it was found — id is generation order, which has nothing to do with
    // the order the player uncovered them in.
    const foundOrder = s => (s.found_at ? Date.parse(s.found_at.replace(' ', 'T')) : 0) || 0;
    const sites = (w.sites && w.sites[cur.id]) || [];
    const actionable = sites.filter(s => s.type !== 'road').sort((a, b) => {
        const ac = a.state === 'cleared' ? 1 : 0;
        const bc = b.state === 'cleared' ? 1 : 0;
        if (ac !== bc) return ac - bc;
        return (foundOrder(b) - foundOrder(a)) || (b.id - a.id);
    });

    // Partly-delved sites get their own "Currently delving" section on top.
    const isDelving = s => s.progress > 0 && s.state === 'found';
    const delving = actionable.filter(isDelving);
    const rest    = actionable.filter(s => !isDelving(s));

    $('current-delve').innerHTML = delving.length
        ? '<h3>Currently delving</h3>' + delving.map(siteRow).join('')
        : '';
    $('site-list').innerHTML = rest.length
        ? rest.map(siteRow).join('')
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
            renderCombat(r.combat, 'explore-log');
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
    const btn = document.querySelector(`[data-delve="${siteId}"]`);
    if (btn && btn.disabled) return;
    const unlock = lockButtons('[data-delve]');
    if (btn) btn.textContent = 'Fighting…';
    clearMercyBox();

    // The site payload already names whatever guards the next stage.
    const cur  = (state.world?.provinces || []).find(p => p.is_current);
    const site = ((state.world?.sites || {})[cur?.id] || []).find(s => s.id === siteId);
    const name = site?.next_monster || site?.name || 'it';
    setFightStatus('delve-status', name);
    const sweep = sweepBar('delve-bar', FIGHT_MS);
    const { status, body } = await req('POST', '/world/sites/advance', { site_id: siteId });
    await sweep;
    unlock();

    if (status !== 200) { $('delve-status').innerHTML = ''; return; }
    setFightStatus('delve-status', name, body.combat.outcome);
    setCharacter(body.character);
    renderCharacter();
    await Promise.all([loadWorld(), loadSettlements(), loadVillage(), loadRelations()]);

    // Full battle log + loot, right here on the Exploration tab.
    renderCombat(body.combat, 'explore-log');
    if (body.cleared) {
        const r = body.completion || {};
        const bits = [];
        if (r.rate) {
            const parts = Object.entries(r.rate).filter(([, v]) => v).map(([k, v]) => `+${v} ${k}/hr`);
            if (parts.length) bits.push(parts.join(', '));
        }
        if (r.regen) bits.push(`+${r.regen} regen/min`);
        if (r.gold) bits.push(`+${r.gold}g`);
        if (r.items && r.items.length) bits.push(`found ${esc(r.items.join(', '))}`);
        $('explore-log').insertAdjacentHTML('beforeend',
            `<div class="combat-head win">Cleared ${esc(body.site.name)}!${bits.length ? ' ' + bits.join(' · ') : ''}</div>`);
    }
    $('explore-result').textContent = '';
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
    state.ticker = setInterval(() => { updateResources(); renderVitals(); renderTraining(); }, 1000);
}

// ── Village: resident NPCs + quests ──────────────────────────────────────────

async function loadVillage() {
    const [n, q] = await Promise.all([req('GET', '/npcs'), req('GET', '/quests')]);
    if (n.status === 200) state.village = n.body;
    if (q.status === 200) state.quests = q.body.quests || [];
    renderVillage();
}

// One NPC row: shows name + profession, and an "Ask" button when they have a
// quest to offer (the blurb is the tooltip). Idle NPCs show a dash.
function npcRow(npc) {
    // Anyone with anything at all gets the same plain "Ask" — the button never
    // hints at what they have, so tuition is found by asking, not by being told.
    const action = (npc.offer || npc.tuition)
        ? `<button class="btn-mini" data-ask="${npc.id}">Ask</button>`
        : `<span class="muted">Nothing for you.</span>`;
    return `<div class="location">
        <div class="loc-info">
            <span class="loc-name">${esc(npc.name)} <em>${esc(npc.profession)}</em></span>
        </div>
        <div class="loc-action">${action}</div>
    </div>`;
}

function questRow(q) {
    const done = q.state === 'done';
    const p = q.proof;
    const proofTxt = p ? ` · proof ${p.have}/${p.need} ${esc(p.item)}` : '';
    const action = done
        ? `<button class="btn-mini" data-turnin="${q.id}">Turn in (+${q.reward_gold}g, +${q.reward_rep} rep)</button>`
        : `<span class="loc-cleared">${q.progress}/${q.target_count}</span>`;
    return `<div class="location ${done ? 'cleared' : ''}">
        <div class="loc-info">
            <span class="loc-name">${esc(q.title)}</span>
            <span class="loc-next">Slay ${esc(q.target_race)} — ${q.progress}/${q.target_count}${done ? ' ✓' : ''}${proofTxt}</span>
        </div>
        <div class="loc-action">${action}</div>
    </div>`;
}

function renderVillage() {
    const v = state.village;
    if (v) {
        $('village-rep').textContent = `· reputation ${v.reputation}`;
        $('npc-list').innerHTML = (v.npcs || []).map(npcRow).join('');
    }
    const quests = state.quests || [];
    $('quest-list').innerHTML = quests.length
        ? quests.map(questRow).join('')
        : '<p class="muted">No quests. Ask around the village.</p>';
}

// Ask an NPC what they have: a quest, tuition, or both.
function openNpcDialog(npcId) {
    const npc = (state.village && state.village.npcs || []).find(n => n.id === npcId);
    if (!npc || (!npc.offer && !npc.tuition)) return;

    let body = '';
    let actions = '<button class="btn-ghost" data-modal-close>Leave</button>';

    if (npc.offer) {
        const paras = (npc.offer.dialog || npc.offer.blurb || '')
            .split('\n\n').map(p => `<p>${esc(p)}</p>`).join('');
        body += `<div class="dialog-text">${paras}</div>
                 <p class="muted">Quest: <b>${esc(npc.offer.title)}</b></p>`;
        actions += `<button class="btn" data-quest-accept="${npc.id}">Accept</button>`;
    }

    // Tuition is stated flatly, as a service with a price — no pitch about
    // what knowing a tongue might one day be good for.
    const t = npc.tuition;
    if (t) {
        body += `<div class="dialog-text"><p>They can teach you the ${esc(t.label.toLowerCase())}.
                 You speak it <b>${esc(t.fluency)}</b>.</p></div>`;
        body += t.can
            ? `<p class="muted">Lesson: <b>+${t.gain}</b> for <b>${t.price} gold</b>, ${clock(t.seconds)} of study.</p>`
            : `<p class="muted">${esc(t.reason)}</p>`;
        if (t.can) actions += `<button class="btn" data-learn="${npc.id}">Pay ${t.price}g</button>`;
    }

    openModal(`
        <h2>${esc(npc.name)} <span class="muted">— ${esc(npc.profession)}</span></h2>
        ${body}
        <div class="modal-actions">${actions}</div>`);
}

async function acceptQuest(npcId) {
    const { status } = await req('POST', '/quests/accept', { npc_id: npcId });
    if (status === 201 || status === 200) await Promise.all([loadVillage(), loadWorld()]);
}

// ── Reusable modal ───────────────────────────────────────────────────────────
function openModal(html) {
    $('modal-card').innerHTML = html;
    $('modal').classList.remove('hidden');
}
function closeModal() {
    $('modal').classList.add('hidden');
    $('modal-card').innerHTML = '';
}

async function turnInQuest(questId) {
    const { status, body } = await req('POST', '/quests/turn-in', { quest_id: questId });
    if (status === 200) {
        $('quest-msg').textContent = '';
        await Promise.all([loadVillage(), loadSettlements(), loadCharacter()]);  // ears consumed
    } else {
        $('quest-msg').textContent = (body && body.error) || 'Could not turn in.';
    }
}
