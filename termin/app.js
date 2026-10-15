/* Online-Buchung für Friseur Andrea Zechmeister.
   Die freien Zeiten und das Buchen laufen über api.php (Google Kalender des Salons).
   Preise und Dauer werden bewusst nicht angezeigt. */
(function () {
  'use strict';

  /* ---------- Hilfsfunktionen ---------- */
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const DOW = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  const DOW_LONG = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
  const MON = ['Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
  const pad = (n) => String(n).padStart(2, '0');
  const fromKey = (k) => { const p = k.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
  const keyOf = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
  const longDate = (d) => DOW_LONG[d.getDay()] + ', ' + d.getDate() + '. ' + MON[d.getMonth()];
  const shortDate = (d) => DOW[d.getDay()] + ', ' + d.getDate() + '. ' + MON[d.getMonth()];

  const ICONS = {
    pin: '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    cash: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5"/>',
    user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3 2.8-4.8 5.5-4.8s4.9 1.8 5.5 4.8"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.4c2.4.2 4.1 1.8 4.6 4.1"/>',
    cal: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    back: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    left: '<path d="M15 6l-6 6 6 6"/>',
    right: '<path d="M9 6l6 6-6 6"/>',
    store: '<path d="M4 10v10h16V10"/><path d="M3 10l2-6h14l2 6"/><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M10 20v-5h4v5"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6 8.5-6"/>',
  };
  const ic = (name, size, sw) => '<svg width="' + (size || 20) + '" height="' + (size || 20) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 1.8) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';

  /* ---------- Zustand ---------- */
  let D = null; // Stammdaten vom Server
  const U = {
    leistung: null, person: 'any', woche: 0, tag: null, zeit: null, tage: null, laedt: false, fehler: '',
    form: { name: '', mail: '', tel: '', agb: false }, felder: {}, sendet: false, gebucht: null,
    storno: null, // { token, termin, fehler, fertig }
  };

  /* ---------- Server ---------- */
  async function api(a, params, body) {
    const q = new URLSearchParams(Object.assign({ a }, params || {}));
    const res = await fetch('api.php?' + q.toString(), body ? {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    } : {});
    let data = {};
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (!res.ok) { const err = new Error(data.fehler || 'Da ist etwas schiefgegangen.'); err.status = res.status; err.data = data; throw err; }
    return data;
  }

  const telLinks = () => D.telefon.map(([label, tel]) => '<a href="tel:' + esc(tel) + '">' + esc(label) + '</a>').join(' oder ');
  const leistung = (id) => D.leistungen.find((l) => l.id === id);
  const personName = (id) => (D.team.find((p) => p.id === id) || { name: '' }).name;
  const head = () => `<header class="topbar"><div class="brand"><span class="mono" aria-hidden="true">AZ</span><span class="brand-name">Friseur Andrea Zechmeister</span></div></header>`;
  const backHead = (href, title, label) => `<header class="topbar back">
  <a class="icon-btn" href="${href}" aria-label="${label}">${ic('back', 22, 2)}</a>
  <h1 class="topbar-title">${title}</h1>
</header>`;

  /* ---------- Ansichten ---------- */
  function viewStart() {
    if (D.pausiert) {
      return `${head()}
<div class="hero"><h1><img class="logo" src="logo.jpg" alt="Friseur Andrea Zechmeister" width="1600" height="653"></h1></div>
<div class="done"><h1>Online-Buchung gerade pausiert</h1><p>Im Moment kannst du online keinen Termin buchen. Ruf uns gerne an, wir finden einen Termin für dich: ${telLinks()}</p></div>
<div class="after"><a class="btn-soft" href="../">Zur Homepage</a></div>`;
    }
    const gruppen = [];
    D.leistungen.forEach((l) => {
      let g = gruppen.find((x) => x.name === l.gruppe);
      if (!g) gruppen.push(g = { name: l.gruppe, list: [] });
      g.list.push(l);
    });
    const html = gruppen.map((g) => `<h3 class="label" style="margin:22px 0 4px">${esc(g.name)}</h3>
      <ul class="svc-list">${g.list.map((l) => `<li class="svc">
        <div class="svc-text"><span class="svc-name">${esc(l.name)}</span></div>
        <button type="button" class="btn-outline" data-act="leistung" data-arg="${esc(l.id)}" aria-label="${esc(l.voll)} auswählen">Auswählen</button>
      </li>`).join('')}</ul>`).join('');
    return `${head()}
<div class="hero"><h1><img class="logo" src="logo.jpg" alt="Friseur Andrea Zechmeister – Termin online buchen" width="1600" height="653"></h1></div>
<section class="info">
  <p class="row">${ic('pin', 18)}<span>August-Jaksch-Straße 1, 9020 Klagenfurt</span></p>
  <p class="row">${ic('phone', 18)}<span>${telLinks()}</span></p>
  <div class="chips">
    <span class="chip">${ic('cash', 16)}Bezahlung im Salon</span>
    <span class="chip">${ic('user', 16)}Ohne Konto buchbar</span>
  </div>
</section>
<section class="services" aria-labelledby="h-leistungen">
  <h2 id="h-leistungen">Welche Leistung möchtest du buchen?</h2>
  ${html}
</section>`;
  }

  function viewTermin() {
    const l = leistung(U.leistung);
    if (!l) { location.replace('#/'); return ''; }
    const people = [{ id: 'any', name: 'Beliebig' }].concat(D.team);
    const staffHtml = people.map((p) => {
      const av = p.id === 'any' ? `<span class="avatar">${ic('users', 22)}</span>` : `<span class="avatar">${esc(p.name[0])}</span>`;
      return `<button type="button" class="person" data-act="person" data-arg="${esc(p.id)}" aria-pressed="${U.person === p.id}">${av}${esc(p.name)}</button>`;
    }).join('');
    const heute = fromKey(D.heute);
    const letzte = fromKey(D.letzterTag);
    const erster = addDays(heute, U.woche * 7);
    const naechsteMoeglich = addDays(erster, 7) <= letzte;
    let daysHtml = '', slotsHtml = '', monat = '';
    if (U.tage) {
      if (!U.tage.some((t) => t.tag === U.tag)) {
        const pick = U.tage.find((t) => t.zeiten.length) || U.tage.find((t) => !t.zu) || U.tage[0];
        U.tag = pick.tag;
      }
      const sel = U.tage.find((t) => t.tag === U.tag);
      daysHtml = U.tage.map((t) => {
        const d = fromKey(t.tag);
        const cls = t.zu ? '' : t.zeiten.length ? 'has' : 'full';
        const aria = longDate(d) + (t.zu ? ', ' + (t.hinweis ? t.hinweis + ', ' : '') + 'geschlossen' : t.zeiten.length ? ', ' + t.zeiten.length + ' freie Termine' : ', ausgebucht');
        return `<button type="button" class="day ${cls}" data-act="tag" data-arg="${t.tag}" aria-pressed="${t.tag === U.tag}" aria-label="${esc(aria)}" title="${esc(aria)}"${t.zu ? ' disabled' : ''}>
          <span class="dow">${DOW[d.getDay()]}</span><span class="num">${d.getDate()}</span><span class="dot"></span></button>`;
      }).join('');
      const a = fromKey(U.tage[0].tag), b = fromKey(U.tage[6].tag);
      monat = (a.getMonth() === b.getMonth() ? MON[a.getMonth()] : MON[a.getMonth()] + ' / ' + MON[b.getMonth()]) + ' ' + b.getFullYear();
      const sd = fromKey(sel.tag);
      const groups = [['Vormittag', 0, 12], ['Nachmittag', 12, 17], ['Abend', 17, 24]].map(([label, von, bis]) => {
        const list = sel.zeiten.filter((z) => { const h = Number(z.uhr.slice(0, 2)); return h >= von && h < bis; });
        if (!list.length) return '';
        return `<div class="slot-group"><h3>${label} <span>· ${list.length} frei</span></h3><div class="slot-grid">${list.map((z) =>
          `<button type="button" class="slot" data-act="zeit" data-arg="${z.ts}" aria-label="${z.uhr} Uhr, ${esc(longDate(sd))}">${z.uhr}</button>`).join('')}</div></div>`;
      }).join('');
      if (!sel.zeiten.length) {
        const wer = U.person === 'any' ? '' : ' bei ' + esc(personName(U.person));
        const grund = sel.zu ? `Am ${esc(longDate(sd))} ist geschlossen${sel.hinweis ? ' (' + esc(sel.hinweis) + ')' : ''}.` : `Am ${esc(longDate(sd))}${wer} ist leider nichts mehr frei.`;
        const anderer = U.tage.find((t) => t.zeiten.length);
        slotsHtml = `<div class="empty"><span>${grund}</span>${anderer
          ? `<button type="button" class="btn-primary sm" data-act="tag" data-arg="${anderer.tag}">${esc(shortDate(fromKey(anderer.tag)))} hat noch freie Zeiten</button>`
          : naechsteMoeglich ? `<button type="button" class="btn-primary sm" data-act="woche" data-arg="1">Nächste Woche ansehen</button>` : ''}</div>`;
      } else slotsHtml = groups;
    } else if (U.fehler) {
      slotsHtml = `<div class="empty" role="alert"><span>${esc(U.fehler)}</span><button type="button" class="btn-primary sm" data-act="neu-laden">Nochmal versuchen</button></div>`;
    } else {
      slotsHtml = '<div class="empty" aria-live="polite"><span>Freie Zeiten werden geladen …</span></div>';
    }
    return `${backHead('#/', 'Termin wählen', 'Zurück zu den Leistungen')}
<div class="pad">
  <div class="sumcard">
    <div><b>${esc(l.voll)}</b><span class="muted">Bezahlung im Salon</span></div>
    <div style="align-items:flex-end"><a href="#/">ändern</a></div>
  </div>
</div>
<div class="pad">
  <h2 class="label">Bei wem?</h2>
  <div class="staff-grid" style="grid-template-columns:repeat(${people.length},minmax(0,1fr))">${staffHtml}</div>
</div>
<div class="weekhead">
  <span class="month">${esc(monat)}</span>
  <div>
    <button type="button" class="icon-btn" data-act="woche" data-arg="-1" aria-label="Vorherige Woche"${U.woche <= 0 ? ' disabled' : ''}>${ic('left', 20, 2)}</button>
    <button type="button" class="icon-btn" data-act="woche" data-arg="1" aria-label="Nächste Woche"${naechsteMoeglich ? '' : ' disabled'}>${ic('right', 20, 2)}</button>
  </div>
</div>
<div class="week">${daysHtml}</div>
<div class="slots">${slotsHtml}</div>`;
  }

  function viewBuchen() {
    const l = leistung(U.leistung);
    if (!l || !U.zeit) { location.replace(l ? '#/termin' : '#/'); return ''; }
    const d = fromKey(U.zeit.tag);
    const wer = U.person !== 'any' ? 'Bei ' + personName(U.person)
      : U.zeit.wer.length === 1 ? 'Bei ' + personName(U.zeit.wer[0]) : 'Beliebig – wir teilen dich zu';
    const E = U.felder, F = U.form;
    const field = (name, label, type, ac, ph, hint) => `<div class="field">
      <label for="f-${name}">${label}${hint ? ' <span class="muted" style="font-weight:500">' + hint + '</span>' : ''}</label>
      <input id="f-${name}" name="${name}" type="${type}" autocomplete="${ac}" placeholder="${ph}" maxlength="${name === 'tel' ? 25 : name === 'mail' ? 120 : 80}" value="${esc(F[name])}"${E[name] ? ` aria-invalid="true" aria-describedby="e-${name}"` : ''}>
      ${E[name] ? `<span class="err" id="e-${name}">${esc(E[name])}</span>` : ''}
    </div>`;
    return `${backHead('#/termin', 'Buchung abschließen', 'Zurück zur Terminauswahl')}
<form class="pad stack" data-submit="buchen" novalidate style="flex:1">
  <section class="card summary" aria-label="Dein Termin">
    <p class="row">${ic('store', 18)}Friseur Andrea Zechmeister</p>
    <div class="when">
      <span class="big">${esc(U.zeit.uhr)}</span><span class="vline" aria-hidden="true"></span>
      <div><b>${esc(longDate(d))}</b><span class="muted small">${esc(wer)}</span></div>
      <a class="icon-btn" href="#/termin" aria-label="Termin ändern">${ic('edit')}</a>
    </div>
    <div class="sumline"><div><b>${esc(l.voll)}</b></div></div>
  </section>
  <section class="fields">
    <h2>Deine Daten</h2>
    ${field('name', 'Vor- und Nachname', 'text', 'name', 'Vor- und Nachname')}
    ${field('mail', 'E-Mail', 'email', 'email', 'name@beispiel.at', '(für die Bestätigung)')}
    ${field('tel', 'Telefonnummer', 'tel', 'tel', '+43 …', '(für Rückfragen)')}
    <div class="field" style="position:absolute;left:-9999px" aria-hidden="true"><label for="f-website">Website</label><input id="f-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
  </section>
  <section class="paysec">
    <h2>Bezahlung</h2>
    <div class="payopt"><span class="radio" aria-hidden="true"></span><div><b>Im Salon bezahlen</b><span>Bar oder mit Karte. Keine Vorauszahlung.</span></div></div>
  </section>
  <div>
    <label class="consent"><input type="checkbox" name="agb" aria-labelledby="t-agb"${F.agb ? ' checked' : ''}${E.agb ? ' aria-invalid="true" aria-describedby="e-agb"' : ''}>
      <span id="t-agb">Ich buche verbindlich. Kostenlos absagen kann ich bis ${D.storno} Stunden vor dem Termin über den Link in der Bestätigungs-E-Mail, danach nur telefonisch. Die <a href="../datenschutz.html" target="_blank" rel="noopener">Datenschutzerklärung</a> habe ich gelesen.</span></label>
    ${E.agb ? `<span class="err" id="e-agb">${esc(E.agb)}</span>` : ''}
  </div>
  ${U.fehler ? `<p class="err" role="alert">${esc(U.fehler)}</p>` : ''}
  <div class="paybar">
    <div><span class="muted small">Bezahlung im Salon</span></div>
    <button type="submit" class="btn-primary"${U.sendet ? ' disabled aria-busy="true"' : ''}>${U.sendet ? 'Wird gebucht …' : 'Verbindlich buchen'}</button>
  </div>
</form>`;
  }

  function terminDetails(t) {
    return `<dl class="card details">
  <dt>Wann</dt><dd><b>${esc(longDate(fromKey(t.tag)))}</b><br>${esc(t.uhr)} Uhr</dd>
  <dt>Was</dt><dd>${esc(t.leistung)}</dd>
  <dt>Bei</dt><dd>${esc(t.person)}</dd>
  <dt>Wo</dt><dd>Friseur Andrea Zechmeister<br>August-Jaksch-Straße 1, 9020 Klagenfurt</dd>
  <dt>Bezahlung</dt><dd>im Salon, bar oder mit Karte</dd>
</dl>`;
  }

  function viewBestaetigt() {
    const t = U.gebucht;
    if (!t) { location.replace('#/'); return ''; }
    return `${head()}
<div class="done">
  <span class="done-icon">${ic('check', 36, 2.2)}</span>
  <h1>Termin gebucht!</h1>
  <p>Wir haben dir eine Bestätigung an <b>${esc(t.mail)}</b> geschickt. Darin findest du auch den Link, falls du absagen musst.</p>
</div>
${terminDetails(t)}
<div class="after">
  <p class="muted center small">Keine E-Mail bekommen? Schau bitte im Spam-Ordner nach oder ruf uns an: ${telLinks()}</p>
  <a class="btn-soft" href="../">Zur Homepage</a>
</div>`;
  }

  function viewStorno() {
    const S = U.storno;
    if (S.fertig) {
      return `${head()}
<div class="done">
  <span class="done-icon">${ic('check', 36, 2.2)}</span>
  <h1>Termin abgesagt</h1>
  <p>Dein Termin ist abgesagt. Du bekommst noch eine kurze Bestätigung per E-Mail.</p>
</div>
<div class="after"><a class="btn-primary" href="#/">Neuen Termin buchen</a><a class="btn-soft" href="../">Zur Homepage</a></div>`;
    }
    if (S.fehler) {
      return `${head()}<div class="done"><h1>Termin nicht gefunden</h1><p>${esc(S.fehler)}</p><p>Bei Fragen ruf uns an: ${telLinks()}</p></div>
<div class="after"><a class="btn-soft" href="#/">Termin buchen</a></div>`;
    }
    if (!S.termin) return `${head()}<div class="done" aria-live="polite"><p>Termin wird geladen …</p></div>`;
    const t = S.termin;
    const aktion = t.vorbei ? '<p class="muted center">Dieser Termin liegt in der Vergangenheit.</p>'
      : t.stornierbar
        ? `<button type="button" class="btn-primary wide" data-act="absagen"${S.sendet ? ' disabled aria-busy="true"' : ''}>${S.sendet ? 'Wird abgesagt …' : 'Termin kostenlos absagen'}</button>
           <p class="muted center small">Kostenlos möglich bis ${esc(t.frist)}.</p>`
        : `<p class="muted center">Online absagen ist nicht mehr möglich (nur bis ${esc(D.storno)} Stunden vorher). Bitte ruf uns an: ${telLinks()}</p>`;
    return `${head()}
<div class="done"><h1>Dein Termin</h1></div>
${terminDetails(t)}
<div class="after">${aktion}${S.meldung ? `<p class="err" role="alert">${esc(S.meldung)}</p>` : ''}</div>`;
  }

  /* ---------- Router ---------- */
  const app = document.getElementById('app');
  const ROUTES = { '': viewStart, termin: viewTermin, buchen: viewBuchen, bestaetigt: viewBestaetigt };
  const TITLES = { '': 'Online-Termin', termin: 'Termin wählen', buchen: 'Buchung abschließen', bestaetigt: 'Termin gebucht' };
  const route = () => location.hash.replace(/^#\/?/, '').replace(/\/$/, '');
  let lastRoute = null;

  function render() {
    if (!D) return;
    let r = route(), inner;
    if (r.indexOf('storno=') === 0) {
      inner = viewStorno();
      r = 'storno';
      document.title = 'Termin absagen – Friseur Andrea Zechmeister';
    } else {
      if (!ROUTES[r]) r = '';
      inner = ROUTES[r]();
      if (!inner) return;
      document.title = TITLES[r] + ' – Friseur Andrea Zechmeister';
    }
    const demo = D.demo ? '<div class="banner" role="note"><span>Testbetrieb: Es wird nichts wirklich gebucht.</span></div>' : '';
    app.innerHTML = demo + `<div class="cust"><div class="phone">${inner}</div></div>`;
    if (lastRoute !== null && r !== lastRoute) {
      const h = app.querySelector('h1');
      if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
    }
    lastRoute = r;
  }
  function rerender() {
    const a = document.activeElement;
    let sel = null;
    if (a && app.contains(a) && a.dataset && a.dataset.act) {
      sel = '[data-act="' + CSS.escape(a.dataset.act) + '"]' + (a.dataset.arg != null ? '[data-arg="' + CSS.escape(a.dataset.arg) + '"]' : '');
    }
    render();
    if (sel) { const el = app.querySelector(sel); if (el) el.focus({ preventScroll: true }); }
  }
  function go(hash) {
    if (location.hash === hash) { render(); window.scrollTo(0, 0); } else location.hash = hash;
  }
  let toastTimer = null;
  function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 4000);
  }

  /* ---------- Daten laden ---------- */
  let ladeNr = 0;
  async function wocheLaden() {
    const nr = ++ladeNr;
    U.tage = null; U.fehler = '';
    rerender();
    const start = keyOf(addDays(fromKey(D.heute), U.woche * 7));
    try {
      const r = await api('woche', { leistung: U.leistung, person: U.person, start });
      if (nr !== ladeNr) return;
      U.tage = r.tage;
    } catch (e) {
      if (nr !== ladeNr) return;
      U.fehler = e.message;
    }
    if (route() === 'termin') rerender();
  }

  async function stornoLaden() {
    const token = route().slice('storno='.length);
    U.storno = { token, termin: null, fehler: '', fertig: false, sendet: false, meldung: '' };
    render();
    try {
      U.storno.termin = (await api('termin', { t: token })).termin;
    } catch (e) {
      U.storno.fehler = e.message;
    }
    render();
  }

  async function buchen(f) {
    const v = (n) => f.elements.namedItem(n);
    const F = U.form = { name: v('name').value.trim(), mail: v('mail').value.trim(), tel: v('tel').value.trim(), agb: v('agb').checked };
    const E = {};
    if (F.name.length < 2) E.name = 'Bitte gib deinen Namen an.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(F.mail)) E.mail = 'Bitte gib eine gültige E-Mail-Adresse an.';
    if (F.tel.replace(/\D/g, '').length < 6) E.tel = 'Bitte gib eine Telefonnummer an.';
    if (!F.agb) E.agb = 'Bitte bestätige die Buchung.';
    U.felder = E; U.fehler = '';
    if (Object.keys(E).length) {
      render();
      const first = app.querySelector('[aria-invalid="true"]');
      if (first) first.focus();
      return;
    }
    U.sendet = true;
    render();
    try {
      const r = await api('buchen', {}, {
        leistung: U.leistung, person: U.person, ts: U.zeit.ts,
        name: F.name, mail: F.mail, tel: F.tel, agb: F.agb, website: v('website') ? v('website').value : '',
      });
      U.gebucht = r.termin;
      U.zeit = null;
      U.form.agb = false;
      U.sendet = false;
      go('#/bestaetigt');
    } catch (e) {
      U.sendet = false;
      if (e.data && e.data.felder) U.felder = e.data.felder;
      U.fehler = e.message;
      if (e.status === 409) { U.tage = null; toast(e.message); go('#/termin'); wocheLaden(); return; }
      render();
    }
  }

  /* ---------- Aktionen ---------- */
  const ACT = {
    leistung: (id) => { U.leistung = id; U.zeit = null; U.tag = null; U.woche = 0; go('#/termin'); wocheLaden(); },
    person: (id) => { U.person = id; wocheLaden(); },
    woche: (dir) => { U.woche = Math.max(0, U.woche + Number(dir)); U.tag = null; wocheLaden(); },
    tag: (key) => { U.tag = key; rerender(); },
    'neu-laden': () => wocheLaden(),
    zeit: (ts) => {
      const t = U.tage.find((x) => x.tag === U.tag);
      const z = t && t.zeiten.find((x) => String(x.ts) === ts);
      if (!z) return;
      U.zeit = { ts: z.ts, uhr: z.uhr, wer: z.wer, tag: t.tag };
      U.felder = {}; U.fehler = '';
      go('#/buchen');
    },
    absagen: async () => {
      if (!window.confirm('Termin wirklich absagen?')) return;
      U.storno.sendet = true; U.storno.meldung = '';
      render();
      try {
        await api('storno', {}, { t: U.storno.token });
        U.storno.fertig = true;
      } catch (e) {
        U.storno.meldung = e.message;
      }
      U.storno.sendet = false;
      render();
    },
  };

  app.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-act]');
    if (!el || !app.contains(el)) return;
    const fn = ACT[el.dataset.act];
    if (fn) fn(el.dataset.arg, el, ev);
  });
  app.addEventListener('input', (ev) => {
    const el = ev.target;
    if (el.form && el.form.dataset.submit === 'buchen' && el.name && el.name in U.form) U.form[el.name] = el.type === 'checkbox' ? el.checked : el.value;
  });
  app.addEventListener('submit', (ev) => {
    if (ev.target.dataset.submit === 'buchen') { ev.preventDefault(); if (!U.sendet) buchen(ev.target); }
  });
  window.addEventListener('hashchange', () => {
    if (route().indexOf('storno=') === 0) stornoLaden();
    else {
      if (route() === 'termin' && U.leistung && !U.tage && !U.fehler) wocheLaden();
      render();
    }
    window.scrollTo(0, 0);
  });

  (async function start() {
    try {
      D = await api('stamm');
    } catch (e) {
      app.innerHTML = '<div class="cust"><div class="phone"><div class="done"><h1>Online-Buchung gerade nicht erreichbar</h1><p>'
        + esc(e.message) + '</p><p>Ruf uns gerne an: <a href="tel:+43463515952">0463 51 59 52</a></p></div></div></div>';
      return;
    }
    if (route().indexOf('storno=') === 0) { stornoLaden(); return; }
    if (route() !== '') location.replace('#/');
    render();
  })();
})();
