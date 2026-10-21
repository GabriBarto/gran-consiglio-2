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
  get session() { try { return JSON.parse(sessionStorage.getItem('vs-session')); } catch { return null; } },
  get next() { return sessionStorage.getItem('vs-next'); },
  set next(v) { v ? sessionStorage.setItem('vs-next', v) : sessionStorage.removeItem('vs-next'); },
  get pos() { try { return JSON.parse(sessionStorage.getItem('vs-pos')); } catch { return null; } },
  set pos(v) { v ? sessionStorage.setItem('vs-pos', JSON.stringify(v)) : sessionStorage.removeItem('vs-pos'); },
  set session(v) { v ? sessionStorage.setItem('vs-session', JSON.stringify(v)) : sessionStorage.removeItem('vs-session'); },
};

const posQuery = () => (store.pos ? `lat=${store.pos.lat}&lng=${store.pos.lng}` : '');
const km = (d) => (d == null ? '' : ` · 📏 ${d.toLocaleString('it-IT')} km`);
const isCustomer = () => store.session?.user?.role === 'cliente';

/** Porta alla registrazione ricordando dove tornare dopo l'accesso. */
function requireLogin(back) {
  store.next = back;
  toast('Registrati o accedi per acquistare: poi torni qui');
  location.hash = '#/account';
}

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
      <button class="secondary" id="near">📍 Vicino a me</button>
      <select id="cat"><option value="">Tutte le categorie</option>${filters.categories.map((c) => `<option value="${esc(c)}">${esc(CAT[c] || c)}</option>`).join('')}</select>
    </div>
    <div id="offers" class="grid"></div>
    <section class="how">
      <div><b>🔎</b><h3>1. Scopri</h3>Trova le sorprese verdi vicino a te.</div>
      <div><b>🛒</b><h3>2. Acquista</h3>Paghi online e ricevi un codice di ritiro.</div>
      <div><b>🌿</b><h3>3. Ritira</h3>Passa in negozio nella fascia oraria indicata.</div>
    </section>`;
  const load = async () => {
    const p = new URLSearchParams({ q: $('#q').value, city: $('#city').value, category: $('#cat').value });
    const offers = await api(`/api/offers?${p}&${posQuery()}`);
    if (!$('#offers')) return;   // l'utente ha già cambiato pagina
    $('#offers').innerHTML = offers.length ? offers.map(card).join('')
      : '<div class="empty" style="grid-column:1/-1">Nessuna offerta al momento 🌧️<br>Riprova più tardi!</div>';
  };
  $('#near').textContent = store.pos ? '📍 Vicino a me ✓' : '📍 Vicino a me';
  $('#near').onclick = () => {
    if (store.pos) { store.pos = null; $('#near').textContent = '📍 Vicino a me'; return load(); }
    if (!navigator.geolocation) return toast('Il browser non supporta la geolocalizzazione');
    navigator.geolocation.getCurrentPosition(
      (p) => { store.pos = { lat: p.coords.latitude, lng: p.coords.longitude }; $('#near').textContent = '📍 Vicino a me ✓'; load(); },
      () => toast('Posizione non disponibile: controlla i permessi del browser'));
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
      <div class="meta">🕒 Ritiro ${day(o.pickup_start)} ${hhmm(o.pickup_start)}–${hhmm(o.pickup_end)} · 📍 ${esc(o.address)}${km(o.distance_km)}</div>
      <div class="price-row"><span class="price">${eur(o.price_cents)}</span><span class="old">${eur(o.original_price_cents)}</span></div>
      <a class="btn" href="#/offerta/${o.id}">Vedi e acquista (${o.available} disponibili)</a>
    </div></article>`;
}

