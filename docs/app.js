/* TreatBetter – eigenständiger Entwurf für Friseur Andrea Zechmeister.
   Läuft komplett im Browser. Testbuchungen und geänderte Einstellungen
   liegen nur im localStorage dieses Browsers; nichts wird verschickt. */
(function () {
  'use strict';

  /* ---------- Hilfsfunktionen ---------- */
  const pad = (n) => String(n).padStart(2, '0');
  const toMin = (s) => { const p = String(s).split(':'); return Number(p[0]) * 60 + Number(p[1]); };
  const fmt = (m) => pad(Math.floor(m / 60)) + ':' + pad(m % 60);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const DOW = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  const DOW_LONG = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
  const MON = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
  const MON_S = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'];
  const dayStart = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
  const at = (d, min) => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, min);
  const keyOf = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const fromKey = (k) => { const p = k.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
  const today = () => dayStart(new Date());
  const nowMin = () => { const n = new Date(); return n.getHours() * 60 + n.getMinutes(); };
  const dayDiff = (a, b) => Math.round((dayStart(b) - dayStart(a)) / 864e5);
  const shortDate = (d) => DOW[d.getDay()] + ', ' + d.getDate() + '. ' + MON_S[d.getMonth()];
  const longDate = (d) => DOW_LONG[d.getDay()] + ', ' + d.getDate() + '. ' + MON[d.getMonth()];
  const euro = (n) => (Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',')) + ' €';
  const durText = (m) => (m < 60 ? m + ' Min.' : m % 60 ? Math.floor(m / 60) + ' Std. ' + (m % 60) + ' Min.' : m / 60 + ' Std.');
  // Gerade/ungerade Kalenderwoche, damit sich die Beispielwochen etwas unterscheiden
  const weekParity = (d) => {
    const days = Math.floor(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) / 864e5);
    return Math.floor((days - 4) / 7) % 2 === 0 ? 'even' : 'odd';
  };

  const ICONS = {
    pin: '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    cash: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5"/>',
    user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3 2.8-4.8 5.5-4.8s4.9 1.8 5.5 4.8"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.4c2.4.2 4.1 1.8 4.6 4.1"/>',
    cal: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    back: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    left: '<path d="M15 6l-6 6 6 6"/>',
    right: '<path d="M9 6l6 6-6 6"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    sync: '<path d="M20 11a8 8 0 0 0-14.5-4.5L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.5 4.5L20 16"/><path d="M20 20v-4h-4"/>',
    store: '<path d="M4 10v10h16V10"/><path d="M3 10l2-6h14l2 6"/><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M10 20v-5h4v5"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    list: '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    scissors: '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><path d="M8 7.5L20 18M8 16.5L20 6"/>',
    sliders: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    ext: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    shield: '<path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6z"/>',
  };
  const ic = (name, size, sw) => '<svg width="' + (size || 20) + '" height="' + (size || 20) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 1.8) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';

  /* ---------- Stammdaten (Treatwell-Profil und Schaufenster des Salons) ---------- */
  const SALON = {
    name: 'Friseur Andrea Zechmeister',
    street: 'August-Jaksch-Straße 1',
    city: '9020 Klagenfurt',
    phones: [['0463 51 59 52', '+43463515952'], ['0676 460 48 83', '+436764604883']],
    maps: 'https://www.google.com/maps/search/?api=1&query=August-Jaksch-Stra%C3%9Fe+1%2C+9020+Klagenfurt',
  };

  const SERVICES = [
    { id: 'damen-trocken', name: 'Damen – Trockenhaarschnitt', min: 25, price: 27, cats: ['beliebt', 'damen'] },
    { id: 'lotion', name: 'Damen – Pflege Lotion', min: 5, price: 7.5, cats: ['beliebt', 'pflege'] },
    { id: 'herren-trocken', name: 'Herren – Trockenhaarschnitt', min: 20, price: 27, cats: ['beliebt', 'herren'] },
    { id: 'kinder', name: 'Kinder bis 10 Jahre – Haarschnitt', min: 20, price: 14, cats: ['beliebt', 'kinder'] },
    { id: 'haarmaske', name: 'Damen – Haarmaske', min: 15, price: 16, cats: ['beliebt', 'pflege'] },
    { id: 'waschen-legen', name: 'Damen – Waschen & Legen', min: 30, price: 31, cats: ['damen'] },
    { id: 'waschen-foehnen', name: 'Damen – Waschen & Föhnen', min: 30, price: 34, cats: ['damen'] },
    { id: 'wsf', name: 'Damen – Waschen, Schneiden & Föhnen', min: 60, price: 61, cats: ['damen'] },
  ];
  const svc = (id) => SERVICES.find((s) => s.id === id);
  const CATS = [['beliebt', 'Beliebt'], ['damen', 'Damen'], ['herren', 'Herren'], ['kinder', 'Kinder'], ['pflege', 'Pflege']];
  const GROUPS = ['Damen', 'Herren', 'Kinder & Jugend', 'Haarkuren & Pflege', 'Culum Natura Pflanzenfarbe', 'Haarverlängerung'];

  const STAFF = [
    { id: 'andrea', name: 'Andrea', role: 'Friseurmeisterin · Inhaberin', initial: 'A', bg: '#EBDDEE', fg: '#4E1F53', synced: 'vor 1 Min.',
      calendars: [['kalender', 'Kalender', true], ['salon', 'Salon-Termine', true], ['geb', 'Geburtstage', false]] },
    { id: 'pamela', name: 'Pamela', role: 'Friseurin', initial: 'P', bg: '#DCEBE3', fg: '#17573A', synced: 'vor 3 Min.',
      calendars: [['kalender', 'Kalender', true], ['geb', 'Geburtstage', false]] },
  ];
  const staffById = (id) => STAFF.find((p) => p.id === id) || { id, name: id, initial: '?' };

  /* ---------- Beispiel-Kalender ----------
     Je Person und Wochentag (1 = Montag). booking = über die Seite gebucht,
     busy = belegt laut Google Kalender (show: busy = Beschäftigt, tentative = Einladung mit „Vielleicht“, oof = Abwesend). */
  const B = (s, e, show, extra) => Object.assign({ kind: 'busy', s: toMin(s), e: toMin(e), show: show || 'busy' }, extra);
  const K = (s, who, id, extra) => {
    const x = Object.assign({ min: svc(id).min, label: svc(id).name, price: svc(id).price }, extra);
    return Object.assign({ kind: 'booking', s: toMin(s), e: toMin(s) + x.min, who, svc: id }, x);
  };
  const ALLDAY = (show, extra) => Object.assign({ kind: 'busy', s: 0, e: 24 * 60, show, allDay: true }, extra);
  const TEMPLATE = {
    andrea: {
      1: [B('08:30', '09:30'), K('11:00', 'S. Pichler', 'damen-trocken')],
      2: [K('08:30', 'K. Berger', 'damen-trocken'), K('09:00', 'M. Gruber', 'damen-trocken', { noshow: true }), B('10:00', '11:30'),
        B('12:00', '12:45'), K('13:30', 'T. Wagner', 'herren-trocken'), B('15:00', '16:30'), B('17:30', '18:00', 'tentative')],
      3: [B('08:30', '10:00'), K('10:30', 'R. Steiner', 'wsf'), B('12:00', '12:30')],
      4: [B('09:00', '10:00'), B('12:00', '12:45'), B('14:00', '19:00', 'oof')],
      5: [K('07:30', 'A. Kofler', 'herren-trocken', { weeks: 'even' }), B('09:00', '10:00', 'busy', { weeks: 'even' }),
        B('12:00', '13:00', 'busy', { weeks: 'even' }), K('16:00', 'J. Moser', 'waschen-foehnen', { weeks: 'even' }),
        B('18:00', '20:00', 'busy', { weeks: 'even' }), ALLDAY('oof', { weeks: 'odd' })],
    },
    pamela: {
      1: [B('08:30', '09:00'), B('11:00', '12:30')],
      2: [K('09:00', 'L. Hofer', 'damen-trocken', { label: 'Damen – Trockenhaarschnitt + Pflege Lotion', price: 34.5, min: 30 }),
        B('10:15', '11:00'), B('12:30', '13:00'), K('14:00', 'F. Novak', 'kinder'), B('16:00', '19:00', 'tentative')],
      3: [B('08:30', '10:00'), B('11:30', '12:00')],
      4: [B('10:00', '11:30'), K('13:00', 'E. Huber', 'haarmaske'), B('15:00', '16:00')],
      5: [B('07:30', '09:00'), K('10:00', 'N. Wolf', 'waschen-legen'), B('12:00', '12:45'), B('17:00', '18:30')],
    },
  };
  const BLOCKERS = [['busy', 'Normale Termine („Beschäftigt“)'], ['oof', 'Abwesend'], ['tentative', 'Einladungen mit Antwort „Vielleicht“'], ['elsewhere', 'Termine, die als „Verfügbar“ markiert sind'], ['allDay', 'Ganztägige Termine, z. B. Urlaub']];
  const SHOW_LABEL = { busy: 'Belegt', oof: 'Abwesend', tentative: 'Vielleicht', elsewhere: 'Verfügbar' };

  /* ---------- Österreichische Feiertage ---------- */
  function easter(y) {
    const a = y % 19, b = Math.floor(y / 100), c = y % 100, d = Math.floor(b / 4), e = b % 4;
    const f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
    const n = h + l - 7 * m + 114;
    return new Date(y, Math.floor(n / 31) - 1, (n % 31) + 1);
  }
  const holidayCache = {};
  function holidays(y) {
    if (holidayCache[y]) return holidayCache[y];
    const map = {};
    [['01-01', 'Neujahr'], ['01-06', 'Heilige Drei Könige'], ['05-01', 'Staatsfeiertag'], ['08-15', 'Mariä Himmelfahrt'],
      ['10-26', 'Nationalfeiertag'], ['11-01', 'Allerheiligen'], ['12-08', 'Mariä Empfängnis'], ['12-25', 'Christtag'], ['12-26', 'Stefanitag']]
      .forEach(([md, name]) => { map[y + '-' + md] = name; });
    const e = easter(y);
    [[1, 'Ostermontag'], [39, 'Christi Himmelfahrt'], [50, 'Pfingstmontag'], [60, 'Fronleichnam']]
      .forEach(([off, name]) => { map[keyOf(addDays(e, off))] = name; });
    holidayCache[y] = map;
    return map;
  }
  const holidayOf = (d) => holidays(d.getFullYear())[keyOf(d)] || null;
  const manualOf = (d) => (d.getMonth() === 11 && d.getDate() === 24 ? { name: 'Heiliger Abend', from: '08:30', to: '12:00' } : null);

  /* ---------- Zustand ---------- */
  const STORE = 'treatbetter-entwurf-v1';
  function defaults() {
    return {
      hours: {
        1: { open: true, from: '08:30', to: '13:30' },
        2: { open: true, from: '08:30', to: '19:00' },
        3: { open: true, from: '08:30', to: '13:30' },
        4: { open: true, from: '08:30', to: '19:00' },
        5: { open: true, from: '07:30', to: '20:00' },
        6: { open: false, from: '08:30', to: '13:00' },
        0: { open: false, from: '08:30', to: '13:00' },
      },
      rules: { step: 15, buffer: 5, lead: 30, weeks: 8, storno: 24, reminder: 'mail', noshow: 'phone' },
      autoHolidays: true,
      blockers: { busy: true, oof: true, tentative: true, elsewhere: false, allDay: true },
      calendars: {},
      writeTo: {},
      bookable: { andrea: true, pamela: true },
      bookings: [],
      status: {},
      lastBooking: null,
    };
  }
  function load() {
    const base = defaults();
    try {
      const raw = window.localStorage.getItem(STORE);
      if (!raw) return base;
      const p = JSON.parse(raw);
      ['hours', 'rules', 'blockers', 'calendars', 'writeTo', 'bookable', 'status'].forEach((k) => {
        if (p[k] && typeof p[k] === 'object') base[k] = Object.assign(base[k], p[k]);
      });
      if (Array.isArray(p.bookings)) base.bookings = p.bookings.filter((b) => b && svc(b.svc) && /^\d{4}-\d{2}-\d{2}$/.test(b.key));
      if (typeof p.autoHolidays === 'boolean') base.autoHolidays = p.autoHolidays;
      if (typeof p.lastBooking === 'string') base.lastBooking = p.lastBooking;
    } catch (e) { /* kein Speicher verfügbar: mit Standardwerten weiter */ }
    return base;
  }
  function save() {
    try { window.localStorage.setItem(STORE, JSON.stringify(S)); } catch (e) { /* ignorieren */ }
  }
  const initialUi = () => ({
    cat: 'beliebt', menu: false, hoursOpen: false, svc: null, staff: 'any', week: 0, day: null, slot: null,
    form: { name: '', mail: '', tel: '', agb: false }, errors: {}, adminDay: null,
  });
  let S = load();
  let U = initialUi();

  /* ---------- Logik: Öffnungszeiten, Kalender, freie Termine ---------- */
  function openingFor(d) {
    const reg = S.hours[d.getDay()];
    const man = manualOf(d);
    if (man && reg && reg.open) return { from: toMin(man.from), to: toMin(man.to), note: man.name };
    if (S.autoHolidays) {
      const h = holidayOf(d);
      if (h) return { closed: true, note: h };
    }
    if (!reg || !reg.open) return { closed: true, note: '' };
    return { from: toMin(reg.from), to: toMin(reg.to) };
  }
  function eventsFor(id, d) {
    const key = keyOf(d);
    const par = weekParity(d);
    const closed = openingFor(d).closed;
    const tpl = ((TEMPLATE[id] || {})[d.getDay()] || [])
      .map((e, i) => Object.assign({ id: id + '~' + key + '~' + i }, e))
      .filter((e) => (!e.weeks || e.weeks === par) && !(closed && e.kind === 'booking'));
    const mine = S.bookings.filter((b) => b.staff === id && b.key === key).map((b) => {
      const s = svc(b.svc);
      return { id: b.id, kind: 'booking', s: b.start, e: b.end, who: b.name, mail: b.mail, tel: b.tel, label: s.name, price: s.price, mine: true };
    });
    return tpl.concat(mine).sort((a, b) => a.s - b.s);
  }
  const isBlocking = (e) => e.kind === 'booking' || (e.allDay ? !!S.blockers.allDay : !!S.blockers[e.show]);
  const activeStaff = () => STAFF.filter((p) => S.bookable[p.id]);

  function slotsFor(id, d, dur) {
    if (!S.bookable[id]) return [];
    const o = openingFor(d);
    if (o.closed) return [];
    const diff = dayDiff(today(), d);
    if (diff < 0 || diff >= S.rules.weeks * 7) return [];
    const step = Number(S.rules.step), buffer = Number(S.rules.buffer);
    const earliest = nowMin() + Number(S.rules.lead) - diff * 1440;
    const evs = eventsFor(id, d).filter(isBlocking);
    const out = [];
    for (let t = o.from; t + dur <= o.to; t += step) {
      if (t < earliest) continue;
      if (evs.some((e) => t < e.e + buffer && t + dur + buffer > e.s)) continue;
      out.push(t);
    }
    return out;
  }
  // Uhrzeit -> Personen, die zu dieser Uhrzeit frei sind
  function slotMap(d, dur) {
    const ids = U.staff === 'any' ? activeStaff().map((p) => p.id) : [U.staff];
    const map = new Map();
    ids.forEach((id) => slotsFor(id, d, dur).forEach((t) => {
      if (!map.has(t)) map.set(t, []);
      map.get(t).push(id);
    }));
    return new Map(Array.from(map.entries()).sort((a, b) => a[0] - b[0]));
  }
  function findNextFree(from, dur) {
    const limit = S.rules.weeks * 7;
    for (let i = 1; i <= limit; i++) {
      const d = addDays(from, i);
      if (dayDiff(today(), d) >= limit) break;
      if (slotMap(d, dur).size) return d;
    }
    return null;
  }
  const countBookings = (id, d) => eventsFor(id, d).filter((e) => e.kind === 'booking').length;
  const deadline = (b) => at(fromKey(b.key), b.start - Number(S.rules.storno) * 60);

  /* ---------- Ansichten: Kundenseite ---------- */
  const banner = () => '<div class="banner" role="note"><span>Entwurf · Testversion: Es wird nichts gebucht, Eingaben bleiben nur in diesem Browser.</span>' +
    '<button type="button" class="banner-btn" data-act="reset">Testdaten zurücksetzen</button></div>';
  const phoneLinks = () => SALON.phones.map(([label, tel]) => '<a href="tel:' + tel + '">' + label + '</a>').join(' · ');

  function menuHtml() {
    return `<div class="menu-layer">
  <nav class="menu" aria-label="Menü">
    <button type="button" class="menu-item" data-act="menu-book">${ic('cal')}Termin buchen</button>
    <a class="menu-item" href="#/meine">${ic('user')}Meine Termine</a>
    <a class="menu-item" href="${esc(SALON.maps)}" target="_blank" rel="noopener">${ic('pin')}Anfahrt (Google Maps)</a>
    <a class="menu-item" href="tel:${SALON.phones[0][1]}">${ic('phone')}Anrufen: ${SALON.phones[0][0]}</a>
    <div class="menu-sep"></div>
    <span class="menu-label">Für das Salon-Team</span>
    <a class="menu-item" href="#/verwaltung">${ic('lock')}Verwaltung: Kalender</a>
    <a class="menu-item" href="#/verwaltung/oeffnungszeiten">${ic('clock')}Verwaltung: Öffnungszeiten</a>
    <a class="menu-item" href="#/verwaltung/team">${ic('users')}Verwaltung: Team &amp; Kalender</a>
  </nav>
  <button type="button" class="menu-scrim" data-act="menu" aria-label="Menü schließen"></button>
</div>`;
  }

  function viewSalon() {
    const d = today();
    const o = openingFor(d);
    const n = nowMin();
    let status;
    if (o.closed) status = '<span class="closed">Heute geschlossen</span>' + (o.note ? ' · ' + esc(o.note) : '');
    else if (n < o.from) status = '<span class="closed">Öffnet heute um ' + fmt(o.from) + '</span>';
    else if (n < o.to) status = '<span class="open">Geöffnet</span> · heute bis ' + fmt(o.to);
    else status = '<span class="closed">Geschlossen</span> · heute ' + fmt(o.from) + '–' + fmt(o.to);
    const hours = [1, 2, 3, 4, 5, 6, 0].map((wd) => {
      const h = S.hours[wd];
      const cls = wd === d.getDay() ? ' class="today"' : '';
      return '<dt' + cls + '>' + DOW_LONG[wd] + '</dt><dd' + cls + '>' + (h.open ? h.from + '–' + h.to : 'geschlossen') + '</dd>';
    }).join('');
    const tabs = CATS.map(([id, label]) => `<button type="button" class="tab" data-act="cat" data-arg="${id}" aria-pressed="${U.cat === id}">${label}</button>`).join('');
    const rows = SERVICES.filter((s) => s.cats.indexOf(U.cat) >= 0).map((s) => `<li class="svc">
      <div class="svc-text"><span class="svc-name">${esc(s.name)}</span><span class="muted">${durText(s.min)}</span></div>
      <span class="svc-price">${euro(s.price)}</span>
      <button type="button" class="btn-outline" data-act="pick-svc" data-arg="${s.id}" aria-label="${esc(s.name)} auswählen">Auswählen</button>
    </li>`).join('');
    return `<header class="topbar">
  <div class="brand"><span class="mono" aria-hidden="true">AZ</span><span class="brand-name">${esc(SALON.name)}</span></div>
  <button type="button" class="icon-btn" data-act="menu" aria-expanded="${U.menu}" aria-label="${U.menu ? 'Menü schließen' : 'Menü öffnen'}">${ic(U.menu ? 'close' : 'menu', 24, 2)}</button>
</header>
${U.menu ? menuHtml() : ''}
<div class="hero"><h1><img class="logo" src="logo.jpg" alt="Friseur Andrea Zechmeister" width="1600" height="653"></h1></div>
<section class="info">
  <p class="row">${ic('pin', 18)}<span>${SALON.street}, ${SALON.city}</span></p>
  <p class="row">${ic('clock', 18)}<span>${status}</span></p>
  <p class="row">${ic('phone', 18)}<span>${phoneLinks()}</span></p>
  <details class="hours"${U.hoursOpen ? ' open' : ''}><summary>Alle Öffnungszeiten</summary><dl>${hours}</dl></details>
  <div class="chips">
    <span class="chip">${ic('cash', 16)}Bezahlung vor Ort</span>
    <span class="chip">${ic('user', 16)}Ohne Konto buchbar</span>
  </div>
</section>
<section class="services" id="leistungen" aria-labelledby="h-leistungen">
  <h2 id="h-leistungen">Leistungen</h2>
  <div class="tabs" role="group" aria-label="Kategorie">${tabs}</div>
  <ul class="svc-list">${rows}</ul>
</section>`;
  }

  function viewZeit() {
    const s = svc(U.svc);
    if (!s) { location.replace('#/'); return ''; }
    const act = activeStaff();
    if (U.staff !== 'any' && !act.some((p) => p.id === U.staff)) U.staff = 'any';
    const maxWeek = Math.max(0, Number(S.rules.weeks) - 1);
    U.week = Math.min(Math.max(U.week, 0), maxWeek);
    const first = addDays(today(), U.week * 7);
    const info = Array.from({ length: 7 }, (_, i) => {
      const d = addDays(first, i);
      const o = openingFor(d);
      return { d, o, key: keyOf(d), slots: o.closed ? new Map() : slotMap(d, s.min) };
    });
    if (!info.some((x) => x.key === U.day)) {
      const pick = info.find((x) => x.slots.size) || info.find((x) => !x.o.closed) || info[0];
      U.day = pick.key;
    }
    const sel = info.find((x) => x.key === U.day);
    const people = [{ id: 'any', name: 'Beliebig' }].concat(act);
    const staffHtml = people.map((p) => {
      const av = p.id === 'any' ? `<span class="avatar">${ic('users', 22)}</span>` : `<span class="avatar" style="background:${p.bg};color:${p.fg}">${p.initial}</span>`;
      return `<button type="button" class="person" data-act="staff" data-arg="${p.id}" aria-pressed="${U.staff === p.id}">${av}${esc(p.name)}</button>`;
    }).join('');
    const daysHtml = info.map((x) => {
      const cls = x.o.closed ? '' : x.slots.size ? 'has' : 'full';
      const aria = longDate(x.d) + (x.o.closed ? ', ' + (x.o.note ? x.o.note + ', ' : '') + 'geschlossen' : x.slots.size ? ', ' + x.slots.size + ' freie Termine' : ', ausgebucht');
      return `<button type="button" class="day ${cls}" data-act="day" data-arg="${x.key}" aria-pressed="${x.key === U.day}" aria-label="${esc(aria)}" title="${esc(aria)}"${x.o.closed ? ' disabled' : ''}>
        <span class="dow">${DOW[x.d.getDay()]}</span><span class="num">${x.d.getDate()}</span><span class="dot"></span></button>`;
    }).join('');
    const last = info[6].d;
    const monthLabel = (first.getMonth() === last.getMonth() ? MON[first.getMonth()] : MON[first.getMonth()] + ' / ' + MON[last.getMonth()]) + ' ' + last.getFullYear();
    const times = Array.from(sel.slots.keys());
    const groups = [['Vormittag', 0, 720], ['Nachmittag', 720, 1020], ['Abend', 1020, 1440]].map(([label, a, b]) => {
      const list = times.filter((t) => t >= a && t < b);
      if (!list.length) return '';
      return `<div class="slot-group"><h3>${label} <span>· ${list.length} frei</span></h3><div class="slot-grid">${list.map((t) =>
        `<button type="button" class="slot" data-act="slot" data-arg="${t}" aria-label="${fmt(t)} Uhr, ${longDate(sel.d)}">${fmt(t)}</button>`).join('')}</div></div>`;
    }).join('');
    let empty = '';
    if (!times.length) {
      const who = U.staff === 'any' ? '' : ' bei ' + esc(staffById(U.staff).name);
      const next = findNextFree(sel.d, s.min);
      const reason = sel.o.closed ? `Am ${longDate(sel.d)} ist geschlossen.` : `Am ${longDate(sel.d)}${who} ist leider nichts mehr frei.`;
      empty = `<div class="empty"><span>${reason}</span>${next ? `<button type="button" class="btn-primary sm" data-act="goto-day" data-arg="${keyOf(next)}">Nächster freier Tag: ${shortDate(next)}</button>` : ''}</div>`;
    }
    return `<header class="topbar back">
  <a class="icon-btn" href="#/" aria-label="Zurück zur Salon-Seite">${ic('back', 22, 2)}</a>
  <h1 class="topbar-title">Termin wählen</h1>
</header>
<div class="pad">
  <div class="sumcard">
    <div><b>${esc(s.name)}</b><span class="muted">${durText(s.min)} · Bezahlung vor Ort</span></div>
    <div style="align-items:flex-end"><b>${euro(s.price)}</b><a href="#/">ändern</a></div>
  </div>
</div>
<div class="pad">
  <h2 class="label">Bei wem?</h2>
  <div class="staff-grid" style="grid-template-columns:repeat(${people.length},minmax(0,1fr))">${staffHtml}</div>
</div>
<div class="weekhead">
  <span class="month">${monthLabel}</span>
  <div>
    <button type="button" class="icon-btn" data-act="week" data-arg="-1" aria-label="Vorherige Woche"${U.week <= 0 ? ' disabled' : ''}>${ic('left', 20, 2)}</button>
    <button type="button" class="icon-btn" data-act="week" data-arg="1" aria-label="Nächste Woche"${U.week >= maxWeek ? ' disabled' : ''}>${ic('right', 20, 2)}</button>
  </div>
</div>
<div class="week">${daysHtml}</div>
<p class="sync">${ic('sync', 16, 2)}<span>${longDate(sel.d)} · freie Zeiten aus den Google Kalendern</span></p>
<div class="slots">${groups}${empty}</div>`;
  }

  function viewBuchen() {
    const s = svc(U.svc);
    if (!s || !U.slot) { location.replace(s ? '#/termin' : '#/'); return ''; }
    const d = fromKey(U.slot.key);
    const t = U.slot.t;
    const cands = slotMap(d, s.min).get(t) || [];
    const staffText = U.staff !== 'any' ? 'Bei ' + staffById(U.staff).name
      : cands.length === 1 ? 'Bei ' + staffById(cands[0]).name : 'Beliebig – wir teilen dich zu';
    const E = U.errors, F = U.form;
    const field = (name, label, type, ac, ph, hint) => `<div class="field">
      <label for="f-${name}">${label}${hint ? ' <span class="muted" style="font-weight:500">' + hint + '</span>' : ''}</label>
      <input id="f-${name}" name="${name}" type="${type}" autocomplete="${ac}" placeholder="${ph}" value="${esc(F[name])}"${E[name] ? ` aria-invalid="true" aria-describedby="e-${name}"` : ''}>
      ${E[name] ? `<span class="err" id="e-${name}">${E[name]}</span>` : ''}
    </div>`;
    return `<header class="topbar back">
  <a class="icon-btn" href="#/termin" aria-label="Zurück zur Terminauswahl">${ic('back', 22, 2)}</a>
  <h1 class="topbar-title">Buchung abschließen</h1>
</header>
<form class="pad stack" data-submit="book" novalidate style="flex:1">
  <section class="card summary" aria-label="Dein Termin">
    <p class="row">${ic('store', 18)}${esc(SALON.name)}</p>
    <div class="when">
      <span class="big">${fmt(t)}</span><span class="vline" aria-hidden="true"></span>
      <div><b>${shortDate(d)}</b><span class="muted small">${durText(s.min)} · bis ${fmt(t + s.min)}</span></div>
      <a class="icon-btn" href="#/termin" aria-label="Termin ändern">${ic('edit')}</a>
    </div>
    <div class="sumline"><div><b>${esc(s.name)}</b><span class="muted small">${esc(staffText)}</span></div><b>${euro(s.price)}</b></div>
  </section>
  <section class="fields">
    <h2>Deine Daten</h2>
    ${field('name', 'Vollständiger Name', 'text', 'name', 'Vor- und Nachname')}
    ${field('mail', 'E-Mail', 'email', 'email', 'name@beispiel.at')}
    ${field('tel', 'Telefonnummer', 'tel', 'tel', '+43 …', '(für Rückfragen)')}
  </section>
  <section class="paysec">
    <h2>Bezahlung</h2>
    <div class="payopt"><span class="radio" aria-hidden="true"></span><div><b>Vor Ort im Salon bezahlen</b><span>Bar oder mit Karte. Keine Vorauszahlung, keine Kreditkarte nötig.</span></div></div>
  </section>
  <div>
    <label class="consent"><input type="checkbox" name="agb"${F.agb ? ' checked' : ''}${E.agb ? ' aria-invalid="true" aria-describedby="e-agb"' : ''}>
      <span>Ich akzeptiere die Buchungsbedingungen. Kostenlos stornierbar bis ${S.rules.storno} Std. vor dem Termin.</span></label>
    ${E.agb ? `<span class="err" id="e-agb">${E.agb}</span>` : ''}
  </div>
  ${E.slot ? `<p class="err" role="alert">${E.slot}</p>` : ''}
  <div class="paybar">
    <div><span class="muted small">Vor Ort zahlen</span><b class="total">${euro(s.price)}</b></div>
    <button type="submit" class="btn-primary">Verbindlich buchen</button>
  </div>
</form>`;
  }

  function viewBestaetigt() {
    const b = S.bookings.find((x) => x.id === S.lastBooking);
    const head = `<header class="topbar"><div class="brand"><span class="mono" aria-hidden="true">AZ</span><span class="brand-name">${esc(SALON.name)}</span></div></header>`;
    if (!b) return head + '<div class="done"><h1>Kein Termin gefunden</h1><p>Der Testtermin wurde storniert oder zurückgesetzt.</p></div><div class="after"><a class="btn-soft" href="#/">Zur Salon-Seite</a></div>';
    const s = svc(b.svc), d = fromKey(b.key), p = staffById(b.staff), dl = deadline(b);
    const canCancel = Date.now() < dl.getTime();
    const storno = canCancel
      ? `<button type="button" class="btn-text" data-act="cancel" data-arg="${b.id}">Termin stornieren</button><p class="muted center small">Kostenlos stornierbar bis ${shortDate(dl)}, ${fmt(dl.getHours() * 60 + dl.getMinutes())} Uhr</p>`
      : `<p class="muted center small">Online-Storno nicht mehr möglich. Bitte im Salon anrufen: <a href="tel:${SALON.phones[0][1]}">${SALON.phones[0][0]}</a></p>`;
    return `${head}
<div class="done">
  <span class="done-icon">${ic('check', 36, 2.2)}</span>
  <h1>Termin gebucht!</h1>
  <p>Im echten Betrieb ginge jetzt eine Bestätigung an <b>${esc(b.mail)}</b>, und der Termin stünde sofort im Google Kalender von ${esc(p.name)}.</p>
</div>
<dl class="card details">
  <dt>Wann</dt><dd><b>${longDate(d)}</b><br>${fmt(b.start)}–${fmt(b.end)} Uhr</dd>
  <dt>Was</dt><dd>${esc(s.name)}</dd>
  <dt>Bei</dt><dd>${esc(p.name)}</dd>
  <dt>Wo</dt><dd>${esc(SALON.name)}<br>${SALON.street}, ${SALON.city}</dd>
  <dt>Bezahlung</dt><dd><b>${euro(s.price)} vor Ort</b><br>bar oder mit Karte</dd>
</dl>
<div class="after">
  <button type="button" class="btn-outline wide" data-act="ics" data-arg="${b.id}">${ic('cal', 18)}Zum eigenen Kalender hinzufügen</button>
  ${storno}
  <a class="btn-soft" href="#/verwaltung" data-act="admin-day" data-arg="${b.key}">So sieht der Salon diesen Termin</a>
  <a class="btn-soft" href="#/">Zurück zum Salon</a>
</div>`;
  }

  function viewMeine() {
    const list = S.bookings.slice().sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : a.start - b.start));
    const items = list.map((b) => {
      const s = svc(b.svc), d = fromKey(b.key), p = staffById(b.staff);
      const past = at(d, b.end) < new Date();
      const can = !past && Date.now() < deadline(b).getTime();
      return `<li class="card">
        <b>${shortDate(d)} · ${fmt(b.start)}–${fmt(b.end)}</b>
        <span>${esc(s.name)} bei ${esc(p.name)}</span>
        <span class="muted small">${euro(s.price)} vor Ort${past ? ' · vorbei' : ''}</span>
        <div class="acts">${past ? '' : `<button type="button" class="mini" data-act="ics" data-arg="${b.id}">In meinen Kalender</button>`}${can ? `<button type="button" class="mini" data-act="cancel" data-arg="${b.id}">Stornieren</button>` : ''}</div>
      </li>`;
    }).join('');
    return `<header class="topbar back">
  <a class="icon-btn" href="#/" aria-label="Zurück zur Salon-Seite">${ic('back', 22, 2)}</a>
  <h1 class="topbar-title">Meine Termine</h1>
</header>
<div class="pad" style="padding-bottom:28px;display:flex;flex-direction:column;gap:12px">
  <p class="muted" style="font-size:14px;line-height:1.5">Hier stehen die Termine, die in diesem Browser gebucht wurden. Im echten Betrieb kommt man über den Link in der Bestätigungs-E-Mail hierher, ganz ohne Konto.</p>
  ${items ? `<ul class="mylist">${items}</ul>` : '<div class="empty"><span>Noch keine Termine gebucht.</span><a class="btn-outline" href="#/">Leistung auswählen</a></div>'}
</div>`;
  }

  /* ---------- Ansichten: Verwaltung ---------- */
  function adminShell(r, inner) {
    const link = (href, icon, label, key) => `<a class="side-link" href="${href}"${r === key ? ' aria-current="page"' : ''}>${ic(icon, 18)}${label}</a>`;
    const soon = (icon, label) => `<button type="button" class="side-link" data-act="soon" data-arg="${label}">${ic(icon, 18)}${label}</button>`;
    return `<div class="admin">
  <nav class="side" aria-label="Verwaltung">
    <div class="side-brand"><span class="mono" aria-hidden="true">AZ</span><div><b>${esc(SALON.name)}</b><span class="muted">Verwaltung</span></div></div>
    <div class="side-links">
      ${link('#/verwaltung', 'cal', 'Kalender', 'verwaltung')}
      ${soon('list', 'Buchungen')}
      ${soon('scissors', 'Leistungen')}
      ${link('#/verwaltung/team', 'users', 'Team &amp; Kalender', 'verwaltung/team')}
      ${link('#/verwaltung/oeffnungszeiten', 'clock', 'Öffnungszeiten', 'verwaltung/oeffnungszeiten')}
      ${soon('sliders', 'Einstellungen')}
    </div>
    <a class="side-link outline" href="#/">${ic('ext', 18)}Buchungsseite ansehen</a>
  </nav>
  <main class="main" id="main">${inner}</main>
</div>`;
  }

  function viewKalender() {
    const d = U.adminDay ? fromKey(U.adminDay) : today();
    const diff = dayDiff(today(), d);
    const isToday = diff === 0;
    const o = openingFor(d);
    const nm = nowMin();
    const START = 7 * 60, END = 20 * 60 + 30, H = 48;
    const y = (m) => Math.round((Math.min(Math.max(m, START), END) - START) * H / 60);
    const total = y(END);
    const buffer = Number(S.rules.buffer), lead = Number(S.rules.lead);

    const cols = STAFF.map((p) => {
      const evs = eventsFor(p.id, d);
      const blocks = [];
      const add = (a, b, cls, one, t1, t2) => {
        const top = y(a) + 1, h = Math.max(y(b) - y(a) - 2, 14), tall = h >= 34 && t2;
        blocks.push(`<div class="blk ${cls}${tall ? ' tall' : ''}" style="top:${top}px;height:${h}px">${tall ? `<b>${t1}</b><span>${t2}</span>` : `<b>${one}</b>`}</div>`);
      };
      if (o.closed) {
        const label = o.note ? esc(o.note) + ' · geschlossen' : 'Geschlossen';
        add(START, END, 'blk-off', label, label, '');
      } else {
        if (o.from > START) add(START, o.from, 'blk-off', '', '', '');
        if (o.to < END) add(o.to, END, 'blk-off', '', '', '');
        if (S.bookable[p.id] && diff >= 0 && diff < S.rules.weeks * 7) {
          const busy = evs.filter(isBlocking).map((e) => [e.s - buffer, e.e + buffer]).sort((a, b) => a[0] - b[0]);
          let cur = Math.max(o.from, nm + lead - diff * 1440);
          const free = [];
          busy.forEach(([a, b]) => { if (a > cur) free.push([cur, Math.min(a, o.to)]); cur = Math.max(cur, b); });
          if (cur < o.to) free.push([cur, o.to]);
          free.filter(([a, b]) => b - a >= 15).forEach(([a, b]) => {
            const range = fmt(a) + '–' + fmt(b);
            add(a, b, 'blk-free', 'Frei · ' + range, 'Frei · online buchbar', range);
          });
        }
      }
      evs.forEach((e) => {
        if (e.kind === 'booking') {
          const neu = e.mine ? ' · neu' : '';
          add(e.s, e.e, 'blk-book' + (e.mine ? ' mine' : ''), `${fmt(e.s)} ${esc(e.who)}${neu} · ${esc(e.label)}`, `${fmt(e.s)} · ${esc(e.who)}${neu}`, esc(e.label));
          return;
        }
        if (e.allDay && o.closed) return;
        const a = e.allDay ? o.from : e.s, b = e.allDay ? o.to : e.e;
        const label = (e.allDay ? 'Abwesend, ganztägig' : SHOW_LABEL[e.show]) + ' (Google)';
        const range = e.allDay ? 'ganzer Tag' : fmt(e.s) + '–' + fmt(e.e);
        if (isBlocking(e)) add(a, b, 'blk-busy', label + ' · ' + range, label, range);
        else add(a, b, 'blk-soft', label + ' · blockiert nicht', label, range + ' · blockiert nicht');
      });
      if (isToday && nm >= START && nm <= END) blocks.push(`<div class="nowline" style="top:${y(nm)}px" aria-hidden="true"></div>`);
      const lines = [];
      for (let m = START; m <= END; m += 60) lines.push(`<div class="hline" style="top:${y(m)}px"></div>`);
      const sub = !S.bookable[p.id] ? 'online nicht buchbar' : o.closed ? 'geschlossen' : fmt(o.from) + '–' + fmt(o.to);
      return {
        head: `<div class="cal-col-head"><span class="avatar" style="background:${p.bg};color:${p.fg}">${p.initial}</span><div><b>${p.name}</b><span>${sub}</span></div></div>`,
        body: `<div class="col" style="height:${total}px">${lines.join('')}${blocks.join('')}</div>`,
      };
    });
    const labels = [];
    for (let m = START; m < END; m += 60) labels.push(`<span style="top:${y(m) + 4}px">${fmt(m)}</span>`);
    const gridCols = `56px repeat(${STAFF.length}, minmax(150px, 1fr))`;

    const all = [];
    STAFF.forEach((p) => eventsFor(p.id, d).filter((e) => e.kind === 'booking').forEach((e) => all.push(Object.assign({ staffName: p.name }, e))));
    all.sort((a, b) => a.s - b.s);
    const statusOf = (e) => {
      if (S.status[e.id]) return S.status[e.id];
      const ended = diff < 0 || (diff === 0 && e.e <= nm);
      return ended ? (e.noshow ? 'weg' : 'da') : 'offen';
    };
    const started = (e) => diff < 0 || (diff === 0 && e.s <= nm);
    const paid = all.filter((e) => statusOf(e) === 'da').reduce((n, e) => n + e.price, 0);
    const noShows = all.filter((e) => statusOf(e) === 'weg').length;
    const list = all.map((e) => {
      const st = statusOf(e);
      let acts;
      if (st === 'da') acts = `<span class="st st-da">Erschienen</span><button type="button" class="linkbtn" data-act="status" data-arg="${e.id}" data-st="offen">ändern</button>`;
      else if (st === 'weg') acts = `<span class="st st-weg">Nicht erschienen</span><button type="button" class="linkbtn" data-act="status" data-arg="${e.id}" data-st="offen">ändern</button>`;
      else if (started(e)) acts = `<button type="button" class="mini ok" data-act="status" data-arg="${e.id}" data-st="da">Erschienen</button><button type="button" class="mini" data-act="status" data-arg="${e.id}" data-st="weg">Nicht erschienen</button>`;
      else acts = '<span class="st st-plan">Geplant</span>';
      return `<div class="bk">
        <div class="bk-top"><span class="bk-time">${fmt(e.s)}</span>
          <div class="bk-main"><b>${esc(e.who)}${e.mine ? '<span class="tag-new">NEU</span>' : ''}</b><span>${esc(e.label)} · ${esc(e.staffName)}</span>${e.mine ? `<span>${esc(e.tel)} · ${esc(e.mail)}</span>` : ''}</div>
          <span class="bk-price">${euro(e.price)}</span></div>
        <div class="bk-actions">${acts}</div>
      </div>`;
    }).join('') || `<p class="muted" style="font-size:14px;padding:8px 0">${o.closed ? 'Der Salon hat an diesem Tag geschlossen.' : 'Keine Online-Buchungen an diesem Tag.'}</p>`;

    const pills = STAFF.map((p) => `<span class="pill"><i${S.bookable[p.id] ? '' : ' class="off"'}></i><b>${p.name}</b> Google Kalender abgeglichen ${p.synced}${S.bookable[p.id] ? '' : ' · online nicht buchbar'}</span>`).join('');
    const subtitle = longDate(d) + ' ' + d.getFullYear() + (isToday ? ' · heute' : '') + (o.note ? ' · ' + esc(o.note) : '');

    return `<div class="pagehead">
  <div><h1>Kalender</h1><span class="muted" style="font-size:14px">${subtitle}</span></div>
  <div class="daynav">
    <button type="button" class="icon-btn" data-act="admin-shift" data-arg="-1" aria-label="Vorheriger Tag">${ic('left', 18, 2)}</button>
    <button type="button" class="today-btn" data-act="admin-shift" data-arg="0">Heute</button>
    <button type="button" class="icon-btn" data-act="admin-shift" data-arg="1" aria-label="Nächster Tag">${ic('right', 18, 2)}</button>
  </div>
  <button type="button" class="btn-primary sm" data-act="soon" data-arg="Termin eintragen">${ic('plus', 18, 2)}Termin eintragen</button>
</div>
<div class="pills">${pills}</div>
<div class="split">
  <section class="cal" aria-label="Tagesansicht">
    <div class="cal-scroll"><div class="cal-inner">
      <div class="cal-head" style="grid-template-columns:${gridCols}"><div></div>${cols.map((c) => c.head).join('')}</div>
      <div class="cal-body" style="grid-template-columns:${gridCols}"><div class="gutter" style="height:${total}px">${labels.join('')}</div>${cols.map((c) => c.body).join('')}</div>
    </div></div>
  </section>
  <aside class="aside">
    <section class="panel">
      <h2>${isToday ? 'Heute' : shortDate(d)}</h2>
      <div class="stats">
        <div><b>${all.length}</b><span>Termine</span></div>
        <div><b>${euro(paid)}</b><span>vor Ort kassiert</span></div>
        <div><b>${noShows}</b><span>nicht erschienen</span></div>
      </div>
    </section>
    <section class="panel"><h2>Online-Buchungen</h2><div style="margin-top:8px">${list}</div></section>
    <section class="panel">
      <h2>Legende</h2>
      <div class="legend">
        <div><span class="sw" style="background:#6A2C70"></span>Online gebucht, steht auch im Google Kalender</div>
        <div><span class="sw" style="background:#7E3485;box-shadow:inset 0 0 0 2px #F3C969"></span>In diesem Test gebucht</div>
        <div><span class="sw" style="background:#E6E2EA"></span>Belegt laut Google Kalender, ohne Details</div>
        <div><span class="sw" style="background:#FFFFFF;border:1.5px dashed #B9B3C0"></span>Im Google Kalender, blockiert laut Einstellung nicht</div>
        <div><span class="sw" style="background:#EEF7F2;border:1.5px dashed #2E8B5B"></span>Frei und online buchbar</div>
        <div><span class="sw" style="background:repeating-linear-gradient(135deg,#DDD8E2 0 4px,#F8F7FA 4px 8px)"></span>Geschlossen</div>
        <div><span class="sw" style="height:2px;background:#C2410C"></span>Jetzt</div>
      </div>
    </section>
  </aside>
</div>`;
  }

  const switchBtn = (on, act, arg, label) => `<button type="button" class="switch" role="switch" aria-checked="${!!on}" aria-label="${label}" data-act="${act}" data-arg="${arg}"><span class="track"><span class="knob"></span></span></button>`;

  function viewOeffnung() {
    const rows = [1, 2, 3, 4, 5, 6, 0].map((wd) => {
      const h = S.hours[wd], n = DOW_LONG[wd];
      const times = h.open
        ? `<div class="times">
            <input type="time" step="900" value="${h.from}" aria-label="${n} von" data-change="day-from" data-arg="${wd}">
            <span class="muted">–</span>
            <input type="time" step="900" value="${h.to}" aria-label="${n} bis" data-change="day-to" data-arg="${wd}">
            <button type="button" class="btn-text" style="font-size:13px" data-act="soon" data-arg="Pause eintragen">+ Pause</button>
          </div>`
        : '<span class="muted" style="font-size:14px">Geschlossen</span>';
      return `<div class="dayrow">${switchBtn(h.open, 'day-open', wd, n + ' geöffnet')}<span class="dayname">${n}</span>${times}</div>`;
    }).join('');
    const ex = [];
    for (let i = 0; i < 400 && ex.length < 5; i++) {
      const d = addDays(today(), i), reg = S.hours[d.getDay()];
      if (!reg || !reg.open) continue;
      const m = manualOf(d), h = holidayOf(d);
      if (m) ex.push(`<div class="exrow"><b>${shortDate(d)}</b><span class="n">${m.name} <span class="muted">· manuell</span></span><span class="badge g">${m.from}–${m.to}</span></div>`);
      else if (h && S.autoHolidays) ex.push(`<div class="exrow"><b>${shortDate(d)}</b><span class="n">${h} <span class="muted">· automatisch</span></span><span class="badge">Geschlossen</span></div>`);
    }
    const sel = (key, label, opts, full) => `<div class="field${full ? ' full' : ''}">
      <label for="r-${key}">${label}</label>
      <select id="r-${key}" data-change="rule" data-arg="${key}">${opts.map(([v, t]) => `<option value="${v}"${String(S.rules[key]) === String(v) ? ' selected' : ''}>${t}</option>`).join('')}</select>
    </div>`;
    return `<div class="pagehead">
  <div><h1>Öffnungszeiten &amp; Buchungsregeln</h1>
    <p class="lead">Der Rahmen für alle Online-Termine. Angeboten wird nur, was zusätzlich im Google Kalender der jeweiligen Person frei ist. In dieser Testversion wirken Änderungen sofort auf die <a href="#/">Buchungsseite</a>.</p></div>
  <button type="button" class="btn-primary sm" data-act="save">Änderungen speichern</button>
</div>
<div class="split">
  <section class="panel" style="flex:1 1 460px;min-width:0">
    <h2>Reguläre Öffnungszeiten</h2>
    <p class="muted" style="font-size:13px;line-height:1.5;margin:4px 0 12px">Gilt für den ganzen Salon. Eigene Arbeitszeiten pro Person kommen unter <a href="#/verwaltung/team">Team &amp; Kalender</a> dazu.</p>
    ${rows}
  </section>
  <div style="flex:1 1 400px;min-width:0;display:flex;flex-direction:column;gap:20px">
    <section class="panel">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <h2>Ausnahmen &amp; Feiertage</h2>
        <button type="button" class="mini" data-act="soon" data-arg="Ausnahme hinzufügen">+ Ausnahme</button>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin:8px 0">${switchBtn(S.autoHolidays, 'auto-holidays', '', 'Österreichische Feiertage automatisch schließen')}<span style="font-size:14px">Österreichische Feiertage automatisch schließen</span></div>
      ${ex.join('') || '<p class="muted" style="font-size:14px">Keine Ausnahmen in den nächsten Monaten.</p>'}
    </section>
    <section class="panel">
      <h2 style="margin-bottom:14px">Buchungsregeln</h2>
      <div class="grid2">
        ${sel('step', 'Startzeiten im Raster', [[10, 'alle 10 Min.'], [15, 'alle 15 Min.'], [30, 'alle 30 Min.']])}
        ${sel('buffer', 'Puffer nach jedem Termin', [[0, 'kein Puffer'], [5, '5 Min.'], [10, '10 Min.'], [15, '15 Min.']])}
        ${sel('lead', 'Frühestens buchbar', [[30, '30 Min. vorher'], [60, '1 Std. vorher'], [120, '2 Std. vorher'], [1440, '1 Tag vorher']])}
        ${sel('weeks', 'Buchbar bis', [[4, '4 Wochen im Voraus'], [8, '8 Wochen im Voraus'], [12, '12 Wochen im Voraus']])}
        ${sel('storno', 'Online stornierbar bis', [[12, '12 Std. vorher'], [24, '24 Std. vorher'], [48, '48 Std. vorher']])}
        ${sel('reminder', 'Erinnerung an Kund:innen', [['mail', 'E-Mail 24 Std. vorher'], ['mailsms', 'E-Mail + SMS 24 Std. vorher'], ['none', 'keine']])}
        ${sel('noshow', 'Wer nicht erscheint', [['phone', 'nach 2× nicht erschienen nur noch telefonisch buchbar'], ['none', 'keine Einschränkung']], true)}
      </div>
      <div class="note" style="margin-top:14px">${ic('lock')}<div><b style="font-size:14px">Bezahlung: nur vor Ort</b><span>Bar oder mit Karte im Salon. Online-Zahlung ist nicht vorgesehen, Kund:innen geben keine Zahlungsdaten ein.</span></div></div>
    </section>
  </div>
</div>`;
  }

  function viewTeam() {
    const card = (p) => {
      const on = !!S.bookable[p.id];
      const cals = p.calendars.map(([id, name, def]) => {
        const k = p.id + '.' + id;
        const c = S.calendars[k] === undefined ? def : S.calendars[k];
        return `<label class="check"><input type="checkbox" data-change="cal" data-arg="${k}"${c ? ' checked' : ''}>${name}</label>`;
      }).join('');
      const w = S.writeTo[p.id] || 'Kalender';
      return `<section class="pcard">
  <div class="phead"><span class="avatar" style="background:${p.bg};color:${p.fg}">${p.initial}</span><div><h2>${p.name}</h2><span class="muted small">${p.role}</span></div><span class="st st-da">Verbunden</span></div>
  <div class="acct">${ic('cal')}<div><b class="small">Google-Konto von ${p.name}</b><span class="muted" style="font-size:12px">Google Kalender · abgeglichen ${p.synced}</span></div>
    <button type="button" class="btn-text" style="font-size:13px" data-act="soon" data-arg="Kalender trennen">Trennen</button></div>
  <fieldset><legend>Belegt prüfen in</legend>${cals}</fieldset>
  <div class="field"><label for="w-${p.id}" style="font-weight:700">Neue Buchungen eintragen in</label>
    <select id="w-${p.id}" data-change="write" data-arg="${p.id}">${['Kalender', 'Salon-Termine'].map((n) => `<option${n === w ? ' selected' : ''}>${n}</option>`).join('')}</select></div>
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
    <div style="display:flex;flex-direction:column;gap:2px"><span class="sublabel">Arbeitszeiten</span><span style="font-size:14px">Wie Öffnungszeiten</span></div>
    <button type="button" class="btn-text" style="font-size:13px" data-act="soon" data-arg="Eigene Arbeitszeiten">Anpassen</button>
  </div>
  <div><span class="sublabel">Leistungen</span><div class="svc-chips">${GROUPS.map((g) => '<span>' + esc(g) + '</span>').join('')}</div></div>
  <div class="pfoot"><span>Online buchbar</span>${switchBtn(on, 'bookable', p.id, p.name + ' online buchbar')}</div>
</section>`;
    };
    const pending = `<section class="pcard">
  <div class="phead"><span class="avatar" style="background:#FCEBD9;color:#7A4B00">+</span><div><h2>Neue Kollegin</h2><span class="muted small">Beispiel für eine offene Einladung</span></div><span class="st st-weg">Einladung offen</span></div>
  <div class="note warn">${ic('info')}<div><span>Der Google Kalender ist noch nicht verbunden. Bis dahin ist diese Person online nicht buchbar.</span><span style="font-size:12px">Einladung per E-Mail gesendet</span></div></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" class="btn-outline" data-act="soon" data-arg="Einladung erneut senden">Einladung erneut senden</button>
    <button type="button" class="btn-text" data-act="soon" data-arg="Link kopieren">Link kopieren</button>
  </div>
  <div><span class="sublabel">Leistungen</span><div class="svc-chips"><span>Damen</span><span>Kinder &amp; Jugend</span></div></div>
</section>`;
    const blockers = BLOCKERS.map(([k, label]) => `<label class="check"><input type="checkbox" data-change="blocker" data-arg="${k}"${S.blockers[k] ? ' checked' : ''}>${label}</label>`).join('');
    const step = (n, title, text) => `<div class="step"><span class="step-n">${n}</span><div><b style="font-size:14px">${title}</b><span>${text}</span></div></div>`;
    return `<div class="pagehead">
  <div><h1>Team &amp; Google Kalender</h1><p class="lead">Jede Person verbindet ihren eigenen Google Kalender, denselben wie am Handy. Wir sehen nur, wann jemand belegt ist, nicht was eingetragen ist.</p></div>
  <button type="button" class="btn-primary sm" data-act="soon" data-arg="Person einladen">${ic('plus', 18, 2)}Person einladen</button>
</div>
<section class="panel steps" aria-label="So entstehen freie Termine">
  ${step(1, 'Rahmen setzen', 'Öffnungszeiten des Salons und Arbeitszeit der Person')}
  ${step(2, 'Kalender abziehen', 'Alles, was im Kalender belegt ist, plus ' + S.rules.buffer + ' Min. Puffer')}
  ${step(3, 'Zurückschreiben', 'Jede Buchung landet sofort als Termin im Google Kalender und damit am Handy')}
</section>
<div class="cards">${STAFF.map(card).join('')}${pending}</div>
<section class="panel" style="display:flex;flex-wrap:wrap;gap:20px 40px">
  <fieldset style="flex:1 1 320px">
    <legend style="font-size:17px;color:var(--ink);margin-bottom:4px">Was blockiert einen Termin?</legend>
    <p class="muted" style="font-size:13px;line-height:1.5;margin-bottom:8px">Maßgeblich ist, wie der Termin im Google Kalender eingetragen ist. Achtung: Ganztägige Termine sind dort standardmäßig „Verfügbar“. Im Test sind z. B. donnerstags die Fortbildung von Andrea (Abwesend) und dienstags der Nachmittag von Pamela (Vielleicht) eingetragen.</p>
    ${blockers}
  </fieldset>
  <div class="note" style="flex:1 1 320px;align-self:flex-start">${ic('shield', 22)}<div><b style="font-size:14px">Datenschutz</b><span>Gelesen wird nur „frei“ oder „belegt“. Betreff, Ort, Teilnehmer und Inhalte privater Termine werden weder angezeigt noch gespeichert.</span></div></div>
</section>`;
  }

  /* ---------- Router und Darstellung ---------- */
  const app = document.getElementById('app');
  const ROUTES = {
    '': viewSalon, termin: viewZeit, buchen: viewBuchen, bestaetigt: viewBestaetigt, meine: viewMeine,
    verwaltung: viewKalender, 'verwaltung/oeffnungszeiten': viewOeffnung, 'verwaltung/team': viewTeam,
  };
  const TITLES = {
    '': 'Online-Termin', termin: 'Termin wählen', buchen: 'Buchung abschließen', bestaetigt: 'Termin gebucht', meine: 'Meine Termine',
    verwaltung: 'Verwaltung · Kalender', 'verwaltung/oeffnungszeiten': 'Verwaltung · Öffnungszeiten', 'verwaltung/team': 'Verwaltung · Team & Kalender',
  };
  const route = () => location.hash.replace(/^#\/?/, '').replace(/\/$/, '');
  let lastRoute = null;

  function render() {
    const r = ROUTES[route()] ? route() : '';
    const inner = ROUTES[r]();
    if (ROUTES[route()] && route() !== r) return;
    if (!inner) return; // Ansicht hat weitergeleitet
    const admin = r.indexOf('verwaltung') === 0;
    app.innerHTML = banner() + (admin ? adminShell(r, inner) : `<div class="cust"><div class="phone">${inner}</div></div>`);
    document.title = TITLES[r] + ' – ' + SALON.name + ' (Entwurf)';
    if (lastRoute !== null && r !== lastRoute) {
      const h = app.querySelector('h1');
      if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
    }
    lastRoute = r;
  }
  function rerender() {
    const a = document.activeElement;
    let sel = null;
    if (a && app.contains(a) && a.dataset) {
      const k = a.dataset.act ? 'act' : a.dataset.change ? 'change' : null;
      if (k) {
        sel = '[data-' + k + '="' + CSS.escape(a.dataset[k]) + '"]';
        if (a.dataset.arg != null) sel += '[data-arg="' + CSS.escape(a.dataset.arg) + '"]';
        if (a.dataset.st) sel += '[data-st="' + CSS.escape(a.dataset.st) + '"]';
      }
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
    toastTimer = setTimeout(() => t.classList.remove('show'), 3400);
  }

  function downloadIcs(id) {
    const b = S.bookings.find((x) => x.id === id);
    if (!b) return;
    const s = svc(b.svc), d = fromKey(b.key);
    const z = (x) => x.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
    const t = (x) => String(x).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n');
    const ics = [
      'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TreatBetter//Entwurf//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
      'BEGIN:VEVENT', 'UID:' + b.id + '@treatbetter-entwurf', 'DTSTAMP:' + z(new Date()),
      'DTSTART:' + z(at(d, b.start)), 'DTEND:' + z(at(d, b.end)),
      'SUMMARY:' + t(s.name + ' – ' + SALON.name),
      'LOCATION:' + t(SALON.street + ', ' + SALON.city),
      'DESCRIPTION:' + t('Testtermin aus dem Entwurf, nicht wirklich gebucht. Bezahlung vor Ort.'),
      'END:VEVENT', 'END:VCALENDAR',
    ].join('\r\n');
    const url = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'termin-friseur-zechmeister.ics';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  function submitBooking(f) {
    const v = (n) => f.elements.namedItem(n);
    const F = U.form = { name: v('name').value.trim(), mail: v('mail').value.trim(), tel: v('tel').value.trim(), agb: v('agb').checked };
    const E = {};
    if (F.name.length < 2) E.name = 'Bitte gib deinen Namen ein.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(F.mail)) E.mail = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    if (F.tel.replace(/\D/g, '').length < 6) E.tel = 'Bitte gib eine Telefonnummer ein.';
    if (!F.agb) E.agb = 'Bitte bestätige die Buchungsbedingungen.';
    const s = svc(U.svc), d = fromKey(U.slot.key), t = U.slot.t;
    const cands = slotMap(d, s.min).get(t) || [];
    if (!cands.length) E.slot = 'Diese Uhrzeit ist leider nicht mehr frei. Bitte wähle eine andere.';
    U.errors = E;
    if (Object.keys(E).length) {
      render();
      const first = app.querySelector('[aria-invalid="true"]');
      if (first) first.focus();
      return;
    }
    const staff = U.staff !== 'any' ? U.staff : cands.slice().sort((a, b) => countBookings(a, d) - countBookings(b, d))[0];
    const b = { id: 'test-' + Date.now().toString(36), key: U.slot.key, start: t, end: t + s.min, staff, svc: s.id, name: F.name, mail: F.mail, tel: F.tel };
    S.bookings.push(b);
    S.lastBooking = b.id;
    save();
    U.errors = {};
    U.slot = null;
    U.form.agb = false;
    go('#/bestaetigt');
  }

  /* ---------- Aktionen ---------- */
  const ACT = {
    menu: () => {
      U.menu = !U.menu;
      rerender();
      const target = U.menu ? app.querySelector('.menu .menu-item') : app.querySelector('[data-act="menu"]');
      if (target) target.focus();
    },
    'menu-book': () => {
      U.menu = false;
      render();
      const sec = document.getElementById('leistungen');
      if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const tab = app.querySelector('.tab[aria-pressed="true"]');
      if (tab) tab.focus({ preventScroll: true });
    },
    cat: (id) => { U.cat = id; rerender(); },
    'pick-svc': (id) => { U.svc = id; U.slot = null; U.day = null; U.week = 0; go('#/termin'); },
    staff: (id) => { U.staff = id; rerender(); },
    week: (dir) => { U.week += Number(dir); U.day = null; rerender(); },
    day: (key) => { U.day = key; rerender(); },
    'goto-day': (key) => { U.week = Math.floor(dayDiff(today(), fromKey(key)) / 7); U.day = key; rerender(); },
    slot: (t) => { U.slot = { key: U.day, t: Number(t) }; U.errors = {}; go('#/buchen'); },
    ics: (id) => downloadIcs(id),
    cancel: (id) => {
      if (!window.confirm('Diesen Testtermin stornieren?')) return;
      S.bookings = S.bookings.filter((b) => b.id !== id);
      if (S.lastBooking === id) S.lastBooking = null;
      save();
      toast('Termin storniert. Die Zeit ist wieder frei.');
      if (route() === 'bestaetigt') go('#/'); else rerender();
    },
    'admin-day': (key) => { U.adminDay = key; },
    'admin-shift': (n) => {
      const base = U.adminDay ? fromKey(U.adminDay) : today();
      U.adminDay = n === '0' ? null : keyOf(addDays(base, Number(n)));
      rerender();
    },
    status: (id, el) => {
      S.status[id] = el.dataset.st;
      save();
      rerender();
    },
    'day-open': (wd) => { S.hours[wd].open = !S.hours[wd].open; save(); rerender(); },
    'auto-holidays': () => { S.autoHolidays = !S.autoHolidays; save(); rerender(); },
    bookable: (id) => {
      S.bookable[id] = !S.bookable[id];
      if (U.staff === id) U.staff = 'any';
      save();
      rerender();
      toast(staffById(id).name + (S.bookable[id] ? ' ist wieder online buchbar.' : ' ist jetzt online nicht buchbar.'));
    },
    save: () => toast('Gespeichert. Die Buchungsseite verwendet die neuen Einstellungen sofort.'),
    soon: (what) => toast('„' + what + '“ ist im Entwurf noch nicht ausgearbeitet.'),
    reset: () => {
      if (!window.confirm('Alle Testbuchungen und geänderten Einstellungen in diesem Browser zurücksetzen?')) return;
      S = defaults();
      try { window.localStorage.removeItem(STORE); } catch (e) { /* ignorieren */ }
      U = initialUi();
      toast('Testdaten zurückgesetzt.');
      go('#/');
    },
  };
  const CHG = {
    'day-from': (wd, el) => setTime(wd, 'from', el),
    'day-to': (wd, el) => setTime(wd, 'to', el),
    rule: (k, el) => { S.rules[k] = /^\d+$/.test(el.value) ? Number(el.value) : el.value; save(); rerender(); },
    cal: (k, el) => { S.calendars[k] = el.checked; save(); },
    write: (k, el) => { S.writeTo[k] = el.value; save(); },
    blocker: (k, el) => {
      S.blockers[k] = el.checked;
      save();
      const label = (BLOCKERS.find((x) => x[0] === k) || [k, k])[1];
      toast('„' + label + '“ ' + (el.checked ? 'blockiert jetzt Termine.' : 'blockiert keine Termine mehr.'));
    },
  };
  function setTime(wd, which, el) {
    const h = S.hours[wd];
    if (!/^\d{2}:\d{2}$/.test(el.value)) { el.value = h[which]; return; }
    const next = Object.assign({}, h, { [which]: el.value });
    if (toMin(next.from) >= toMin(next.to)) {
      toast('Das Ende muss nach dem Beginn liegen.');
      el.value = h[which];
      return;
    }
    S.hours[wd] = next;
    save();
  }

  app.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-act]');
    if (!el || !app.contains(el)) return;
    const fn = ACT[el.dataset.act];
    if (fn) fn(el.dataset.arg, el, ev);
  });
  app.addEventListener('change', (ev) => {
    const el = ev.target.closest('[data-change]');
    if (el && CHG[el.dataset.change]) CHG[el.dataset.change](el.dataset.arg, el);
  });
  app.addEventListener('input', (ev) => {
    const el = ev.target;
    if (el.form && el.form.dataset.submit === 'book' && el.name) U.form[el.name] = el.type === 'checkbox' ? el.checked : el.value;
  });
  app.addEventListener('submit', (ev) => {
    if (ev.target.dataset.submit === 'book') { ev.preventDefault(); submitBooking(ev.target); }
  });
  app.addEventListener('toggle', (ev) => {
    if (ev.target.classList && ev.target.classList.contains('hours')) U.hoursOpen = ev.target.open;
  }, true);
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && U.menu) ACT.menu();
  });
  window.addEventListener('hashchange', () => { U.menu = false; render(); window.scrollTo(0, 0); });

  render();
})();
