/* Estado del pedido en vivo: se actualiza cada pocos segundos. */
(() => {
  'use strict';
  const S = window.STATUS;
  if (!S) return;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const money = n => '$' + (Math.round(n * 100) / 100).toFixed(2);
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const steps = ['recibido', 'preparando', 'listo', 'entregado'];
  const icons = {
    recibido: '<path d="M12 4h24v40l-4-3-4 3-4-3-4 3-4-3-4 3z"/><path d="M18 14h12M18 21h12M18 28h7"/>',
    preparando: '<path d="M14 20a8 8 0 1 1 6-13 8 8 0 0 1 14 2 7 7 0 0 1 0 11v4H14z"/><path d="M14 24h20v16H14z"/><path d="M20 30v4M28 30v4"/>',
    listo: '<path d="M6 30h36"/><path d="M9 30a15 15 0 0 1 30 0"/><path d="M24 15v-3M20 12h8"/><path d="M4 36h40"/>',
    entregado: '<path d="M10 25l9 9 19-20"/>',
    cerrado: '<path d="M10 25l9 9 19-20"/>',
    cancelado: '<path d="M12 12l24 24M36 12L12 36"/>',
  };
  const toastEl = $('[data-toast]');
  const toast = msg => { toastEl.textContent = msg; toastEl.classList.add('is-on'); setTimeout(() => toastEl.classList.remove('is-on'), 2800); };
  const card = $('[data-status-card]');
  const ring = $('[data-ring]');
  let order = S.order;
  let prevStatus = order.status;

  const etaText = () => {
    if (order.status === 'recibido') return order.rounds > 1 ? 'Cocina recibió tu nueva ronda.' : 'La cocina ya tiene tu pedido. En un momento te dan el tiempo.';
    if (order.status === 'preparando') {
      if (!order.etaAt) return 'Lo estamos preparando.';
      const min = Math.max(0, Math.ceil((order.etaAt - Date.now()) / 60000));
      return min > 0 ? 'Listo en unos <b>' + min + ' min</b>' : 'Ya casi está…';
    }
    if (order.status === 'listo') return order.kind === 'mesa' ? 'Ya te lo llevan a la mesa.' : (order.kind === 'llevar' ? 'Puedes pasar a retirarlo.' : 'Va en camino a tu dirección.');
    if (order.status === 'entregado') return order.kind === 'mesa' ? '¡Buen provecho! Puedes pedir algo más cuando quieras.' : '¡Gracias por tu pedido!';
    if (order.status === 'cerrado') return '¡Gracias por visitarnos!';
    return 'Este pedido fue cancelado. Si tienes dudas, consulta en caja.';
  };
  const progress = () => {
    const idx = steps.indexOf(order.status);
    if (order.status === 'preparando' && order.etaAt && order.etaTotal) {
      return .4 + .38 * Math.min(1, Math.max(0, 1 - (order.etaAt - Date.now()) / order.etaTotal));
    }
    return idx < 0 ? 1 : [.12, .4, .8, 1][idx];
  };

  const render = () => {
    card.className = 'status-card is-' + order.status;
    $('[data-status-text]').textContent = order.statusText;
    $('[data-status-eta]').innerHTML = etaText();
    $('[data-status-icon]').innerHTML = '<svg class="icon" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (icons[order.status] || icons.recibido) + '</svg>';
    ring.style.strokeDashoffset = String(327 * (1 - progress()));
    const idx = order.status === 'cerrado' ? 4 : steps.indexOf(order.status);
    $$('[data-step]').forEach((li, i) => { li.classList.toggle('is-done', i <= idx); li.classList.toggle('is-now', i === idx + 1 && idx < 3 && order.open); });
    let round = 0;
    $('[data-status-items]').innerHTML = order.items.map(it => {
      let head = '';
      if (order.rounds > 1 && it.round !== round) { round = it.round; head = '<li class="round">' + (round === 1 ? 'Primer pedido' : 'Ronda ' + round) + '</li>'; }
      return head + '<li><span>' + it.qty + ' × ' + esc(it.name) + (it.note ? '<small>' + esc(it.note) + '</small>' : '') + '</span><span>' + money(it.qty * it.price) + '</span></li>';
    }).join('');
    $('[data-status-total]').textContent = money(order.total);
    $$('[data-open-only]').forEach(el => { el.hidden = !order.open; });
    const note = $('[data-call-note]');
    if (note) {
      note.hidden = !order.call;
      note.textContent = order.call === 'cuenta' ? 'Pediste la cuenta. Ya te la llevan.' : 'Llamaste al mesero. Ya viene.';
    }
  };

  const remember = () => {
    try {
      if (order.open) localStorage.setItem(S.store + ':order', JSON.stringify({ code: order.code, kind: order.kind, table: order.kind === 'mesa' ? Number(order.label.replace(/\D/g, '')) : 0, open: true, number: order.number }));
      else localStorage.removeItem(S.store + ':order');
    } catch { /* modo privado */ }
  };

  const beep = () => {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      [0, .18].forEach(t => { const o = ctx.createOscillator(); const g = ctx.createGain(); o.frequency.value = 880; g.gain.setValueAtTime(.2, ctx.currentTime + t); g.gain.exponentialRampToValueAtTime(.001, ctx.currentTime + t + .25); o.connect(g).connect(ctx.destination); o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + .3); });
    } catch { /* sin audio */ }
    if (navigator.vibrate) navigator.vibrate([120, 80, 120]);
  };

  const poll = async () => {
    try {
      const res = await fetch('estado.php?json=1&c=' + encodeURIComponent(order.code), { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok) return;
      order = data.order;
      if (order.status !== prevStatus) {
        if (order.status === 'listo') { beep(); toast('¡Tu pedido está listo!'); }
        else if (order.status === 'preparando') toast('¡Empezaron a preparar tu pedido!');
        prevStatus = order.status;
      }
      remember();
      render();
    } catch { /* sin conexión: se reintenta */ }
  };

  $$('[data-call]').forEach(b => b.addEventListener('click', async () => {
    b.disabled = true;
    try {
      const res = await fetch('estado.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: S.csrf, c: order.code, call: b.dataset.call }) });
      const data = await res.json();
      if (data.ok) { order.call = b.dataset.call; render(); toast(b.dataset.call === 'cuenta' ? 'Pedimos tu cuenta' : 'Avisamos al mesero'); }
    } catch { toast('No hay conexión. Inténtalo de nuevo.'); }
    setTimeout(() => { b.disabled = false; }, 4000);
  }));
  $$('[data-copy]').forEach(b => b.addEventListener('click', () => {
    const text = b.parentElement.querySelector('[data-copy-src]').textContent.trim();
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => toast('Número copiado'), () => toast(text));
  }));

  remember();
  render();
  setInterval(poll, 5000);
  setInterval(render, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