async function offerPage(id) {
  const o = await api(`/api/offers/${id}`);
  const expired = new Date(o.pickup_end) <= new Date();
  view.innerHTML = `<p><a href="#/">← Tutte le offerte</a></p>
    <div class="panel"><h1>${esc(o.emoji)} ${esc(o.title)}</h1>
      <p class="shop"><a href="#/negozio/${o.shop_id}">${esc(o.shop_emoji)} ${esc(o.shop_name)}</a> · ${esc(o.address)}, ${esc(o.city)}</p>
      <p>${esc(o.description)}</p>
      ${o.allergens.length ? `<p class="meta">⚠️ Allergeni: ${o.allergens.map(esc).join(', ')}</p>` : ''}
      <p class="meta">🕒 Ritiro ${day(o.pickup_start)} ${hhmm(o.pickup_start)}–${hhmm(o.pickup_end)}</p>
      <div class="price-row"><span class="price">${eur(o.price_cents)}</span><span class="old">${eur(o.original_price_cents)}</span></div>
      <p class="meta">Paghi ${eur(o.price_cents)} invece di ${eur(o.original_price_cents)}: risparmi ${eur(o.original_price_cents - o.price_cents)}.</p>
      ${expired || o.available < 1 ? '<p class="err">Non più disponibile.</p>' : `
        <label>Quantità (disponibili: ${o.available})</label><input id="o-qty" type="number" min="1" max="${Math.min(5, o.available)}" value="1" style="max-width:6rem">
        <p class="err" id="o-err"></p><button id="o-add">Aggiungi al carrello</button>`}
    </div>`;
  const add = $('#o-add');
  if (!add) return;
  add.onclick = async () => {
    if (!isCustomer()) {
      if (store.session) return ($('#o-err').textContent = 'Solo gli account cliente possono acquistare.');
      return requireLogin(`#/offerta/${id}`);
    }
    try {
      const qty = Number($('#o-qty').value);
      const cart = await api('/api/cart', { token: store.session.token });
      const have = cart.items.find((i) => i.box_id === o.id)?.qty ?? 0;
      await setCart(o.id, have + qty);
      toast('Aggiunto al carrello 🛒'); location.hash = '#/carrello';
    } catch (e) { $('#o-err').textContent = e.message; }
  };
}

/** Imposta la quantità nel carrello; se è di un altro negozio chiede se sostituirlo. */
async function setCart(boxId, qty) {
  const send = (replace) => api('/api/cart/items', { method: 'POST', token: store.session.token, body: { box_id: boxId, qty, replace } });
  try { return await send(false); }
  catch (e) {
    if (e.code !== 'other_shop') throw e;
    if (!confirm('Il carrello contiene box di un altro negozio. Vuoi svuotarlo e iniziare un nuovo carrello?')) throw new Error('Carrello non modificato');
    return send(true);
  }
}

async function shopsPage() {
  view.innerHTML = `<h1>Negozi</h1>
    <div class="filters"><input id="s-q" type="search" placeholder="Cerca per nome o città…">
      <select id="s-km"><option value="">Qualsiasi distanza</option><option value="5">Entro 5 km</option><option value="15">Entro 15 km</option><option value="30">Entro 30 km</option></select>
      <button class="secondary" id="s-near">📍 Vicino a me${store.pos ? ' ✓' : ''}</button></div>
    <div id="shops" class="grid"></div>`;
  const load = async () => {
    const p = new URLSearchParams({ q: $('#s-q').value });
    if (store.pos && $('#s-km').value) p.set('max_km', $('#s-km').value);
    const list = await api(`/api/shops?${p}&${posQuery()}`);
    if (!$('#shops')) return;
    $('#shops').innerHTML = list.length ? list.map((s) => `<article class="card"><div class="body">
      <h3>${esc(s.emoji)} ${esc(s.name)}</h3><div class="shop">${esc(s.address)}, ${esc(s.city)}${km(s.distance_km)}</div>
      <div class="desc">${esc(s.description)}</div>
      <div class="meta">${s.open_boxes} sorprese disponibili</div>
      <a class="btn" href="#/negozio/${s.id}">Vai al negozio</a></div></article>`).join('')
      : '<div class="empty" style="grid-column:1/-1">Nessun negozio trovato 🌧️</div>';
  };
  let t; $('#s-q').oninput = () => { clearTimeout(t); t = setTimeout(load, 200); };
  $('#s-km').onchange = load;
  $('#s-near').onclick = () => {
    if (!navigator.geolocation) return toast('Il browser non supporta la geolocalizzazione');
    navigator.geolocation.getCurrentPosition(
      (p) => { store.pos = { lat: p.coords.latitude, lng: p.coords.longitude }; shopsPage(); },
      () => toast('Posizione non disponibile: controlla i permessi del browser'));
  };
  load();
}

