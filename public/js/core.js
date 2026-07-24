// Shared client state and the single API helper every request goes through.

const state = {
    playerId: null,
    username: null,
    token: null,
    settlements: [],
    current: null,   // the currently viewed settlement
    character: null,
    monsters: [],
    world: null,
    village: null,   // { npcs, reputation }
    quests: [],
    ticker: null,    // setInterval handle for the resource counter
};

function $(id) { return document.getElementById(id); }

// Escape for safe insertion into HTML text or double-quoted attributes.
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// One wrapper for all API calls: attaches the auth token, sends/parses JSON.
// Returns { status, body }.
async function req(method, path, body) {
    const opts = { method, headers: {} };
    if (state.token) opts.headers['Authorization'] = 'Bearer ' + state.token;
    if (body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }

    try {
        const res = await fetch('/api' + path, opts);
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch { data = text; }
        return { status: res.status, body: data };
    } catch {
        return { status: 0, body: { error: 'Network error.' } };
    }
}

// Project resources forward from their hourly rates, so on-screen counters
// tick up smoothly between server fetches (the client half of the model).
function liveResources(s) {
    const elapsedHours = (Date.now() - s.fetchedAt) / 3600000;
    const project = (current, rate, cap) => {
        let v = current + rate * elapsedHours;
        if (cap > 0) v = Math.min(cap, v);
        return Math.max(0, Math.floor(v));
    };
    return {
        gold:  project(s.gold,  s.rate_gold_per_hour,  s.capacity_gold),
        wood:  project(s.wood,  s.rate_wood_per_hour,  s.capacity_wood),
        stone: project(s.stone, s.rate_stone_per_hour, s.capacity_stone),
    };
}
