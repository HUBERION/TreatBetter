/* Dashboard für den Salon: Tages- und Wochenansicht, Termine eintragen, verschieben, absagen,
   Zeiten blockieren, Kundensuche, Online-Buchung pausieren. Daten über verwaltung.php (Google Kalender). */
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
  const addDays = (k, n) => { const d = fromKey(k); return keyOf(new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)); };
  const montag = (k) => { const d = fromKey(k); return addDays(k, -((d.getDay() + 6) % 7)); };
  const longDate = (k) => { const d = fromKey(k); return DOW_LONG[d.getDay()] + ', ' + d.getDate() + '. ' + MON[d.getMonth()] + ' ' + d.getFullYear(); };
  const shortDate = (k) => { const d = fromKey(k); return DOW[d.getDay()] + ', ' + d.getDate() + '. ' + MON[d.getMonth()]; };
  const toMin = (s) => { const p = String(s).split(':'); return Number(p[0]) * 60 + Number(p[1]); };
  const fmt = (m) => pad(Math.floor(m / 60)) + ':' + pad(m % 60);
  const minOf = (ts) => { const d = new Date(ts * 1000); return d.getHours() * 60 + d.getMinutes(); };
  const endeText = (uhr, dauer) => (/^\d{2}:\d{2}$/.test(uhr || '') && Number(dauer) > 0 ? 'Endet um ' + fmt(toMin(uhr) + Number(dauer)) + ' Uhr.' : '');
  const ART_TEXT = { online: 'Online', telefon: 'Telefon', treatwell: 'Treatwell', block: 'Blockiert', kalender: 'Kalender' };
  const istKunde = (t) => t.art === 'online' || t.art === 'telefon' || t.art === 'treatwell';

  const ICONS = {
    cal: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    week: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 10v10M12 10v10M16 10v10"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3 2.8-4.8 5.5-4.8s4.9 1.8 5.5 4.8"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.4c2.4.2 4.1 1.8 4.6 4.1"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
    sliders: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    left: '<path d="M15 6l-6 6 6 6"/>',
    right: '<path d="M9 6l6 6-6 6"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6 8.5-6"/>',
    ext: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
  };
  const ic = (name, size, sw) => '<svg width="' + (size || 20) + '" height="' + (size || 20) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 1.8) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';

  /* ---------- Zustand ---------- */
  let D = null;            // zuletzt geladene Daten (daten-Antwort)
  let geladenFuer = '';    // welcher Zeitraum in D steckt
  let laedt = false;
  let fehler = '';
  let angemeldet = null;
  let demo = false;
  let suche = { q: '', treffer: null, laedt: false, fehler: '' };
  const kartei = { q: '', liste: null, gesamt: null, fehler: '' };
  const karte = { id: null, daten: null, fehler: '' };
  let M = null;            // offener Dialog
  const app = document.getElementById('app');
  const dlg = document.getElementById('modal');

  /* ---------- Server ---------- */
  async function api(a, params, body) {
    const q = new URLSearchParams(Object.assign({ a }, params || {}));
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Verwaltung': '1' }, body: JSON.stringify(body) } : {};
    opt.credentials = 'same-origin';
    const res = await fetch('verwaltung.php?' + q.toString(), opt);
    let data = {};
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (res.status === 401 && a !== 'login') { angemeldet = false; render(); }
    if (!res.ok) { const err = new Error(data.fehler || 'Da ist etwas schiefgegangen.'); err.status = res.status; err.data = data; throw err; }
    return data;
  }

  /* ---------- Router ---------- */
  function route() {
    const r = location.hash.replace(/^#\/?/, '').split('/');
    const heute = keyOf(new Date());
    const datum = /^\d{4}-\d{2}-\d{2}$/.test(r[1] || '') ? r[1] : heute;
    if (r[0] === 'woche') return { view: 'woche', datum: montag(datum) };
    if (r[0] === 'suche' || r[0] === 'kunden') return { view: 'kunden' };
    if (r[0] === 'kunde' && /^[0-9a-f]{16}$/.test(r[1] || '')) return { view: 'kunde', id: r[1] };
    if (r[0] === 'einstellungen') return { view: 'einstellungen' };
    return { view: 'tag', datum };
  }
  const zeitraum = (r) => (r.view === 'woche' ? r.datum + '/7' : r.view === 'tag' ? r.datum + '/1' : '');

  async function laden(still) {
    const r = route();
    const z = zeitraum(r);
    if (r.view === 'kunden') karteiLaden();
    if (r.view === 'kunde' && (still || karte.id !== r.id || !karte.daten)) karteLaden(r.id);
    if (!z && D) { render(); return; }
    const [von, tage] = (z || keyOf(new Date()) + '/1').split('/');
    if (!still) { laedt = true; fehler = ''; render(); }
    try {
      const daten = await api('daten', { von, tage });
      if (zeitraum(route()) !== z && z) return;
      D = daten; geladenFuer = z; demo = daten.demo;
    } catch (e) {
      if (e.status !== 401) fehler = e.message;
    }
    laedt = false;
    render();
  }

  let karteiNr = 0;
  async function karteiLaden() {
    const nr = ++karteiNr;
    try {
      const r = await api('kunden', { q: kartei.q });
      if (nr !== karteiNr) return;
      kartei.liste = r.kunden; kartei.gesamt = r.gesamt; kartei.fehler = '';
    } catch (e) {
      if (nr !== karteiNr) return;
      kartei.fehler = e.message;
    }
    if (route().view === 'kunden') neuZeichnenBehaltenFokus();
  }

  async function karteLaden(id) {
    karte.id = id; karte.fehler = '';
    if (karte.daten && karte.daten.id !== id) karte.daten = null;
    try {
      karte.daten = (await api('kunde', { id })).kunde;
    } catch (e) {
      if (e.status !== 401) karte.fehler = e.message;
    }
    if (route().view === 'kunde') render();
  }

  // Beim Tippen in der Suche neu zeichnen, ohne dass das Eingabefeld den Fokus oder die Schreibmarke verliert
  function neuZeichnenBehaltenFokus() {
    const a = document.activeElement;
    const id = a && a.id, pos = a && typeof a.selectionStart === 'number' ? a.selectionStart : null;
    render();
    if (id) {
      const el = document.getElementById(id);
      if (el) { el.focus({ preventScroll: true }); if (pos !== null) try { el.setSelectionRange(pos, pos); } catch (e) { /* egal */ } }
    }
  }

  /* ---------- Ansichten ---------- */
  function shell(r, inner) {
    const link = (href, icon, label, key) => `<a class="side-link" href="${href}"${r.view === key ? ' aria-current="page"' : ''}>${ic(icon, 18)}${label}</a>`;
    const datum = r.datum || keyOf(new Date());
    const pausiert = D && Object.values(D.pausiert).some(Boolean);
    return `${demo ? '<div class="banner" role="note"><span>Testbetrieb: Es wird nichts wirklich eingetragen.</span></div>' : ''}<div class="admin">
  <nav class="side" aria-label="Dashboard">
    <div class="side-brand"><span class="mono" aria-hidden="true">AZ</span><div><b>Friseur Andrea Zechmeister</b><span class="muted">Dashboard</span></div></div>
    <div class="side-links">
      ${link('#/tag/' + (r.view === 'woche' ? datum : datum), 'cal', 'Tag', 'tag')}
      ${link('#/woche/' + datum, 'week', 'Woche', 'woche')}
      ${link('#/kunden', 'users', 'Kunden', r.view === 'kunde' ? r.view : 'kunden')}
      ${link('#/einstellungen', 'sliders', 'Einstellungen' + (pausiert ? ' <span class="badge">pausiert</span>' : ''), 'einstellungen')}
      <button type="button" class="side-link cta" data-act="neu">${ic('plus', 18, 2)}Eintragen</button>
    </div>
  </nav>
  <main class="main" id="main">${treatwellHinweise()}${inner}</main>
</div>`;
  }

  /* Hinweise aus dem Treatwell-Abruf (Überschneidungen, unlesbare E-Mails, Postfach-Fehler) */
  function treatwellHinweise() {
    const tw = D && D.treatwell;
    if (!tw || !tw.aktiv) return '';
    const liste = (tw.hinweise || []).map((h) => `<div class="note warn" role="status">${ic('info', 20)}<div style="flex:1"><b>Treatwell</b><span>${esc(h.text)}</span>${h.betreff ? `<span class="muted small">E-Mail: ${esc(h.betreff)}</span>` : ''}</div><button type="button" class="mini" data-act="hinweis-erledigt" data-arg="${esc(h.id)}">Erledigt</button></div>`).join('');
    const fehler = tw.fehler ? `<div class="note warn" role="alert">${ic('info', 20)}<div><b>Treatwell-E-Mails werden gerade nicht abgerufen</b><span>${esc(tw.fehler)}</span><span class="muted small">Bis das behoben ist, Treatwell-Buchungen bitte von Hand eintragen.</span></div></div>` : '';
    return fehler + liste;
  }

  function terminText(t) {
    if (istKunde(t)) return (t.name || (t.anonym ? 'Kundin/Kunde' : 'Ohne Namen')) + (t.leistung ? ' · ' + t.leistung : '') + (t.twBezahlt === 'Vorab bezahlt' ? ' · bereits bezahlt' : '');
    return t.titel || 'Belegt';
  }

  function viewTag(r) {
    const tag = (D && D.tage[0]) || null;
    const heute = keyOf(new Date());
    const nav = `<div class="daynav">
      <a class="icon-btn" href="#/tag/${addDays(r.datum, -1)}" aria-label="Vorheriger Tag">${ic('left', 18, 2)}</a>
      <a class="today-btn" href="#/tag/${heute}" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit">Heute</a>
      <a class="icon-btn" href="#/tag/${addDays(r.datum, 1)}" aria-label="Nächster Tag">${ic('right', 18, 2)}</a>
    </div>
    <input type="date" class="today-btn" style="border:1px solid var(--line2);font:inherit" value="${r.datum}" data-change="datum" aria-label="Datum wählen">`;
    const kopf = `<div class="pagehead">
  <div><h1>${esc(longDate(r.datum))}</h1><span class="muted" style="font-size:14px">${r.datum === heute ? 'Heute' : r.datum === addDays(heute, 1) ? 'Morgen' : ''}${tag && tag.zu ? (r.datum === heute ? ' · ' : '') + 'Geschlossen' + (tag.hinweis ? ' (' + esc(tag.hinweis) + ')' : '') : tag ? (r.datum === heute ? ' · ' : '') + 'Geöffnet ' + tag.von + '–' + tag.bis : ''}</span></div>
  ${nav}
</div>`;
    if (!D || geladenFuer !== r.datum + '/1') return kopf + zustand();

    const START = 7 * 60, END = 20 * 60 + 30, H = 52;
    const y = (m) => Math.round((Math.min(Math.max(m, START), END) - START) * H / 60);
    const total = y(END);
    const jetzt = new Date();
    const nm = jetzt.getHours() * 60 + jetzt.getMinutes();
    const termine = D.termine.filter((t) => t.tag === r.datum || (t.ganztags && t.beginn <= fromKey(r.datum).getTime() / 1000 + 43200 && t.ende > fromKey(r.datum).getTime() / 1000));

    const cols = D.team.map((p) => {
      const blocks = [];
      const add = (a, b, cls, t1, t2, t) => {
        const top = y(a) + 1, h = Math.max(y(b) - y(a) - 2, 18), tall = h >= 36 && t2;
        const inhalt = tall ? `<b>${t1}</b><span>${t2}</span>` : `<b>${t1}${t2 ? ' · ' + t2 : ''}</b>`;
        blocks.push(t
          ? `<button type="button" class="blk ${cls}${tall ? ' tall' : ''}" style="top:${top}px;height:${h}px" data-act="detail" data-arg="${esc(t.person)}|${esc(t.id)}">${inhalt}</button>`
          : `<div class="blk ${cls}" style="top:${top}px;height:${h}px" aria-hidden="true">${inhalt}</div>`);
      };
      if (tag.zu) add(START, END, 'blk-off', 'Geschlossen' + (tag.hinweis ? ' · ' + esc(tag.hinweis) : ''), '');
      else {
        if (toMin(tag.von) > START) add(START, toMin(tag.von), 'blk-off', '', '');
        if (toMin(tag.bis) < END) add(toMin(tag.bis), END, 'blk-off', '', '');
        if (p.bis && toMin(p.bis) < toMin(tag.bis)) add(toMin(p.bis), toMin(tag.bis), 'blk-off', 'online nicht buchbar', '');
      }
      termine.filter((t) => t.person === p.id).forEach((t) => {
        const a = t.ganztags ? Math.max(START, toMin(tag.von || '07:00')) : minOf(t.beginn);
        const b = t.ganztags ? Math.min(END, toMin(tag.bis || '20:00')) : minOf(t.ende);
        const zeit = t.ganztags ? 'ganztägig' : t.uhr + '–' + t.bis;
        const cls = t.art === 'online' ? 'blk-book' : t.art === 'telefon' ? 'blk-book tel' : t.art === 'treatwell' ? 'blk-book tw' : !t.blockiert ? 'blk-soft' : 'blk-block';
        add(a, b, cls, esc(zeit + ' ' + ART_TEXT[t.art]), esc(terminText(t)), t);
      });
      if (r.datum === keyOf(jetzt) && nm >= START && nm <= END) blocks.push(`<div class="nowline" style="top:${y(nm)}px" aria-hidden="true"></div>`);
      const lines = [];
      for (let m = START; m <= END; m += 60) lines.push(`<div class="hline" style="top:${y(m)}px"></div>`);
      const pausiert = D.pausiert[p.id];
      return {
        head: `<div class="cal-col-head"><span class="avatar">${esc(p.name[0])}</span><div><b>${esc(p.name)}</b><span>${pausiert ? 'online pausiert' : p.bis ? 'online bis ' + p.bis : 'online buchbar'}</span></div></div>`,
        body: `<div class="col" style="height:${total}px"><button type="button" class="col-klick" data-act="neu-klick" data-arg="${p.id}" aria-label="Bei ${esc(p.name)} eintragen"></button>${lines.join('')}${blocks.join('')}</div>`,
      };
    });
    const labels = [];
    for (let m = START; m < END; m += 60) labels.push(`<span style="top:${y(m) + 4}px">${fmt(m)}</span>`);
    const gridCols = `44px repeat(${D.team.length}, minmax(0, 1fr))`;

    const liste = termine.filter(istKunde).sort((a, b) => a.beginn - b.beginn).map((t) => `<div class="bk">
      <div class="bk-top"><span class="bk-time">${esc(t.uhr)}</span>
        <div class="bk-main"><b><span class="art art-${t.art}">${ART_TEXT[t.art]}</span>${esc(t.name || 'Ohne Namen')}</b><span>${esc(t.leistung)} · ${esc(personName(t.person))} · bis ${esc(t.bis)}</span>
        ${t.tel ? `<span><a href="tel:${esc(t.tel.replace(/[^\d+]/g, ''))}">${esc(t.tel)}</a></span>` : ''}</div></div>
      <div class="bk-actions"><button type="button" class="mini" data-act="detail" data-arg="${esc(t.person)}|${esc(t.id)}">Details</button></div>
    </div>`).join('') || `<p class="muted" style="font-size:14px;padding:8px 0">${tag.zu ? 'Geschlossen.' : 'Noch keine Kundentermine an diesem Tag.'}</p>`;
    const anzahl = termine.filter(istKunde).length;

    return `${kopf}
<div class="split">
  <section class="cal" aria-label="Tagesansicht">
    <div class="cal-scroll"><div class="cal-inner dash">
      <div class="cal-head" style="grid-template-columns:${gridCols}"><div></div>${cols.map((c) => c.head).join('')}</div>
      <div class="cal-body" style="grid-template-columns:${gridCols}"><div class="gutter" style="height:${total}px">${labels.join('')}</div>${cols.map((c) => c.body).join('')}</div>
    </div></div>
  </section>
  <aside class="aside">
    <section class="panel"><h2>Kundentermine · ${anzahl}</h2><div style="margin-top:8px">${liste}</div></section>
    <section class="panel">
      <h2>Legende</h2>
      <div class="legend">
        <div><span class="sw" style="background:#7B2D8E"></span>Online gebucht</div>
        <div><span class="sw" style="background:#3D5A80"></span>Telefonisch eingetragen</div>
        <div><span class="sw" style="background:#00756A"></span>Über Treatwell gebucht</div>
        <div><span class="sw" style="background:#E6E2EA;border-left:4px solid #9A95A1"></span>Blockiert (Pause, Urlaub …) oder direkt im Google Kalender eingetragen</div>
        <div><span class="sw" style="background:repeating-linear-gradient(135deg,#DDD8E2 0 4px,#F8F7FA 4px 8px)"></span>Geschlossen bzw. online nicht buchbar</div>
      </div>
      <p class="muted small" style="margin-top:10px">Tipp: In eine freie Stelle der Spalte tippen, um dort etwas einzutragen.</p>
    </section>
  </aside>
</div>`;
  }

  function viewWoche(r) {
    const heute = keyOf(new Date());
    const kopf = `<div class="pagehead">
  <div><h1>Woche ab ${esc(shortDate(r.datum))}</h1><span class="muted" style="font-size:14px">${esc(shortDate(r.datum))} bis ${esc(shortDate(addDays(r.datum, 6)))}</span></div>
  <div class="daynav">
    <a class="icon-btn" href="#/woche/${addDays(r.datum, -7)}" aria-label="Vorherige Woche">${ic('left', 18, 2)}</a>
    <a class="today-btn" href="#/woche/${montag(heute)}" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit">Diese Woche</a>
    <a class="icon-btn" href="#/woche/${addDays(r.datum, 7)}" aria-label="Nächste Woche">${ic('right', 18, 2)}</a>
  </div>
</div>`;
    if (!D || geladenFuer !== r.datum + '/7') return kopf + zustand();
    const tage = D.tage.map((t) => {
      const start = fromKey(t.tag).getTime() / 1000;
      const liste = D.termine.filter((x) => x.tag === t.tag || (x.ganztags && x.beginn <= start && x.ende > start));
      const items = liste.map((x) => `<button type="button" class="wk-item" data-act="detail" data-arg="${esc(x.person)}|${esc(x.id)}">
        <b>${x.ganztags ? 'Tag' : esc(x.uhr)}</b><span><span class="art art-${x.art}">${esc(personName(x.person))}</span>${esc(terminText(x))}</span></button>`).join('');
      const kunden = liste.filter(istKunde).length;
      return `<section class="wk-day${t.tag === heute ? ' heute' : ''}">
        <div class="wk-head"><a href="#/tag/${t.tag}">${esc(shortDate(t.tag))}</a><span class="muted small">${t.zu ? 'geschlossen' : kunden + ' Kund' + (kunden === 1 ? 'e' : 'en')}</span></div>
        ${items || `<span class="muted small">${t.zu ? (t.hinweis ? esc(t.hinweis) : 'Geschlossen') : 'Noch nichts eingetragen'}</span>`}
      </section>`;
    }).join('');
    return `${kopf}<div class="wk">${tage}</div>`;
  }

  function viewSuche() {
    let ergebnis = '';
    if (suche.laedt) ergebnis = '<p class="muted">Suche läuft …</p>';
    else if (suche.fehler) ergebnis = `<p class="err" role="alert">${esc(suche.fehler)}</p>`;
    else if (suche.treffer) {
      const jetzt = Date.now() / 1000;
      ergebnis = suche.treffer.length ? suche.treffer.map((t) => `<div class="bk">
        <div class="bk-top"><span class="bk-time" style="width:auto">${esc(shortDate(t.tag))}<br><span class="muted">${esc(t.uhr)}</span></span>
          <div class="bk-main"><b><span class="art art-${t.art}">${ART_TEXT[t.art]}</span>${esc(t.name)}${t.beginn > jetzt ? ' <span class="badge g">kommt noch</span>' : ''}</b>
          <span>${esc(t.leistung)} · ${esc(personName(t.person))}</span>${t.tel ? `<span><a href="tel:${esc(t.tel.replace(/[^\d+]/g, ''))}">${esc(t.tel)}</a>${t.mail ? ' · ' + esc(t.mail) : ''}</span>` : ''}</div></div>
        <div class="bk-actions"><button type="button" class="mini" data-act="such-detail" data-arg="${esc(t.person)}|${esc(t.id)}">Details</button><a class="mini" href="#/tag/${t.tag}" style="text-decoration:none;display:inline-flex;align-items:center">Zum Tag</a></div>
      </div>`).join('') : '<p class="muted">Nichts gefunden. Gesucht wird in den letzten 12 Monaten und allen kommenden Terminen.</p>';
    }
    return ergebnis;
  }

  /* ---------- Kundenkartei ---------- */
  const besuchKurz = (b) => (b ? `${esc(shortDate(b.tag))}${b.uhr ? ', ' + esc(b.uhr) : ''} · ${esc(b.leistung)}${b.personName ? ' bei ' + esc(b.personName) : ''}` : '');

  function viewKunden() {
    const liste = kartei.liste;
    let inhalt;
    if (kartei.fehler) inhalt = `<p class="err" role="alert">${esc(kartei.fehler)}</p>`;
    else if (!liste) inhalt = '<p class="muted">Wird geladen …</p>';
    else if (!liste.length) inhalt = kartei.q
      ? `<p class="muted">Keine Kundenkarte gefunden.</p>`
      : `<p class="muted">Noch keine Kundenkarten. Leg die erste mit „Neue Kundenkarte“ an, zum Beispiel von einer Papierkarte.</p>`;
    else inhalt = liste.map((k) => `<a class="bk" href="#/kunde/${esc(k.id)}" style="text-decoration:none;color:inherit">
        <div class="bk-top"><div class="bk-main"><b>${esc(k.name)}</b>
          <span>${k.tel ? esc(k.tel) : ''}${k.tel && k.mail ? ' · ' : ''}${k.mail ? esc(k.mail) : ''}</span>
          ${k.letzter ? `<span>Zuletzt: ${besuchKurz(k.letzter)}</span>` : '<span>Noch kein Besuch eingetragen</span>'}
          ${k.naechster ? `<span><span class="badge g">Nächster Termin</span> ${besuchKurz(k.naechster)}</span>` : ''}</div>
          ${ic('right', 18, 2)}</div></a>`).join('');
    return `<div class="pagehead"><div><h1>Kunden</h1><span class="muted" style="font-size:14px">${kartei.gesamt != null ? kartei.gesamt + ' Kundenkarte' + (kartei.gesamt === 1 ? '' : 'n') : ''}</span></div>
  <button type="button" class="btn-primary sm" data-act="karte-neu">${ic('plus', 18, 2)}Neue Kundenkarte</button></div>
<div class="suchfeld" role="search">
  <label for="kartei-q" style="position:absolute;left:-9999px">Kundenkarte suchen</label>
  <input id="kartei-q" type="search" placeholder="Name oder Telefonnummer …" value="${esc(kartei.q)}" autocomplete="off" data-input="kartei">
</div>
<section class="panel">${inhalt}</section>
<section class="panel">
  <h2>Excel</h2>
  <p class="muted small" style="margin:6px 0 10px">Karteikarten aus einer Excel-Liste übernehmen oder die ganze Kartei als Excel-Datei sichern. Für den Import am besten die Vorlage verwenden.</p>
  <div class="knoepfe">
    <button type="button" class="btn-outline" data-act="import">${ic('plus', 18, 2)}Aus Excel importieren</button>
    <a class="btn-outline" href="verwaltung.php?a=export" download>${ic('ext', 18)}Kartei als Excel exportieren</a>
    <a class="btn-outline" href="verwaltung.php?a=vorlage" download>${ic('ext', 18)}Vorlage herunterladen</a>
  </div>
</section>
<section class="panel">
  <h2>Auch Termine ohne Kundenkarte durchsuchen</h2>
  <form class="suchfeld" data-submit="suche" role="search" style="margin-top:10px">
    <label for="such-q" style="position:absolute;left:-9999px">In Terminen suchen</label>
    <input id="such-q" name="q" type="search" placeholder="z. B. Huber oder 0664 …" value="${esc(suche.q)}" autocomplete="off">
    <button type="submit" class="btn-outline">${ic('search', 18, 2)}Suchen</button>
  </form>
  <div style="margin-top:8px">${viewSuche()}</div>
</section>`;
  }

  function viewKunde(r) {
    const k = karte.daten && karte.daten.id === r.id ? karte.daten : null;
    if (karte.fehler) return `<div class="note warn" role="alert">${ic('info', 20)}<div><b>Kundenkarte nicht gefunden</b><span>${esc(karte.fehler)}</span><a href="#/kunden">Zur Kundenliste</a></div></div>`;
    if (!k) return '<p class="muted">Wird geladen …</p>';
    const jetzt = Date.now() / 1000;
    const kommend = k.besuche.filter((b) => b.beginn > jetzt).reverse();
    const bisher = k.besuche.filter((b) => b.beginn <= jetzt);
    const zeile = (b) => `<div class="bk"><div class="bk-top">
        <span class="bk-time" style="width:auto;min-width:92px">${esc(shortDate(b.tag))}${b.uhr ? '<br><span class="muted">' + esc(b.uhr) + '</span>' : ''}</span>
        <div class="bk-main"><b>${esc(b.leistung)}</b><span>${b.personName ? 'bei ' + esc(b.personName) : ''}${b.quelle === 'online' ? ' · online gebucht' : b.quelle === 'nachgetragen' ? ' · nachgetragen' : ''}</span></div>
        ${b.quelle === 'nachgetragen' ? `<button type="button" class="mini" data-act="besuch-weg" data-arg="${esc(b.id)}" aria-label="Nachgetragenen Besuch vom ${esc(shortDate(b.tag))} entfernen">Entfernen</button>` : b.terminId ? `<a class="mini" href="#/tag/${b.tag}" style="text-decoration:none;display:inline-flex;align-items:center">Zum Tag</a>` : ''}
      </div></div>`;
    return `<div class="pagehead">
  <div><h1>${esc(k.name)}</h1><span class="muted" style="font-size:14px">${k.anzahl} Besuch${k.anzahl === 1 ? '' : 'e'}${k.letzter ? ' · zuletzt ' + esc(shortDate(k.letzter.tag)) : ''}</span></div>
  <button type="button" class="btn-primary sm" data-act="karte-termin">${ic('plus', 18, 2)}Termin eintragen</button>
</div>
<div class="split">
  <div style="flex:999 1 420px;min-width:0;display:flex;flex-direction:column;gap:16px">
    ${k.letzter ? `<section class="panel" style="border-color:var(--plum);box-shadow:inset 0 0 0 1px var(--plum)"><h2>Letztes Mal</h2><p style="margin-top:6px;font-size:16px"><b>${esc(k.letzter.leistung)}</b></p><p class="muted">${esc(longDate(k.letzter.tag))}${k.letzter.personName ? ' bei ' + esc(k.letzter.personName) : ''}</p></section>` : ''}
    <section class="panel"><h2>Kommende Termine</h2><div style="margin-top:6px">${kommend.map(zeile).join('') || '<p class="muted small">Kein Termin eingetragen.</p>'}</div></section>
    <section class="panel"><div class="modal-head"><h2>Bisherige Besuche</h2><button type="button" class="mini" data-act="besuch-neu">Besuch nachtragen</button></div><div style="margin-top:6px">${bisher.map(zeile).join('') || '<p class="muted small">Noch keine Besuche. Von der Papierkarte kannst du den letzten Besuch nachtragen.</p>'}</div></section>
  </div>
  <aside class="aside">
    <section class="panel">
      <div class="modal-head"><h2>Kontakt &amp; Notiz</h2><button type="button" class="mini" data-act="karte-bearbeiten">Bearbeiten</button></div>
      <dl class="infozeilen" style="margin-top:10px">
        <dt>Telefon</dt><dd>${k.tel ? `<a href="tel:${esc(k.tel.replace(/[^\d+]/g, ''))}">${esc(k.tel)}</a>` : '–'}</dd>
        <dt>E-Mail</dt><dd>${k.mail ? `<a href="mailto:${esc(k.mail)}">${esc(k.mail)}</a>` : '–'}</dd>
      </dl>
      <p style="margin-top:12px;white-space:pre-wrap">${k.notiz ? esc(k.notiz) : '<span class="muted">Keine Notiz.</span>'}</p>
    </section>
    <section class="panel">
      <h2>Datenschutz</h2>
      <p class="muted small" style="margin-top:6px">Diese Karte wird am ${esc(longDate(k.loeschenAm))} automatisch gelöscht, wenn bis dahin kein Besuch mehr dazukommt.</p>
      <button type="button" class="btn-danger" data-act="karte-loeschen" style="margin-top:10px">Karte jetzt löschen</button>
    </section>
  </aside>
</div>`;
  }

  function viewEinstellungen() {
    if (!D) return zustand();
    const sw = (on, arg, label) => `<button type="button" class="switch" role="switch" aria-checked="${!on}" aria-label="${label}" data-act="pause" data-arg="${arg}"><span class="track"><span class="knob"></span></span></button>`;
    const reihen = D.team.map((p) => `<div class="dayrow"><span class="dayname">${esc(p.name)}</span>${sw(D.pausiert[p.id], p.id, 'Online-Buchung bei ' + p.name)}<span class="muted small">${D.pausiert[p.id] ? 'Online-Buchung pausiert' : 'Online buchbar'}</span></div>`).join('');
    return `<div class="pagehead"><div><h1>Einstellungen</h1></div></div>
<section class="panel">
  <h2>Online-Buchung</h2>
  <p class="muted small" style="margin:6px 0 4px">Ausschalten, wenn jemand kurzfristig ausfällt. Bereits gebuchte Termine bleiben bestehen, es kann nur nichts Neues online gebucht werden. Telefonisch eintragen geht weiterhin.</p>
  ${reihen}
</section>
<section class="panel">
  <h2>Links</h2>
  <div class="knoepfe" style="margin-top:10px">
    <a class="btn-outline" href="./" target="_blank" rel="noopener">${ic('ext', 18)}Buchungsseite ansehen</a>
    <a class="btn-outline" href="https://calendar.google.com" target="_blank" rel="noopener">${ic('cal', 18)}Google Kalender öffnen</a>
  </div>
</section>
<section class="panel">
  <h2>Abmelden</h2>
  <p class="muted small" style="margin:6px 0 10px">Auf diesem Gerät bleibt man sonst 30 Tage angemeldet.</p>
  <button type="button" class="btn-danger" data-act="logout">Abmelden</button>
</section>`;
  }

  function zustand() {
    if (fehler) return `<div class="note warn" role="alert">${ic('info', 20)}<div><b>Konnte nicht laden</b><span>${esc(fehler)}</span><button type="button" class="mini" data-act="neu-laden" style="align-self:flex-start;margin-top:6px">Nochmal versuchen</button></div></div>`;
    return '<p class="muted" aria-live="polite">Wird geladen …</p>';
  }

  function viewLogin() {
    return `<form class="loginbox" data-submit="login">
  <div class="side-brand" style="padding:0"><span class="mono" aria-hidden="true">AZ</span><div><b>Friseur Andrea Zechmeister</b><span class="muted">Dashboard</span></div></div>
  <h1 style="font-size:22px">Anmelden</h1>
  <div class="field"><label for="pw">Passwort</label><input id="pw" name="passwort" type="password" autocomplete="current-password" required></div>
  <p class="err" id="login-fehler" role="alert"></p>
  <button type="submit" class="btn-primary">Anmelden</button>
  <p class="muted small">Auf diesem Gerät bleibst du 30 Tage angemeldet.</p>
</form>`;
  }

  const personName = (id) => ((D && D.team.find((p) => p.id === id)) || { name: id }).name;

  function render() {
    if (angemeldet === null) { app.innerHTML = '<p class="muted" style="padding:24px">Wird geladen …</p>'; return; }
    if (!angemeldet) {
      document.title = 'Anmelden – Dashboard';
      app.innerHTML = viewLogin();
      const pw = document.getElementById('pw');
      if (pw) pw.focus();
      return;
    }
    const r = route();
    const inner = r.view === 'woche' ? viewWoche(r) : r.view === 'kunden' ? viewKunden() : r.view === 'kunde' ? viewKunde(r) : r.view === 'einstellungen' ? viewEinstellungen() : viewTag(r);
    const fokus = document.activeElement && app.contains(document.activeElement) && document.activeElement.dataset ? document.activeElement.dataset.act : null;
    app.innerHTML = shell(r, inner);
    document.title = ({ tag: 'Tag', woche: 'Woche', kunden: 'Kunden', kunde: 'Kundenkarte', einstellungen: 'Einstellungen' }[r.view]) + ' – Dashboard';
    if (fokus) { const el = app.querySelector('[data-act="' + CSS.escape(fokus) + '"]'); if (el) el.focus({ preventScroll: true }); }
  }

  /* ---------- Dialoge ---------- */
  const termin = (arg) => {
    const [person, id] = String(arg).split('|');
    const quelle = (M && M.liste) || (D ? D.termine : []);
    return quelle.find((t) => t.person === person && t.id === id) || (suche.treffer || []).find((t) => t.person === person && t.id === id);
  };

  function dialogOeffnen(m) {
    M = m;
    dialogZeichnen();
    if (!dlg.open) dlg.showModal();
    const first = dlg.querySelector('[autofocus]') || dlg.querySelector('input, select, button:not(.icon-btn)');
    if (first) first.focus();
  }
  function dialogSchliessen() {
    M = null;
    if (dlg.open) dlg.close();
  }
  dlg.addEventListener('close', () => { M = null; });

  const kopfDialog = (titel) => `<div class="modal-head"><h2 id="modal-titel">${titel}</h2><button type="button" class="icon-btn" data-act="schliessen" aria-label="Schließen">${ic('close', 22, 2)}</button></div>`;
  const auswahlPerson = (name, wert, mitBeide) => `<div class="field"><label for="f-${name}">Bei</label><select id="f-${name}" name="${name}">
    ${D.team.map((p) => `<option value="${p.id}"${wert === p.id ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}${mitBeide ? `<option value="beide"${wert === 'beide' ? ' selected' : ''}>Beide</option>` : ''}</select></div>`;
  const feld = (name, label, type, wert, extra) => `<div class="field"><label for="f-${name}">${label}</label><input id="f-${name}" name="${name}" type="${type}" value="${esc(wert || '')}"${extra || ''}${M.felder && M.felder[name] ? ` aria-invalid="true" aria-describedby="e-${name}"` : ''}>${M.felder && M.felder[name] ? `<span class="err" id="e-${name}">${esc(M.felder[name])}</span>` : ''}</div>`;
  const konfliktHtml = () => M.konflikt ? `<div class="note warn" role="alert">${ic('info', 20)}<div><b>Zu dieser Zeit ist schon etwas eingetragen</b><span>${M.konflikt.map(esc).join('<br>')}</span></div></div>` : '';
  const fehlerHtml = () => (M.fehler && !M.konflikt ? `<p class="err" role="alert">${esc(M.fehler)}</p>` : '');

  function dialogZeichnen() {
    if (!M) return;
    let html = '';
    if (M.typ === 'neu') {
      const f = M.werte;
      const bearbeiten = !!M.edit; // gleicher Dialog zum Bearbeiten eines bestehenden Eintrags
      const reiter = `<div class="seg" role="group" aria-label="Was eintragen?">
        <button type="button" data-act="neu-art" data-arg="termin" aria-pressed="${f.art === 'termin'}">Kundentermin</button>
        <button type="button" data-act="neu-art" data-arg="block" aria-pressed="${f.art === 'block'}">Zeit blockieren</button></div>`;
      let felder;
      if (f.art === 'termin') {
        const eigene = f.leistung === 'eigene';
        const vorgabe = eigene ? null : D.leistungen.find((l) => l.id === f.leistung);
        // Dauer: Vorgabe der Leistung oder selbst eingetragen; bei „Andere Tätigkeit“ immer selbst
        const dauerFeld = eigene || f.dauerEigen
          ? `${feld('dauer', 'Dauer in Minuten', 'number', f.dauer || (vorgabe ? vorgabe.min : ''), ' min="5" max="600" step="5" inputmode="numeric" required')}
             ${!eigene ? `<div class="field" style="justify-content:flex-end"><button type="button" class="btn-outline" data-act="dauer-vorgabe">Vorgabe ${vorgabe.min} Min. verwenden</button></div>` : '<div></div>'}`
          : `<div class="field"><span class="sublabel">Dauer</span><span style="min-height:48px;display:flex;align-items:center">${vorgabe ? vorgabe.min : ''} Min. (Vorgabe)</span></div>
             <div class="field" style="justify-content:flex-end"><button type="button" class="btn-outline" data-act="dauer-eigen">Dauer selbst eintragen</button></div>`;
        felder = `${bearbeiten ? `<p class="muted small"><span class="art art-${M.edit.art}">${ART_TEXT[M.edit.art]}</span> ${M.edit.art === 'online' ? 'Online gebucht' : M.edit.art === 'treatwell' ? 'Über Treatwell gebucht – Änderungen bitte auch in Treatwell machen' : 'Telefonisch eingetragen'}</p>` : `<div class="field"><span class="sublabel">Woher kommt der Termin?</span>
          <div class="seg" role="group" aria-label="Woher kommt der Termin?">
            <button type="button" data-act="quelle" data-arg="telefon" aria-pressed="${f.quelle !== 'treatwell'}">Telefon / vor Ort</button>
            <button type="button" data-act="quelle" data-arg="treatwell" aria-pressed="${f.quelle === 'treatwell'}">Treatwell</button>
          </div></div>`}
        <div class="grid2">
          ${auswahlPerson('person', f.person)}
          <div class="field"><label for="f-leistung">Leistung</label><select id="f-leistung" name="leistung" data-change="leistung">${D.leistungen.map((l) => `<option value="${l.id}"${f.leistung === l.id ? ' selected' : ''}>${esc(l.voll)} (${l.min} Min.)</option>`).join('')}
            <option value="eigene"${eigene ? ' selected' : ''}>Andere Tätigkeit (selbst eintragen) …</option></select></div>
          ${eigene ? `<div class="full">${feld('taetigkeit', 'Was wird gemacht?', 'text', f.taetigkeit, ' maxlength="60" placeholder="z. B. Pflanzenfarbe + Schnitt" required')}</div>` : ''}
          ${feld('tag', 'Datum', 'date', f.tag, ' required')}
          ${feld('uhr', 'Uhrzeit', 'time', f.uhr, ' step="300" required')}
          ${dauerFeld}
          <p class="muted small full" id="ende-hinweis" aria-live="polite">${endeText(f.uhr, eigene || f.dauerEigen ? f.dauer || (vorgabe ? vorgabe.min : '') : vorgabe && vorgabe.min)}</p>
          <div class="full">
            ${bearbeiten ? feld('name', 'Name der Kundin bzw. des Kunden', 'text', f.name, ' autocomplete="off" maxlength="80" required') : f.kunde
              ? `<div class="note" style="align-items:center">${ic('users', 20)}<div style="flex:1"><b>${esc(f.kunde.name)} · Kundenkarte</b>
                  <span>${f.kunde.letzter ? 'Zuletzt: ' + besuchKurz(f.kunde.letzter) : 'Noch kein Besuch eingetragen'}</span></div>
                  ${f.kunde.letzter && f.kunde.letzter.leistungId ? `<button type="button" class="mini" data-act="gleiche-leistung">Gleiche Leistung</button>` : ''}
                  <button type="button" class="mini" data-act="kunde-loesen" aria-label="Kundenkarte nicht verwenden">Andere Person</button></div>
                <input type="hidden" name="name" value="${esc(f.name)}">`
              : `${feld('name', 'Name der Kundin bzw. des Kunden', 'text', f.name, ' autocomplete="off" maxlength="80" required aria-describedby="vorschlag-hinweis"')}
                <div id="vorschlaege" class="vorschlaege">${vorschlaegeHtml()}</div>
                <span class="muted small" id="vorschlag-hinweis">Beim Tippen erscheinen passende Kundenkarten.</span>`}
          </div>
          ${feld('tel', 'Telefon (freiwillig)', 'tel', f.tel, ' autocomplete="off" maxlength="25"')}
          ${feld('mail', 'E-Mail (freiwillig)', 'email', f.mail, ' autocomplete="off" maxlength="120"')}
        </div>
        ${bearbeiten ? (M.edit.art === 'treatwell' ? '' : `<label class="check"><input type="checkbox" name="mailSenden"${f.mailSenden ? ' checked' : ''}> Kundin bzw. Kunde per E-Mail über die Änderung informieren (nur wenn E-Mail angegeben)</label>`) : f.quelle === 'treatwell'
          ? '<p class="muted small">Treatwell schickt der Kundin Bestätigung und Erinnerung selbst. Von hier geht keine E-Mail raus.</p>'
          : `<label class="check"><input type="checkbox" name="mailSenden"${f.mailSenden ? ' checked' : ''}> Bestätigung per E-Mail schicken (nur wenn E-Mail angegeben; dann auch Erinnerung am Vortag)</label>`}
        ${f.kunde || bearbeiten ? '' : `<label class="check"><input type="checkbox" name="kartei"${f.kartei ? ' checked' : ''}> In der Kundenkartei speichern (Stammkundin bzw. Stammkunde)</label>`}`;
      } else {
        const gruende = ['Pause', 'Arzttermin', 'Frei', 'Urlaub', 'Krank', 'Sonstiges'];
        if (f.grund && !gruende.includes(f.grund)) gruende.unshift(f.grund); // z. B. Titel aus dem Google Kalender
        felder = `<div class="grid2">
          ${auswahlPerson('person', f.person, !bearbeiten)}
          <div class="field"><label for="f-grund">Was</label><select id="f-grund" name="grund">${gruende.map((g) => `<option${f.grund === g ? ' selected' : ''}>${g}</option>`).join('')}</select></div>
          <div class="full"><label class="check"><input type="checkbox" name="ganztags" data-change="ganztags"${f.ganztags ? ' checked' : ''}> Ganztägig (z. B. Urlaub, freier Tag)</label></div>
          ${f.ganztags
            ? `${feld('tag', 'Von', 'date', f.tag, ' required')}${feld('bisTag', 'Bis einschließlich', 'date', f.bisTag || f.tag, ' required')}`
            : `${feld('tag', 'Datum', 'date', f.tag, ' required')}<div></div>${feld('uhr', 'Von', 'time', f.uhr, ' step="300" required')}${feld('bis', 'Bis', 'time', f.bis, ' step="300" required')}`}
          <div class="field full"><label for="f-notiz">Notiz (freiwillig)</label><textarea id="f-notiz" name="notiz" maxlength="300">${esc(f.notiz || '')}</textarea></div>
        </div>`;
      }
      html = `${kopfDialog(bearbeiten ? 'Bearbeiten' : 'Eintragen')}${bearbeiten ? '' : reiter}
        <form class="stack" data-submit="neu" novalidate>${felder}${konfliktHtml()}${fehlerHtml()}
          <div class="knoepfe">${M.konflikt ? `<button type="button" class="btn-outline" data-act="trotzdem">${bearbeiten ? 'Trotzdem speichern' : 'Trotzdem eintragen'}</button>` : ''}
          <button type="submit" class="btn-primary"${M.sendet ? ' disabled aria-busy="true"' : ''}>${M.sendet ? 'Wird gespeichert …' : bearbeiten ? 'Speichern' : 'Eintragen'}</button></div>
        </form>`;
    } else if (M.typ === 'detail') {
      const t = termin(M.arg);
      if (!t) { dialogSchliessen(); return; }
      const kunde = istKunde(t);
      const zukunft = t.beginn > Date.now() / 1000;
      const zeilen = [
        ['Wann', esc(longDate(t.tag)) + '<br>' + (t.ganztags ? 'ganztägig' : esc(t.uhr) + '–' + esc(t.bis) + ' Uhr')],
        ['Bei', esc(personName(t.person))],
        ['Art', `<span class="art art-${t.art}">${ART_TEXT[t.art]}</span>${t.art === 'kalender' ? ' direkt im Google Kalender eingetragen' : ''}`],
      ];
      if (kunde) {
        zeilen.push(['Leistung', esc(t.leistung)]);
        zeilen.push(['Name', esc(t.name || (t.anonym ? 'nach 12 Monaten entfernt' : '–'))]);
        if (t.tel) zeilen.push(['Telefon', `<a href="tel:${esc(t.tel.replace(/[^\d+]/g, ''))}">${esc(t.tel)}</a>`]);
        if (t.mail) zeilen.push(['E-Mail', `<a href="mailto:${esc(t.mail)}">${esc(t.mail)}</a>`]);
        if (t.twBezahlt) zeilen.push(['Bezahlung', t.twBezahlt === 'Vorab bezahlt' ? '<b>Vorab über Treatwell bezahlt – im Salon nicht kassieren</b>' : esc(t.twBezahlt) + ' – im Salon kassieren']);
        if (t.twNr) zeilen.push(['Treatwell-Nr.', esc(t.twNr)]);
        if (t.art === 'treatwell') zeilen.push(['Hinweis', 'Über Treatwell gebucht. Verschieben oder Absagen bitte auch in Treatwell machen.']);
        else if (t.mail && zukunft) zeilen.push(['Erinnerung', t.erinnert ? 'verschickt' : 'wird am Vortag verschickt']);
      } else {
        zeilen.push(['Titel', esc(t.titel || '–')]);
        if (t.notiz) zeilen.push(['Notiz', esc(t.notiz)]);
        if (!t.blockiert) zeilen.push(['Hinweis', 'Als „Verfügbar“ markiert, blockiert die Online-Buchung nicht.']);
      }
      html = `${kopfDialog(kunde ? esc(t.name || 'Kundentermin') : esc(t.titel || 'Eintrag'))}
        <dl class="infozeilen">${zeilen.map(([a, b]) => `<dt>${a}</dt><dd>${b}</dd>`).join('')}</dl>
        ${M.fehler ? `<p class="err" role="alert">${esc(M.fehler)}</p>` : ''}
        <div class="knoepfe">
          ${t.tel ? `<a class="btn-outline" href="tel:${esc(t.tel.replace(/[^\d+]/g, ''))}">${ic('phone', 18)}Anrufen</a>` : ''}
          <button type="button" class="btn-outline" data-act="bearbeiten" data-arg="${esc(M.arg)}">Bearbeiten</button>
          ${kunde && t.kunde ? `<a class="btn-outline" href="#/kunde/${esc(t.kunde)}" data-act="schliessen-weiter">${ic('users', 18)}Kundenkarte</a>` : ''}
          ${kunde && !t.kunde && !t.anonym ? `<button type="button" class="btn-outline" data-act="als-stammkunde" data-arg="${esc(M.arg)}">${ic('users', 18)}Als Stammkunde speichern</button>` : ''}
          <button type="button" class="btn-danger" data-act="absagen" data-arg="${esc(M.arg)}">${kunde ? 'Termin absagen' : 'Eintrag löschen'}</button>
        </div>
        ${M.absagen ? `<div class="panel" style="background:#FDF6F5;border-color:#F0D2CE">
          <b>${kunde ? 'Termin wirklich absagen?' : 'Eintrag wirklich löschen?'}</b>
          ${t.art === 'treatwell' ? '<p class="small" style="margin-top:6px">Bitte den Termin auch in Treatwell stornieren, damit die Kundin informiert wird.</p>' : kunde && t.mail && zukunft ? `<label class="check" style="margin-top:6px"><input type="checkbox" id="absage-mail" checked> Kundin bzw. Kunde per E-Mail informieren</label>` : kunde && zukunft ? '<p class="muted small" style="margin-top:6px">Keine E-Mail hinterlegt: Bitte telefonisch Bescheid geben.</p>' : ''}
          <div class="knoepfe" style="margin-top:10px"><button type="button" class="btn-outline" data-act="absagen-nein">Doch nicht</button>
          <button type="button" class="btn-danger" data-act="absagen-ja"${M.sendet ? ' disabled aria-busy="true"' : ''}>${M.sendet ? 'Wird gelöscht …' : kunde ? 'Ja, absagen' : 'Ja, löschen'}</button></div>
        </div>` : ''}`;
    } else if (M.typ === 'verschieben') {
      const t = termin(M.arg);
      if (!t) { dialogSchliessen(); return; }
      const f = M.werte;
      html = `${kopfDialog('Verschieben')}
        <p class="muted">${esc(terminText(t))} · bisher ${esc(shortDate(t.tag))}, ${esc(t.uhr)} Uhr bei ${esc(personName(t.person))}</p>
        <form class="stack" data-submit="verschieben" novalidate>
          <div class="grid2">${auswahlPerson('nach', f.nach)}<div></div>${feld('tag', 'Neues Datum', 'date', f.tag, ' required')}${feld('uhr', 'Neue Uhrzeit', 'time', f.uhr, ' step="300" required')}${t.ganztags ? '' : feld('dauer', 'Dauer in Minuten', 'number', f.dauer, ' min="5" max="600" step="5" inputmode="numeric" required')}<p class="muted small" id="ende-hinweis" aria-live="polite" style="align-self:end">${endeText(f.uhr, f.dauer)}</p></div>
          ${t.art === 'treatwell' ? '<div class="note warn"><div><b>Treatwell-Termin</b><span>Bitte den Termin auch in Treatwell verschieben, damit die Kundin informiert wird.</span></div></div>' : t.mail && istKunde(t) ? `<label class="check"><input type="checkbox" name="mail"${f.mail ? ' checked' : ''}> Kundin bzw. Kunde per E-Mail informieren</label>` : istKunde(t) ? '<p class="muted small">Keine E-Mail hinterlegt: Bitte telefonisch Bescheid geben.</p>' : ''}
          ${konfliktHtml()}${fehlerHtml()}
          <div class="knoepfe">${M.konflikt ? '<button type="button" class="btn-outline" data-act="trotzdem">Trotzdem verschieben</button>' : ''}
          <button type="submit" class="btn-primary"${M.sendet ? ' disabled aria-busy="true"' : ''}>${M.sendet ? 'Wird verschoben …' : 'Verschieben'}</button></div>
        </form>`;
    } else if (M.typ === 'karte') {
      const f = M.werte;
      html = `${kopfDialog(f.id ? 'Kundenkarte bearbeiten' : 'Neue Kundenkarte')}
        <form class="stack" data-submit="karte" novalidate>
          ${feld('name', 'Name', 'text', f.name, ' autocomplete="off" maxlength="80" required')}
          <div class="grid2">${feld('tel', 'Telefon (freiwillig)', 'tel', f.tel, ' autocomplete="off" maxlength="25"')}${feld('mail', 'E-Mail (freiwillig)', 'email', f.mail, ' autocomplete="off" maxlength="120"')}</div>
          <div class="field"><label for="f-notiz">Notiz (freiwillig)</label><textarea id="f-notiz" name="notiz" maxlength="1000" placeholder="z. B. Farbrezeptur, Wünsche, Haartyp">${esc(f.notiz || '')}</textarea>
          <span class="muted small">Bitte keine Gesundheitsangaben wie Allergien oder Krankheiten eintragen.</span></div>
          ${fehlerHtml()}
          <button type="submit" class="btn-primary"${M.sendet ? ' disabled aria-busy="true"' : ''}>${M.sendet ? 'Wird gespeichert …' : 'Speichern'}</button>
        </form>`;
    } else if (M.typ === 'import') {
      if (!M.vorschau) {
        html = `${kopfDialog('Aus Excel importieren')}
          <form class="stack" data-submit="import-pruefen" novalidate>
            <p class="muted">Excel-Datei (.xlsx) oder CSV auswählen. Die erste Zeile sollte die Überschriften enthalten (Name, Telefon, E-Mail, Letzter Besuch, Letzte Leistung, Bei, Notiz) – so wie in der <a href="verwaltung.php?a=vorlage" download>Vorlage</a>. Vor dem Übernehmen siehst du eine Vorschau.</p>
            <div class="field"><label for="f-datei">Datei</label><input id="f-datei" name="datei" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required style="padding:10px;height:auto"></div>
            ${fehlerHtml()}
            <button type="submit" class="btn-primary"${M.sendet ? ' disabled aria-busy="true"' : ''}>${M.sendet ? 'Wird geprüft …' : 'Prüfen'}</button>
          </form>`;
      } else {
        const v = M.vorschau;
        const badge = { neu: '<span class="badge g">neu</span>', ergaenzen: '<span class="badge">wird ergänzt</span>', fehler: '<span class="st st-weg">Fehler</span>' };
        const zeilen = v.zeilen.slice(0, 300).map((z) => `<div class="bk"><div class="bk-top">
            <span class="bk-time" style="width:auto;min-width:40px" title="Zeile in der Datei">${z.zeile}</span>
            <div class="bk-main"><b>${esc(z.name || '(ohne Name)')} ${badge[z.status]}</b>
              <span>${[z.tel, z.mail].filter(Boolean).map(esc).join(' · ')}</span>
              ${z.datum ? `<span>Zuletzt: ${esc(z.datum)} · ${esc(z.leistung)}${z.personName ? ' bei ' + esc(z.personName) : ''}</span>` : ''}
              ${z.notiz ? `<span>Notiz: ${esc(z.notiz)}</span>` : ''}
              ${z.fehler ? `<span class="err">${esc(z.fehler)}</span>` : ''}${z.hinweis ? `<span style="color:#7A4B00">${esc(z.hinweis)}</span>` : ''}</div></div></div>`).join('');
        const anzahl = v.neu + v.ergaenzen;
        html = `${kopfDialog('Vorschau')}
          <div class="stats" style="margin:0"><div><b>${v.neu}</b><span>neue Karten</span></div><div><b>${v.ergaenzen}</b><span>schon vorhanden, werden ergänzt</span></div><div><b>${v.fehler}</b><span>Zeilen mit Fehlern (werden übersprungen)</span></div></div>
          ${v.abgeschnitten ? '<p class="err">Es werden höchstens 3000 Zeilen auf einmal eingelesen.</p>' : ''}
          <p class="muted small">Vorhandene Karten werden nur ergänzt: Leere Felder werden gefüllt, Notizen angehängt, nichts überschrieben.</p>
          <div style="max-height:45vh;overflow:auto;border:1px solid var(--line);border-radius:12px;padding:0 12px">${zeilen || '<p class="muted">Keine Zeilen gefunden.</p>'}${v.zeilen.length > 300 ? `<p class="muted small">… und ${v.zeilen.length - 300} weitere Zeilen.</p>` : ''}</div>
          ${fehlerHtml()}
          <div class="knoepfe">
            <button type="button" class="btn-outline" data-act="import-zurueck">Andere Datei</button>
            <button type="button" class="btn-primary" data-act="import-uebernehmen"${!anzahl || M.sendet ? ' disabled' : ''}${M.sendet ? ' aria-busy="true"' : ''}>${M.sendet ? 'Wird übernommen …' : anzahl + ' Karte' + (anzahl === 1 ? '' : 'n') + ' übernehmen'}</button>
          </div>`;
      }
    } else if (M.typ === 'besuch') {
      const f = M.werte;
      html = `${kopfDialog('Besuch nachtragen')}
        <p class="muted">Für Besuche von früher, zum Beispiel von der Papierkarte.</p>
        <form class="stack" data-submit="besuch" novalidate>
          <div class="grid2">
            ${feld('tag', 'Datum', 'date', f.tag, ' required max="' + keyOf(new Date()) + '"')}
            ${auswahlPerson('person', f.person)}
            <div class="field full"><label for="f-leistung">Leistung</label><select id="f-leistung" name="leistung" data-change="leistung">${D.leistungen.map((l) => `<option value="${l.id}"${f.leistung === l.id ? ' selected' : ''}>${esc(l.voll)}</option>`).join('')}
              <option value="eigene"${f.leistung === 'eigene' ? ' selected' : ''}>Andere Tätigkeit …</option></select></div>
            ${f.leistung === 'eigene' ? `<div class="full">${feld('taetigkeit', 'Was wurde gemacht?', 'text', f.taetigkeit, ' maxlength="60" required')}</div>` : ''}
          </div>
          ${fehlerHtml()}
          <button type="submit" class="btn-primary"${M.sendet ? ' disabled aria-busy="true"' : ''}>${M.sendet ? 'Wird gespeichert …' : 'Nachtragen'}</button>
        </form>`;
    }
    dlg.innerHTML = `<div class="modal-in">${html}</div>`;
  }

  function vorschlaegeHtml() {
    const v = M && M.vorschlaege;
    if (!v || !v.length) return '';
    return `<span class="sublabel">Kundenkarten</span>` + v.map((k, i) => `<button type="button" class="wk-item" data-act="kunde-waehlen" data-arg="${i}"><b style="width:auto">${esc(k.name)}</b><span class="muted">${esc(k.tel || k.mail || '')}${k.letzter ? ' · zuletzt ' + esc(shortDate(k.letzter.tag)) : ''}</span></button>`).join('');
  }

  let vorschlagTimer = null;
  function vorschlaegeSuchen(q) {
    clearTimeout(vorschlagTimer);
    if (q.trim().length < 2) { M.vorschlaege = []; const el = dlg.querySelector('#vorschlaege'); if (el) el.innerHTML = ''; return; }
    vorschlagTimer = setTimeout(async () => {
      try {
        const r = await api('kunden', { q });
        if (!M || M.typ !== 'neu') return;
        M.vorschlaege = r.kunden.slice(0, 5);
        const el = dlg.querySelector('#vorschlaege');
        if (el) el.innerHTML = vorschlaegeHtml();
      } catch (e) { /* Vorschläge sind nur eine Hilfe */ }
    }, 250);
  }

  function formWerte(form) {
    const w = {};
    Array.from(form.elements).forEach((el) => {
      if (!el.name) return;
      w[el.name] = el.type === 'checkbox' ? el.checked : el.value.trim();
    });
    return w;
  }

  async function neuSenden(trotzdem) {
    const form = dlg.querySelector('form[data-submit="neu"]');
    Object.assign(M.werte, formWerte(form));
    const f = M.werte;
    M.felder = {}; M.fehler = ''; M.sendet = true;
    if (!trotzdem) M.konflikt = null;
    dialogZeichnen();
    if (M.edit) {
      // Bestehenden Eintrag ändern
      try {
        const body = f.art === 'termin'
          ? { von: M.edit.person, id: M.edit.id, person: f.person, tag: f.tag, uhr: f.uhr, leistung: f.leistung, taetigkeit: f.taetigkeit,
            dauer: f.leistung === 'eigene' || f.dauerEigen ? f.dauer : '', name: f.name, tel: f.tel, mail: f.mail,
            informieren: !!f.mailSenden && M.edit.art !== 'treatwell', trotzdem: !!trotzdem }
          : { von: M.edit.person, id: M.edit.id, person: f.person, tag: f.tag, bisTag: f.bisTag, ganztags: !!f.ganztags, uhr: f.uhr, bis: f.bis, grund: f.grund, notiz: f.notiz };
        const r = await api('aendern', {}, body);
        dialogSchliessen();
        toast('Gespeichert' + (r.gemailt ? ', E-Mail an die Kundin verschickt.' : '.'));
        laden(true);
      } catch (e) {
        M.sendet = false;
        M.fehler = e.message;
        M.felder = (e.data && e.data.felder) || {};
        M.konflikt = e.status === 409 && e.data && e.data.konflikt ? e.data.konflikt : null;
        dialogZeichnen();
      }
      return;
    }
    const personen = f.art === 'block' && f.person === 'beide' ? D.team.map((p) => p.id) : [f.person];
    try {
      let gemailt = false;
      for (const person of personen) {
        const body = f.art === 'termin'
          ? { art: 'termin', person, tag: f.tag, uhr: f.uhr, leistung: f.leistung, taetigkeit: f.taetigkeit, dauer: f.leistung === 'eigene' || f.dauerEigen ? f.dauer : '', name: f.name, tel: f.tel, mail: f.mail, quelle: f.quelle, mailSenden: f.mailSenden && !!f.mail && f.quelle !== 'treatwell', kundeId: f.kunde ? f.kunde.id : '', kartei: !f.kunde && !!f.kartei, trotzdem: !!trotzdem }
          : { art: 'block', person, tag: f.tag, bisTag: f.bisTag, ganztags: !!f.ganztags, uhr: f.uhr, bis: f.bis, grund: f.grund, notiz: f.notiz };
        const r = await api('eintragen', {}, body);
        gemailt = gemailt || r.gemailt;
      }
      const ziel = '#/tag/' + f.tag;
      dialogSchliessen();
      toast(f.art === 'termin' ? 'Termin eingetragen' + (gemailt ? ', Bestätigung verschickt.' : '.') : 'Eingetragen. Die Zeit ist online nicht buchbar.');
      if (location.hash !== ziel && route().view === 'tag') location.hash = ziel; else laden(true);
    } catch (e) {
      M.sendet = false;
      M.fehler = e.message;
      M.felder = (e.data && e.data.felder) || {};
      M.konflikt = e.status === 409 && e.data && e.data.konflikt ? e.data.konflikt : null;
      dialogZeichnen();
    }
  }

  async function verschiebenSenden(trotzdem) {
    const form = dlg.querySelector('form[data-submit="verschieben"]');
    Object.assign(M.werte, formWerte(form));
    const t = termin(M.arg);
    const f = M.werte;
    M.fehler = ''; M.sendet = true;
    if (!trotzdem) M.konflikt = null;
    dialogZeichnen();
    try {
      const r = await api('verschieben', {}, { person: t.person, id: t.id, nach: f.nach, tag: f.tag, uhr: f.uhr, dauer: f.dauer, mail: !!f.mail, trotzdem: !!trotzdem });
      dialogSchliessen();
      toast('Verschoben' + (r.gemailt ? ', E-Mail verschickt.' : '.'));
      laden(true);
    } catch (e) {
      M.sendet = false;
      M.fehler = e.message;
      M.konflikt = e.status === 409 && e.data && e.data.konflikt ? e.data.konflikt : null;
      dialogZeichnen();
    }
  }

  let toastTimer = null;
  function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 4000);
  }

  function neuDialog(vorgaben) {
    const r = route();
    const tag = r.datum || keyOf(new Date());
    dialogOeffnen({
      typ: 'neu',
      werte: Object.assign({ art: 'termin', person: D ? D.team[0].id : 'andrea', tag, uhr: '', bis: '', leistung: D ? D.leistungen[0].id : '', name: '', tel: '', mail: '', mailSenden: true, dauerEigen: false, dauer: '', taetigkeit: '', kunde: null, kartei: true, quelle: 'telefon', grund: 'Pause', ganztags: false, notiz: '' }, vorgaben || {}),
      felder: {},
    });
  }

  /* ---------- Aktionen ---------- */
  const ACT = {
    neu: () => { if (D) neuDialog(); },
    'neu-klick': (person, el, ev) => {
      const rect = el.getBoundingClientRect();
      const min = 7 * 60 + Math.floor(((ev.clientY - rect.top) / 52) * 60 / 15) * 15;
      neuDialog({ person, uhr: fmt(Math.min(Math.max(min, 7 * 60), 20 * 60)) });
    },
    'neu-art': (art) => { Object.assign(M.werte, formWerte(dlg.querySelector('form'))); M.werte.art = art; M.konflikt = null; M.fehler = ''; M.felder = {}; dialogZeichnen(); },
    'dauer-eigen': () => { Object.assign(M.werte, formWerte(dlg.querySelector('form'))); const l = D.leistungen.find((x) => x.id === M.werte.leistung); M.werte.dauerEigen = true; M.werte.dauer = l ? l.min : ''; dialogZeichnen(); const el = dlg.querySelector('#f-dauer'); if (el) { el.focus(); el.select(); } },
    'hinweis-erledigt': async (id) => {
      try { const r = await api('hinweis-erledigt', {}, { id }); D.treatwell = r.treatwell; render(); } catch (e) { toast(e.message); }
    },
    bearbeiten: (arg) => {
      const t = termin(arg);
      if (!t) return;
      const edit = { person: t.person, id: t.id, art: t.art };
      const minuten = Math.round((t.ende - t.beginn) / 60);
      if (istKunde(t)) {
        const l = D.leistungen.find((x) => x.id === t.leistungId);
        neuDialog({ art: 'termin', person: t.person, tag: t.tag, uhr: t.uhr, leistung: l ? l.id : 'eigene', taetigkeit: l ? '' : t.leistung,
          dauerEigen: !l || l.min !== minuten, dauer: minuten, name: t.name, tel: t.tel, mail: t.mail, mailSenden: false, kartei: false });
      } else {
        // Ganztägig: Google speichert das Ende als Folgetag, angezeigt wird der letzte Tag
        const bisTag = t.ganztags ? keyOf(new Date((t.ende - 1) * 1000)) : t.tag;
        neuDialog({ art: 'block', person: t.person, tag: t.tag, bisTag, ganztags: t.ganztags, uhr: t.uhr, bis: t.bis, grund: t.titel || 'Sonstiges', notiz: t.notiz });
      }
      M.edit = edit;
      dialogZeichnen();
    },
    quelle: (q) => { Object.assign(M.werte, formWerte(dlg.querySelector('form'))); M.werte.quelle = q; dialogZeichnen(); },
    'dauer-vorgabe': () => { Object.assign(M.werte, formWerte(dlg.querySelector('form'))); M.werte.dauerEigen = false; M.werte.dauer = ''; dialogZeichnen(); },
    detail: (arg) => dialogOeffnen({ typ: 'detail', arg }),
    'such-detail': (arg) => dialogOeffnen({ typ: 'detail', arg, liste: suche.treffer }),
    schliessen: () => dialogSchliessen(),
    verschieben: (arg) => {
      const t = termin(arg);
      const liste = M && M.liste;
      dialogOeffnen({ typ: 'verschieben', arg, liste, werte: { nach: t.person, tag: t.tag, uhr: t.uhr, dauer: Math.round((t.ende - t.beginn) / 60), mail: !!t.mail } });
    },
    absagen: () => { M.absagen = true; dialogZeichnen(); const b = dlg.querySelector('[data-act="absagen-ja"]'); if (b) b.focus(); },
    'absagen-nein': () => { M.absagen = false; dialogZeichnen(); },
    'absagen-ja': async () => {
      const t = termin(M.arg);
      const mail = !!(dlg.querySelector('#absage-mail') || {}).checked;
      M.sendet = true; M.fehler = '';
      dialogZeichnen();
      try {
        const r = await api('absagen', {}, { person: t.person, id: t.id, mail });
        dialogSchliessen();
        toast((istKunde(t) ? 'Termin abgesagt' : 'Eintrag gelöscht') + (r.gemailt ? ', E-Mail verschickt.' : '.'));
        if (suche.treffer) suche.treffer = suche.treffer.filter((x) => !(x.person === t.person && x.id === t.id));
        laden(true);
      } catch (e) {
        M.sendet = false; M.fehler = e.message;
        dialogZeichnen();
      }
    },
    trotzdem: () => (M.typ === 'neu' ? neuSenden(true) : verschiebenSenden(true)),
    pause: async (person) => {
      const neu = !D.pausiert[person];
      try {
        const r = await api('pause', {}, { person, pausiert: neu });
        D.pausiert = r.pausiert;
        render();
        toast(personName(person) + (neu ? ': Online-Buchung pausiert.' : ': wieder online buchbar.'));
      } catch (e) { toast(e.message); }
    },
    logout: async () => {
      try { await api('logout', {}, {}); } catch (e) { /* egal */ }
      angemeldet = false; D = null;
      render();
    },
    'neu-laden': () => laden(),

    /* Kundenkartei */
    'kunde-waehlen': (i) => {
      const k = M.vorschlaege && M.vorschlaege[Number(i)];
      if (!k) return;
      Object.assign(M.werte, formWerte(dlg.querySelector('form')));
      M.werte.kunde = k;
      M.werte.name = k.name;
      if (k.tel) M.werte.tel = k.tel;
      if (k.mail) M.werte.mail = k.mail;
      M.vorschlaege = [];
      dialogZeichnen();
    },
    'kunde-loesen': () => {
      Object.assign(M.werte, formWerte(dlg.querySelector('form')));
      M.werte.kunde = null; M.werte.name = ''; M.werte.tel = ''; M.werte.mail = '';
      dialogZeichnen();
      const el = dlg.querySelector('#f-name'); if (el) el.focus();
    },
    'gleiche-leistung': () => {
      Object.assign(M.werte, formWerte(dlg.querySelector('form')));
      M.werte.leistung = M.werte.kunde.letzter.leistungId;
      M.werte.dauerEigen = false; M.werte.dauer = '';
      dialogZeichnen();
      toast('Leistung vom letzten Mal übernommen.');
    },
    'karte-neu': () => dialogOeffnen({ typ: 'karte', werte: { id: '', name: kartei.q && !/\d/.test(kartei.q) ? kartei.q : '', tel: '', mail: '', notiz: '' }, felder: {} }),
    'karte-bearbeiten': () => { const k = karte.daten; dialogOeffnen({ typ: 'karte', werte: { id: k.id, name: k.name, tel: k.tel, mail: k.mail, notiz: k.notiz }, felder: {} }); },
    'karte-termin': () => {
      const k = karte.daten;
      neuDialog({ tag: keyOf(new Date()), kunde: k, name: k.name, tel: k.tel, mail: k.mail,
        leistung: k.letzter && k.letzter.leistungId ? k.letzter.leistungId : (D ? D.leistungen[0].id : ''),
        person: k.letzter && k.letzter.person ? k.letzter.person : (D ? D.team[0].id : 'andrea') });
    },
    'karte-loeschen': async () => {
      const k = karte.daten;
      if (!window.confirm('Die Kundenkarte von ' + k.name + ' mit allen Notizen und Besuchen löschen? Termine im Kalender bleiben bestehen.')) return;
      try {
        await api('kunde-loeschen', {}, { id: k.id });
        toast('Kundenkarte gelöscht.');
        karte.daten = null; kartei.liste = null;
        location.hash = '#/kunden';
      } catch (e) { toast(e.message); }
    },
    'besuch-neu': () => {
      const k = karte.daten;
      dialogOeffnen({ typ: 'besuch', werte: { tag: '', person: (k.letzter && k.letzter.person) || (D ? D.team[0].id : 'andrea'), leistung: D ? D.leistungen[0].id : '', taetigkeit: '' }, felder: {} });
    },
    'besuch-weg': async (id) => {
      if (!window.confirm('Diesen nachgetragenen Besuch entfernen?')) return;
      try {
        await api('besuch-entfernen', {}, { id: karte.daten.id, besuch: id });
        karteLaden(karte.daten.id);
      } catch (e) { toast(e.message); }
    },
    'als-stammkunde': async (arg) => {
      const t = termin(arg);
      try {
        const r = await api('zuordnen', {}, { person: t.person, termin: t.id });
        dialogSchliessen();
        toast('Kundenkarte angelegt.');
        location.hash = '#/kunde/' + r.kundeId;
      } catch (e) { M.fehler = e.message; dialogZeichnen(); }
    },
    'schliessen-weiter': (arg, el) => { dialogSchliessen(); location.hash = el.getAttribute('href'); },

    /* Excel-Import */
    import: () => dialogOeffnen({ typ: 'import', vorschau: null }),
    'import-zurueck': () => { M.vorschau = null; M.fehler = ''; dialogZeichnen(); },
    'import-uebernehmen': async () => {
      M.sendet = true; M.fehler = '';
      dialogZeichnen();
      try {
        const r = await api('import-uebernehmen', {}, { zeilen: M.vorschau.zeilen.filter((z) => z.status !== 'fehler') });
        dialogSchliessen();
        toast(`Übernommen: ${r.neu} neu, ${r.ergaenzt} ergänzt.`);
        kartei.liste = null;
        karteiLaden();
      } catch (e) {
        M.sendet = false; M.fehler = e.message;
        dialogZeichnen();
      }
    },
  };

  function klick(ev, root) {
    const el = ev.target.closest('[data-act]');
    if (!el || !root.contains(el)) return;
    const fn = ACT[el.dataset.act];
    if (fn) { ev.preventDefault(); fn(el.dataset.arg, el, ev); }
  }
  app.addEventListener('click', (ev) => klick(ev, app));
  dlg.addEventListener('click', (ev) => {
    if (ev.target === dlg) { dialogSchliessen(); return; } // Klick auf den Hintergrund
    klick(ev, dlg);
  });
  app.addEventListener('change', (ev) => {
    const el = ev.target;
    if (el.dataset.change === 'datum' && el.value) location.hash = '#/tag/' + el.value;
  });
  // Kartei-Suche: beim Tippen filtern
  let karteiTimer = null;
  app.addEventListener('input', (ev) => {
    if (ev.target.dataset.input !== 'kartei') return;
    kartei.q = ev.target.value;
    clearTimeout(karteiTimer);
    karteiTimer = setTimeout(karteiLaden, 200);
  });
  dlg.addEventListener('input', (ev) => {
    if (M && M.typ === 'neu' && ev.target.name === 'name' && ev.target.type === 'text') vorschlaegeSuchen(ev.target.value);
    if (!['uhr', 'dauer'].includes(ev.target.name)) return;
    const form = ev.target.form, hinweis = dlg.querySelector('#ende-hinweis');
    if (!form || !hinweis) return;
    const w = formWerte(form);
    const vorgabe = D.leistungen.find((l) => l.id === w.leistung);
    hinweis.textContent = endeText(w.uhr, w.dauer !== undefined ? w.dauer : vorgabe && vorgabe.min);
  });
  dlg.addEventListener('change', (ev) => {
    const el = ev.target;
    if (el.dataset.change === 'ganztags' || el.dataset.change === 'leistung') {
      Object.assign(M.werte, formWerte(el.form));
      if (el.dataset.change === 'leistung' && M.werte.leistung !== 'eigene' && !M.werte.dauerEigen) M.werte.dauer = '';
      dialogZeichnen();
      const n = dlg.querySelector(el.dataset.change === 'leistung' && M.werte.leistung === 'eigene' ? '#f-taetigkeit' : '#f-' + el.name);
      if (n) n.focus();
    }
  });
  app.addEventListener('submit', async (ev) => {
    const form = ev.target;
    ev.preventDefault();
    if (form.dataset.submit === 'login') {
      const btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      try {
        await api('login', {}, { passwort: form.elements.namedItem('passwort').value });
        angemeldet = true;
        render();
        laden();
        treatwellAbruf();
      } catch (e) {
        document.getElementById('login-fehler').textContent = e.message;
        btn.disabled = false;
        form.elements.namedItem('passwort').select();
      }
    } else if (form.dataset.submit === 'suche') {
      suche.q = form.elements.namedItem('q').value.trim();
      suche.laedt = true; suche.fehler = ''; render();
      try {
        suche.treffer = (await api('suche', { q: suche.q })).treffer;
      } catch (e) { suche.fehler = e.message; suche.treffer = null; }
      suche.laedt = false;
      render();
      const inp = document.getElementById('such-q');
      if (inp) inp.focus();
    }
  });
  dlg.addEventListener('submit', (ev) => {
    ev.preventDefault();
    if (M && M.sendet) return;
    if (ev.target.dataset.submit === 'neu') neuSenden(false);
    if (ev.target.dataset.submit === 'verschieben') verschiebenSenden(false);
    if (ev.target.dataset.submit === 'karte' || ev.target.dataset.submit === 'besuch') karteiSenden(ev.target);
    if (ev.target.dataset.submit === 'import-pruefen') importPruefen(ev.target);
  });

  async function importPruefen(form) {
    const datei = form.elements.namedItem('datei').files[0];
    if (!datei) { M.fehler = 'Bitte eine Datei auswählen.'; dialogZeichnen(); return; }
    M.sendet = true; M.fehler = '';
    dialogZeichnen();
    try {
      const fd = new FormData();
      fd.append('datei', datei);
      const res = await fetch('verwaltung.php?a=import-pruefen', { method: 'POST', body: fd, headers: { 'X-Verwaltung': '1' }, credentials: 'same-origin' });
      let data = {};
      try { data = await res.json(); } catch (e) { /* leer */ }
      if (res.status === 401) { angemeldet = false; dialogSchliessen(); render(); return; }
      if (!res.ok) throw new Error(data.fehler || 'Die Datei konnte nicht gelesen werden.');
      M.vorschau = data;
    } catch (e) {
      M.fehler = e.message;
    }
    M.sendet = false;
    dialogZeichnen();
  }

  async function karteiSenden(form) {
    Object.assign(M.werte, formWerte(form));
    const f = M.werte;
    M.sendet = true; M.fehler = ''; M.felder = {};
    dialogZeichnen();
    try {
      if (M.typ === 'karte') {
        const r = await api('kunde-speichern', {}, { id: f.id, name: f.name, tel: f.tel, mail: f.mail, notiz: f.notiz });
        dialogSchliessen();
        toast(f.id ? 'Gespeichert.' : 'Kundenkarte angelegt.');
        kartei.liste = null;
        if (location.hash === '#/kunde/' + r.id) karteLaden(r.id); else location.hash = '#/kunde/' + r.id;
      } else {
        await api('besuch-nachtragen', {}, { id: karte.daten.id, tag: f.tag, person: f.person, leistung: f.leistung === 'eigene' ? '' : f.leistung, taetigkeit: f.taetigkeit });
        dialogSchliessen();
        toast('Besuch nachgetragen.');
        karteLaden(karte.daten.id);
      }
    } catch (e) {
      M.sendet = false; M.fehler = e.message; M.felder = (e.data && e.data.felder) || {};
      dialogZeichnen();
    }
  }
  window.addEventListener('hashchange', () => { window.scrollTo(0, 0); laden(); });
  // Alle 60 Sekunden still neu laden, wenn das Fenster sichtbar ist und kein Dialog offen ist
  setInterval(() => { if (angemeldet && !M && document.visibilityState === 'visible' && !['kunden', 'kunde'].includes(route().view)) laden(true); }, 60000);
  setInterval(() => { if (angemeldet && document.visibilityState === 'visible') treatwellAbruf(); }, 120000);

  // Neue Treatwell-E-Mails abholen lassen (der Server prüft höchstens alle 2 Minuten)
  async function treatwellAbruf() {
    if (!D || !D.treatwell || !D.treatwell.aktiv) { if (D) return; }
    try {
      const r = await api('treatwell-abruf', {}, {});
      if (D) D.treatwell = r.treatwell;
      if (r.neu > 0) { toast(r.neu === 1 ? '1 Treatwell-Buchung übernommen.' : r.neu + ' Treatwell-Buchungen übernommen.'); laden(true); } else if (!M) render();
    } catch (e) { /* nicht schlimm, nächstes Mal wieder */ }
  }
  document.addEventListener('visibilitychange', () => { if (angemeldet && !M && document.visibilityState === 'visible') laden(true); });

  (async function start() {
    try {
      const s = await api('status');
      angemeldet = s.angemeldet; demo = s.demo;
    } catch (e) {
      app.innerHTML = `<div class="loginbox"><h1 style="font-size:22px">Dashboard nicht erreichbar</h1><p>${esc(e.message)}</p></div>`;
      return;
    }
    render();
    if (angemeldet) { laden(); treatwellAbruf(); }
  })();
})();