async function shopPage(id) {
  const s = await api(`/api/shops/${id}?${posQuery()}`);
  const hours = (a, b) => (a && b ? `${a}–${b}` : 'non indicato');
  view.innerHTML = `<p><a href="#/negozi">← Tutti i negozi</a></p>
    <div class="panel"><h1>${esc(s.emoji)} ${esc(s.name)}</h1><p>${esc(s.description)}</p>
      <p class="meta">📍 ${esc(s.address)}, ${esc(s.city)}${km(s.distance_km)}${s.phone ? ` · 📞 ${esc(s.phone)}` : ''}</p>
      <p class="meta">🕘 Orari di apertura: ${hours(s.opening_time, s.closing_time)} · 🕒 Ritiro: ${hours(s.pickup_start, s.pickup_end)}</p></div>
    <h2>Sorprese disponibili</h2>
    <div class="grid">${s.boxes.length ? s.boxes.map(card).join('') : '<div class="empty" style="grid-column:1/-1">Nessuna sorpresa al momento 🌧️</div>'}</div>`;
}

async function cartPage() {
  if (!isCustomer()) {
    if (!store.session) return requireLogin('#/carrello');
    view.innerHTML = '<h1>Carrello</h1><div class="empty">Solo gli account cliente hanno un carrello.</div>'; return;
  }
  const token = store.session.token;
  const cart = await api('/api/cart', { token });
  if (!cart.items.length) { view.innerHTML = '<h1>Carrello</h1><div class="empty">Il carrello è vuoto 🌱<br><a href="#/">Scopri le offerte</a></div>'; return; }
  const allOk = cart.items.every((i) => i.ok);
  view.innerHTML = `<h1>Carrello</h1><p class="shop">Ordine da <a href="#/negozio/${cart.shop.id}">${esc(cart.shop.name)}</a> · ${esc(cart.shop.address)}, ${esc(cart.shop.city)}</p>
    <div class="panel"><table><tr><th>Box</th><th>Quantità</th><th>Prezzo</th><th></th></tr>
      ${cart.items.map((i) => `<tr><td>${esc(i.emoji)} ${esc(i.title)}<br><span class="meta">Ritiro ${day(i.pickup_start)} ${hhmm(i.pickup_start)}–${hhmm(i.pickup_end)}</span>
        ${i.ok ? '' : '<br><span class="err">Non più disponibile nella quantità scelta</span>'}</td>
        <td><input type="number" min="1" max="${Math.max(1, Math.min(5, i.available))}" value="${i.qty}" data-qty="${i.box_id}" style="width:4.5rem"></td>
        <td>${eur(i.qty * i.price_cents)}</td><td><button class="link" data-rm="${i.box_id}">Rimuovi</button></td></tr>`).join('')}</table>
      <p><b>Totale: ${eur(cart.total_cents)}</b> <span class="meta">(risparmi ${eur(cart.savings_cents)})</span></p></div>
    <div class="panel" style="max-width:460px"><h2>Pagamento</h2>
      <p class="meta">Pagamento simulato: usa una carta di 16 cifre qualsiasi (es. 4242 4242 4242 4242).</p>
      <label>Numero di carta</label><input id="c-num" inputmode="numeric" autocomplete="cc-number" value="4242 4242 4242 4242">
      <label>Scadenza (MM/AA)</label><input id="c-exp" autocomplete="cc-exp" value="12/30" style="max-width:7rem">
      <p class="err" id="c-err"></p><button id="c-pay" ${allOk ? '' : 'disabled'}>Paga ${eur(cart.total_cents)}</button></div>`;
  document.querySelectorAll('[data-qty]').forEach((el) => (el.onchange = async () => {
    try { await setCart(Number(el.dataset.qty), Number(el.value)); } catch (e) { toast(e.message); }
    cartPage();
  }));
  document.querySelectorAll('[data-rm]').forEach((el) => (el.onclick = async () => { await setCart(Number(el.dataset.rm), 0); cartPage(); }));
  $('#c-pay').onclick = async () => {
    try {
      const { code } = await api('/api/checkout', { method: 'POST', token, body: { card: { number: $('#c-num').value, expiry: $('#c-exp').value } } });
      location.hash = `#/ordini/${code}`;
    } catch (e) { $('#c-err').textContent = e.message; }
  };
}

