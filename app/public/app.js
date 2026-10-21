const $ = (s) => document.querySelector(s);
const view = $('#view');

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const eur = (cents) => (cents / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
const hhmm = (iso) => new Date(iso).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
const day = (iso) => new Date(iso).toDateString() === new Date().toDateString() ? 'Oggi' : new Date(iso).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' });
const CAT = { piante: 'Piante', fiori: 'Fiori', erbe: 'Erbe e orto', 'piante-da-frutto': 'Piante da frutto', succulente: 'Succulente' };

async function api(path, { method = 'GET', body, token } = {}) {
  const res = await fetch(path, {
    method,
    headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Errore');
  return data;
}

function toast(msg) {
  const t = $('#toast'); t.textContent = msg; t.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), 3200);
}
function modal(html) {
  const m = $('#modal');
  m.innerHTML = `<div class="box">${html}</div>`; m.hidden = false;
  m.onclick = (e) => { if (e.target === m) closeModal(); };
}
const closeModal = () => ($('#modal').hidden = true);

// ---------- storage ----------
const store = {
  get codes() { try { return JSON.parse(localStorage.getItem('vs-codes') || '[]'); } catch { return []; } },
  addCode(c) { localStorage.setItem('vs-codes', JSON.stringify([c, ...this.codes])); },
  get seller() { try { return JSON.parse(sessionStorage.getItem('vs-seller')); } catch { return null; } },
  set seller(v) { v ? sessionStorage.setItem('vs-seller', JSON.stringify(v)) : sessionStorage.removeItem('vs-seller'); },
};

// ---------- Scopri ----------
async function home() {
  const [stats, filters] = await Promise.all([api('/api/stats'), api('/api/filters')]);
  view.innerHTML = `
    <section class="hero">
      <h1>Ogni pianta merita una seconda possibilità</h1>
      <p>Vivai e fioristi mettono in vendita a prezzo ridotto le piante e i fiori che a fine giornata rischierebbero di essere buttati. Tu risparmi, loro non sprecano.</p>
      <div class="stats">
        <div class="stat"><b>${stats.plantsSaved}</b><span>piante salvate</span></div>
        <div class="stat"><b>${stats.shops}</b><span>negozi partner</span></div>
        <div class="stat"><b>${eur(stats.moneySavedCents)}</b><span>risparmiati dai clienti</span></div>
      </div>
    </section>
    <div class="filters">
      <input id="q" type="search" placeholder="Cerca: lavanda, orchidea, bouquet…">
      <select id="city"><option value="">Tutte le città</option>${filters.cities.map((c) => `<option>${esc(c)}</option>`).join('')}</select>
      <select id="cat"><option value="">Tutte le categorie</option>${filters.categories.map((c) => `<option value="${esc(c)}">${esc(CAT[c] || c)}</option>`).join('')}</select>
    </div>
    <div id="offers" class="grid"></div>
    <section class="how">
      <div><b>🔎</b><h3>1. Scopri</h3>Trova le sorprese verdi vicino a te.</div>
      <div><b>🛒</b><h3>2. Prenota</h3>Paghi poco, ricevi un codice di ritiro.</div>
      <div><b>🌿</b><h3>3. Ritira</h3>Passa in negozio nella fascia oraria indicata.</div>
    </section>`;
  const load = async () => {
    const p = new URLSearchParams({ q: $('#q').value, city: $('#city').value, category: $('#cat').value });
    const offers = await api(`/api/offers?${p}`);
    $('#offers').innerHTML = offers.length ? offers.map(card).join('')
      : '<div class="empty" style="grid-column:1/-1">Nessuna offerta al momento 🌧️<br>Riprova più tardi!</div>';
    document.querySelectorAll('[data-reserve]').forEach((b) => (b.onclick = () => reserveModal(offers.find((o) => o.id == b.dataset.reserve), load)));
  };
  let t; $('#q').oninput = () => { clearTimeout(t); t = setTimeout(load, 200); };
  $('#city').onchange = $('#cat').onchange = load;
  load();
}

function card(o) {
  const off = Math.round((1 - o.price_cents / o.original_price_cents) * 100);
  return `<article class="card">
    <div class="pic">${esc(o.emoji)}<span class="badge${o.available <= 2 ? ' low' : ''}">${o.available <= 2 ? `Ultime ${o.available}!` : `-${off}%`}</span></div>
    <div class="body">
      <div class="shop">${esc(o.shop_emoji)} ${esc(o.shop_name)} · ${esc(o.city)}</div>
      <h3>${esc(o.title)}</h3>
      <div class="desc">${esc(o.description)}</div>
      <div class="meta">🕒 Ritiro ${day(o.pickup_start)} ${hhmm(o.pickup_start)}–${hhmm(o.pickup_end)} · 📍 ${esc(o.address)}</div>
      <div class="price-row"><span class="price">${eur(o.price_cents)}</span><span class="old">${eur(o.original_price_cents)}</span></div>
      <button data-reserve="${o.id}">Prenota (${o.available} disponibili)</button>
    </div></article>`;
}

function reserveModal(o, reload) {
  modal(`<h2>${esc(o.emoji)} ${esc(o.title)}</h2>
    <p class="shop">${esc(o.shop_name)} · ${esc(o.address)}, ${esc(o.city)}</p>
    <p class="meta">Paghi in negozio al ritiro: ${eur(o.price_cents)} a sorpresa.</p>
    <label>Nome</label><input id="r-name" autocomplete="name">
    <label>Email</label><input id="r-email" type="email" autocomplete="email">
    <label>Quantità</label><input id="r-qty" type="number" min="1" max="${Math.min(5, o.available)}" value="1">
    <p class="err" id="r-err"></p>
    <div style="display:flex;gap:.6rem"><button id="r-ok">Conferma prenotazione</button><button class="secondary" id="r-no">Annulla</button></div>`);
  $('#r-no').onclick = closeModal;
  $('#r-ok').onclick = async () => {
    try {
      const { code } = await api('/api/reservations', { method: 'POST', body: { offer_id: o.id, name: $('#r-name').value, email: $('#r-email').value, qty: $('#r-qty').value } });
      store.addCode(code);
      modal(`<h2>Prenotazione confermata 🎉</h2><p>Mostra questo codice in negozio:</p><p style="text-align:center"><span class="code">${esc(code)}</span></p>
        <p class="meta">Lo trovi anche in “Le mie prenotazioni”.</p><button id="ok">Fantastico</button>`);
      $('#ok').onclick = () => { closeModal(); reload(); };
    } catch (e) { $('#r-err').textContent = e.message; }
  };
}

// ---------- Prenotazioni ----------
async function bookings() {
  view.innerHTML = `<h1>Le mie prenotazioni</h1>
    <div class="panel"><label style="margin-top:0">Hai un codice? Cercalo</label>
      <div style="display:flex;gap:.6rem"><input id="c" placeholder="VS-XXXXXX" style="flex:1"><button id="go">Cerca</button></div><p class="err" id="e"></p></div>
    <div id="list" class="grid"></div>`;
  const render = async (codes) => {
    const items = (await Promise.all(codes.map((c) => api(`/api/reservations/${c}`).catch(() => null)))).filter(Boolean);
    $('#list').innerHTML = items.length ? items.map((r) => `<article class="card"><div class="body">
      <span class="tag ${r.status === 'collected' ? 'done' : ''}">${r.status === 'collected' ? 'Ritirata' : 'Da ritirare'}</span>
      <h3>${esc(r.emoji)} ${esc(r.title)} × ${r.qty}</h3>
      <div class="shop">${esc(r.shop_name)} · ${esc(r.address)}, ${esc(r.city)}</div>
      <div class="meta">🕒 ${day(r.pickup_start)} ${hhmm(r.pickup_start)}–${hhmm(r.pickup_end)}</div>
      <div><span class="code">${esc(r.code)}</span></div></div></article>`).join('')
      : '<div class="empty" style="grid-column:1/-1">Nessuna prenotazione ancora 🌱</div>';
  };
  render(store.codes);
  $('#go').onclick = async () => {
    const c = $('#c').value.trim().toUpperCase();
    try { await api(`/api/reservations/${c}`); $('#e').textContent = ''; render([c]); } catch (e) { $('#e').textContent = e.message; }
  };
}

// ---------- Venditori ----------
async function sellers() {
  const s = store.seller;
  if (s) return dashboard(s);
  const shops = await api('/api/shops');
  view.innerHTML = `<h1>Area venditori</h1>
    <p>Trasforma l'invenduto in ricavo. Pubblica una sorpresa verde in meno di un minuto.</p>
    <div class="panel" style="max-width:460px">
      <label style="margin-top:0">Il tuo negozio</label>
      <select id="shop">${shops.map((x) => `<option value="${x.id}">${esc(x.emoji)} ${esc(x.name)} (${esc(x.city)})</option>`).join('')}</select>
      <label>PIN</label><input id="pin" type="password" inputmode="numeric" placeholder="Demo: 1111, 2222, 3333…">
      <p class="err" id="e"></p><button id="login">Accedi</button></div>`;
  $('#login').onclick = async () => {
    try {
      const r = await api('/api/seller/login', { method: 'POST', body: { shop_id: $('#shop').value, pin: $('#pin').value } });
      store.seller = r; sellers();
    } catch (e) { $('#e').textContent = e.message; }
  };
}

async function dashboard(s) {
  let d;
  try { d = await api('/api/seller/dashboard', { token: s.token }); } catch { store.seller = null; return sellers(); }
  view.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
      <h1>${esc(s.shop.emoji)} ${esc(s.shop.name)}</h1><button class="secondary" id="logout">Esci</button></div>
    <div class="stats" style="margin:.5rem 0 1.2rem">
      <div class="panel stat" style="color:var(--ink);background:#fff;margin:0"><b>${d.plantsSaved}</b><span>piante salvate</span></div>
      <div class="panel stat" style="color:var(--ink);background:#fff;margin:0"><b>${eur(d.revenueCents)}</b><span>ricavi recuperati</span></div>
    </div>
    <div class="cols">
      <div class="panel"><h2>Nuova sorpresa verde</h2>
        <label style="margin-top:0">Titolo</label><input id="n-title" placeholder="Es. Sorpresa Aromatiche">
        <label>Descrizione</label><textarea id="n-desc" rows="2"></textarea>
        <label>Categoria</label><select id="n-cat">${Object.entries(CAT).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
          <div><label>Prezzo pieno €</label><input id="n-full" type="number" step="0.5" min="1"></div>
          <div><label>Prezzo salvataggio €</label><input id="n-price" type="number" step="0.5" min="1"></div>
          <div><label>Quantità</label><input id="n-qty" type="number" min="1" value="5"></div>
          <div><label>Ritiro entro (ore)</label><input id="n-hours" type="number" min="1" max="72" value="4"></div>
        </div>
        <p class="err" id="n-err"></p><button id="n-ok">Pubblica</button></div>
      <div>
        <div class="panel"><h2>Conferma ritiro</h2><p class="meta">Il cliente mostra il suo codice.</p>
          <div style="display:flex;gap:.6rem"><input id="rd" placeholder="VS-XXXXXX" style="flex:1"><button id="rd-ok">Conferma</button></div><p class="err" id="rd-err"></p></div>
        <div class="panel"><h2>Prenotazioni recenti</h2>
          ${d.reservations.length ? `<table><tr><th>Codice</th><th>Cliente</th><th>Stato</th></tr>${d.reservations.map((r) =>
            `<tr><td>${esc(r.code)}</td><td>${esc(r.name)} · ${r.qty}× ${esc(r.title)}</td><td><span class="tag ${r.status === 'collected' ? 'done' : ''}">${r.status === 'collected' ? 'Ritirata' : 'In attesa'}</span></td></tr>`).join('')}</table>`
            : '<p class="meta">Nessuna prenotazione.</p>'}</div>
      </div>
    </div>
    <div class="panel"><h2>Le tue offerte</h2><table><tr><th>Offerta</th><th>Prezzo</th><th>Prenotate</th><th>Ritiro fino alle</th></tr>
      ${d.offers.map((o) => `<tr><td>${esc(o.emoji)} ${esc(o.title)}</td><td>${eur(o.price_cents)}</td><td>${o.reserved}/${o.quantity}</td><td>${day(o.pickup_end)} ${hhmm(o.pickup_end)}</td></tr>`).join('')}</table></div>`;
  $('#logout').onclick = () => { store.seller = null; sellers(); };
  $('#n-ok').onclick = async () => {
    try {
      await api('/api/seller/offers', { method: 'POST', token: s.token, body: {
        title: $('#n-title').value, description: $('#n-desc').value, category: $('#n-cat').value,
        original_price: $('#n-full').value, price: $('#n-price').value, quantity: $('#n-qty').value, pickup_hours: $('#n-hours').value } });
      toast('Offerta pubblicata! 🌱'); dashboard(s);
    } catch (e) { $('#n-err').textContent = e.message; }
  };
  $('#rd-ok').onclick = async () => {
    try {
      const r = await api('/api/seller/redeem', { method: 'POST', token: s.token, body: { code: $('#rd').value } });
      toast(`Ritiro confermato: ${r.name} (${r.qty}× ${r.title})`); dashboard(s);
    } catch (e) { $('#rd-err').textContent = e.message; }
  };
}

// ---------- router ----------
const routes = { '': home, prenotazioni: bookings, venditori: sellers };
async function router() {
  const key = location.hash.replace(/^#\/?/, '');
  document.querySelectorAll('nav a').forEach((a) => a.classList.toggle('active', a.dataset.nav === (key || 'home')));
  try { await (routes[key] || home)(); } catch (e) { view.innerHTML = `<div class="empty">Qualcosa è andato storto: ${esc(e.message)}</div>`; }
  window.scrollTo(0, 0);
}
addEventListener('hashchange', router);
router();

api('/api/config').then((c) => {
  if (!c.demo) return;
  const b = $('#reset-demo'); b.hidden = false;
  b.onclick = async () => { await api('/api/demo/reset', { method: 'POST' }); localStorage.clear(); store.seller = null; toast('Dati demo ripristinati'); router(); };
});
