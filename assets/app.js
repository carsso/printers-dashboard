// Dashboard frontend.
// Loads the printer list once from api.php, then fires one fetch per printer
// to poll.php with a bounded AbortController timeout. Repeats every REFRESH_MS.

(function () {
    "use strict";

    const REFRESH_MS    = 60_000;
    const FETCH_TIMEOUT = 12_000; // slightly above POLL_TIMEOUT in .env

    const grid   = document.getElementById("grid");
    const empty  = document.getElementById("empty");
    const lastEl = document.getElementById("last-refresh");

    let printers = [];
    const snapshots = new Map(); // id -> snapshot
    const cards     = new Map(); // id -> <article> element

    init();

    async function init() {
        try {
            const res = await fetch("api.php", { cache: "no-store" });
            printers = (await res.json()).printers || [];
        } catch (err) {
            lastEl.textContent = "Cannot load printers: " + err.message;
            return;
        }

        empty.classList.toggle("hidden", printers.length > 0);
        buildGrid();
        pollAll();
        setInterval(pollAll, REFRESH_MS);
    }

    function buildGrid() {
        grid.innerHTML = "";
        cards.clear();
        for (const p of printers) {
            const el = document.createElement("article");
            el.className = "card";
            grid.appendChild(el);
            cards.set(p.id, el);
            renderCard(p);
        }
    }

    function pollAll() {
        Promise.allSettled(printers.map(pollOne)).then(() => {
            lastEl.textContent = "Updated " + new Date().toLocaleTimeString();
        });
    }

    async function pollOne(p) {
        const ctrl  = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT);
        const url   = "poll.php?id=" + encodeURIComponent(p.id);

        try {
            const res = await fetch(url, { signal: ctrl.signal, cache: "no-store" });
            if (!res.ok) throw new Error("HTTP " + res.status);
            snapshots.set(p.id, await res.json());
        } catch (err) {
            const prev = snapshots.get(p.id) || {};
            snapshots.set(p.id, {
                polled_at: Math.floor(Date.now() / 1000),
                online: false,
                markers: [],
                state_reasons: [],
                device_name: prev.device_name || null,
                error: err.name === "AbortError"
                    ? "request timed out (" + (FETCH_TIMEOUT / 1000) + "s)"
                    : err.message,
            });
        } finally {
            clearTimeout(timer);
            renderCard(p);
        }
    }

    function renderCard(p) {
        const el = cards.get(p.id);
        if (!el) return;
        const snap   = snapshots.get(p.id) || {};
        const status = badge(snap);
        el.innerHTML = `
            <div class="card-header">
                <div class="card-title">
                    <h2>${esc(p.name)}</h2>
                    ${snap.device_name ? `<div class="card-model">${esc(snap.device_name)}</div>` : ""}
                </div>
                <span class="badge ${status.cls}">${esc(status.label)}</span>
            </div>
            <div class="card-meta">
                <span>${esc(p.ip)}</span>
                <span class="sep">${lastSeen(snap.polled_at)}</span>
            </div>
            <div class="markers">${(snap.markers || []).map(marker).join("")}</div>
            ${reasons(snap.state_reasons)}
            ${snap.online === false && snap.error ? `<div class="error-line">Offline: ${esc(snap.error)}</div>` : ""}
        `;
    }

    function marker(m) {
        const pct = m.percent ?? null;
        const w   = pct === null ? 0 : Math.max(0, Math.min(100, pct));
        return `
            <div class="marker">
                <span class="marker-name">${esc(m.name)}</span>
                <span class="marker-pct">${pct === null ? "n/a" : pct + "%"}</span>
                <div class="marker-bar"><div class="marker-fill" style="width:${w}%;background:${esc(m.color || "#777")};"></div></div>
            </div>
        `;
    }

    function reasons(rs) {
        if (!rs || !rs.length) return "";
        return `<div class="reasons">${rs.map(r => `<span class="reason-chip">${esc(r)}</span>`).join("")}</div>`;
    }

    function badge(snap) {
        if (!snap.polled_at)        return { cls: "unknown", label: "no data" };
        if (snap.online === false)  return { cls: "offline", label: "offline" };
        const s = (snap.status || "unknown").toLowerCase();
        return { cls: s, label: s };
    }

    function lastSeen(ts) {
        if (!ts) return "never polled";
        const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (s < 60)   return s + "s ago";
        if (s < 3600) return Math.floor(s / 60) + "m ago";
        return Math.floor(s / 3600) + "h ago";
    }

    function esc(s) {
        return String(s ?? "")
            .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
            .replaceAll("\"", "&quot;").replaceAll("'", "&#39;");
    }
})();