function orderCard(o) {
  return `<article class="card"><div class="body">
    <span class="tag ${o.status === 'collected' ? 'done' : ''}">${o.status === 'collected' ? 'Ritirato' : 'Pagato · da ritirare'}</span>
    <h3>${o.items.map((i) => `${esc(i.emoji)} ${i.qty}× ${esc(i.title)}`).join('<br>')}</h3>
    <div class="shop">${esc(o.shop_name)} · ${esc(o.address)}, ${esc(o.city)}</div>
    ${o.items.map((i) => `<div class="meta">🕒 ${day(i.pickup_start)} ${hhmm(i.pickup_start)}–${hhmm(i.pickup_end)}</div>`).join('')}
    <div class="meta">Totale ${eur(o.total_cents)}</div><div><span class="code">${esc(o.code)}</span></div></div></article>`;
}

async function ordersPage(code) {
  if (!isCustomer()) {
    if (!store.session) return requireLogin('#/ordini');
    view.innerHTML = '<h1>I miei ordini</h1><div class="empty">Solo gli account cliente hanno ordini.</div>'; return;
  }
  const token = store.session.token;
  if (code) {
    const o = await api(`/api/orders/${code}`, { token });
    view.innerHTML = `<h1>Ordine confermato 🎉</h1><p>Ti abbiamo inviato un'email di conferma con il riepilogo e le modalità di ritiro.
      Mostra il codice in negozio nella fascia oraria indicata.</p><div class="grid">${orderCard(o)}</div><p><a href="#/ordini">Tutti i miei ordini</a></p>`;
    return;
  }
  const list = await api('/api/orders', { token });
  view.innerHTML = `<h1>I miei ordini</h1><div class="grid">${list.length ? list.map(orderCard).join('') : '<div class="empty" style="grid-column:1/-1">Nessun ordine ancora 🌱</div>'}</div>`;
}

// ---------- Account (accesso, registrazione, area venditori) ----------
async function logout() {
  const s = store.session;
  store.session = null;
  if (s) await api('/api/auth/logout', { method: 'POST', token: s.token }).catch(() => {});
  account();
}

async function account() {
  const s = store.session;
  if (!s) return authView();
  let me;
  try { me = await api('/api/auth/me', { token: s.token }); } catch { store.session = null; return authView(); }
  store.session = { ...s, user: me.user, shop: me.shop };
  if (me.user.must_change_password) return changePasswordView(store.session);
  if (me.user.role === 'venditore') return dashboard(store.session);
  view.innerHTML = `<h1>Ciao ${esc(me.user.username)} 👋</h1>
    <div class="panel"><p>${me.user.role === 'admin'
      ? 'Sei collegato come <b>amministratore</b>. Il pannello di gestione (licenze e moderazione) arriva con la Fase 3.'
      : 'Sei collegato come <b>cliente</b>. Puoi scegliere le sorprese dalla pagina Scopri e pagarle dal carrello.'}</p>
      <a class="btn" href="#/ordini">I miei ordini</a> <button class="secondary" id="logout">Esci</button></div>`;
  $('#logout').onclick = logout;
}

function authView(mode = 'login') {
  const reg = mode === 'register';
  view.innerHTML = `<h1>${reg ? 'Crea il tuo account' : 'Accedi'}</h1>
    <p>Clienti e venditori: trasforma l'invenduto in ricavo, o salva una pianta a prezzo ridotto.</p>
    <div class="panel" style="max-width:460px">
      ${reg ? `<label style="margin-top:0">Sono un…</label>
        <select id="a-role"><option value="cliente">Cliente</option><option value="venditore">Venditore (vivaio, fiorista, garden)</option></select>
        <label>Nome utente</label><input id="a-user" autocomplete="username">` : ''}
      <label${reg ? '' : ' style="margin-top:0"'}>Email</label><input id="a-email" type="email" autocomplete="email">
      <label>Password</label><input id="a-pass" type="password" autocomplete="${reg ? 'new-password' : 'current-password'}" ${reg ? 'placeholder="Almeno 8 caratteri, lettere e numeri"' : ''}>
      ${reg ? '<label>Ripeti la password</label><input id="a-pass2" type="password" autocomplete="new-password">' : ''}
      <p class="err" id="a-err"></p>
      <button id="a-go">${reg ? 'Registrati' : 'Accedi'}</button>
      <p class="meta" style="margin-top:1rem">${reg ? 'Hai già un account?' : 'Non hai un account?'}
        <a href="#/account" id="a-switch">${reg ? 'Accedi' : 'Registrati'}</a></p>
      ${reg ? '' : '<p class="meta">Demo: <code>glicine@demo.it</code> / <code>demo1234</code> (venditore) · <code>cliente@demo.it</code> / <code>demo1234</code></p>'}
    </div>`;
  $('#a-switch').onclick = (e) => { e.preventDefault(); authView(reg ? 'login' : 'register'); };
  $('#a-go').onclick = async () => {
    try {
      const body = { email: $('#a-email').value, password: $('#a-pass').value };
      if (reg) Object.assign(body, { role: $('#a-role').value, username: $('#a-user').value, password_confirm: $('#a-pass2').value });
      store.session = await api(reg ? '/api/auth/register' : '/api/auth/login', { method: 'POST', body });
      const next = store.next;
      store.next = null;
      if (next && store.session.user.role === 'cliente') { location.hash = next; return; }   // riporta alla box che stava guardando
      account();
    } catch (e) { $('#a-err').textContent = e.message; }
  };
}

function changePasswordView(s) {
  view.innerHTML = `<h1>Scegli una nuova password</h1><p>Per sicurezza devi cambiare la password iniziale prima di continuare.</p>
    <div class="panel" style="max-width:460px">
      <label style="margin-top:0">Password attuale</label><input id="p0" type="password">
      <label>Nuova password</label><input id="p1" type="password" placeholder="Almeno 8 caratteri, lettere e numeri">
      <label>Ripeti la nuova password</label><input id="p2" type="password">
      <p class="err" id="p-err"></p><button id="p-go">Cambia password</button></div>`;
  $('#p-go').onclick = async () => {
    try {
      await api('/api/auth/change-password', { method: 'POST', token: s.token, body: { current_password: $('#p0').value, new_password: $('#p1').value, new_password_confirm: $('#p2').value } });
      toast('Password aggiornata'); account();
    } catch (e) { $('#p-err').textContent = e.message; }
  };
}

async function dashboard(s) {
  let d;
  try { d = await api('/api/seller/dashboard', { token: s.token }); }
  catch (e) {
    view.innerHTML = `<h1>Area venditori</h1><div class="panel"><p>${esc(e.message)}.</p>
      <p class="meta">La registrazione del negozio (dati, orari e partita IVA) arriva con la Fase 2.</p><button class="secondary" id="logout">Esci</button></div>`;
    $('#logout').onclick = logout; return;
  }
  view.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
      <h1>${esc(d.shop.emoji)} ${esc(d.shop.name)}</h1><button class="secondary" id="logout">Esci</button></div>
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
  $('#logout').onclick = logout;
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
const routes = {
  '': home, negozi: shopsPage, negozio: shopPage, offerta: offerPage, carrello: cartPage, ordini: ordersPage,
  prenotazioni: ordersPage, account, venditori: account,
};
async function router() {
  const [key = '', arg] = location.hash.replace(/^#\/?/, '').split('/');
  document.querySelectorAll('nav a').forEach((a) => a.classList.toggle('active', a.dataset.nav === ({ negozio: 'negozi', offerta: 'home' }[key] ?? (key || 'home'))));
  try { await (routes[key] || home)(arg); } catch (e) { view.innerHTML = `<div class="empty">Qualcosa è andato storto: ${esc(e.message)}</div>`; }
  window.scrollTo(0, 0);
}
addEventListener('hashchange', router);
router();

api('/api/config').then((c) => {
  if (!c.demo) return;
  const b = $('#reset-demo'); b.hidden = false;
  b.onclick = async () => { await api('/api/demo/reset', { method: 'POST' }); localStorage.clear(); store.session = null; toast('Dati demo ripristinati'); router(); };
});
