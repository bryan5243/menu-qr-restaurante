/* Pantalla de cocina: muestra los pedidos abiertos y los avanza con un toque. */
(() => {
  'use strict';
  const K = window.KITCHEN;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = n => '$' + Number(n).toFixed(2);
  const board = $('[data-k-board]');
  let orders = [];
  let skew = 0; // diferencia entre el reloj del servidor y el de este equipo
  let known = null; // ids + rondas ya vistos, para avisar lo nuevo
  let sound = false;
  let busy = false;

  const ago = t => {
    const min = Math.floor((Date.now() + skew - t) / 60000);
    return min < 1 ? 'recién' : (min < 60 ? 'hace ' + min + ' min' : 'hace ' + Math.floor(min / 60) + ' h ' + (min % 60) + ' min');
  };
  const left = o => o.etaAt ? Math.ceil((o.etaAt - Date.now() - skew) / 60000) : null;
  const kindTag = o => ({ mesa: 'k-mesa', llevar: 'k-llevar', domicilio: 'k-domicilio' }[o.kind]);
  const title = o => o.kind === 'mesa' ? o.label : (o.kind === 'llevar' ? 'Para llevar' : 'Domicilio');

  const card = o => {
    const minutes = left(o);
    const late = o.status === 'preparando' && minutes !== null && minutes < 0;
    const age = Math.floor((Date.now() + skew - o.updated) / 60000);
    let round = 0;
    const items = o.items.map(it => {
      let head = '';
      if (o.rounds > 1 && it.round !== round) {
        round = it.round;
        const isNew = round === o.rounds && (o.status === 'recibido' || o.status === 'preparando');
        head = `<li class="k-round${isNew ? ' is-new' : ''}">${round === 1 ? 'Primer pedido' : 'Ronda ' + round}${isNew ? ' · NUEVA' : ''}</li>`;
      }
      const old = o.rounds > 1 && it.round < o.rounds && (o.status === 'recibido' || o.status === 'preparando');
      return head + `<li class="${old ? 'is-old' : ''}"><b>${it.qty}×</b> ${esc(it.name)}${it.note ? `<em>${esc(it.note)}</em>` : ''}</li>`;
    }).join('');
    const etaBtns = [10, 15, 20, 30, 45].map(m => `<button class="k-chip${m === K.eta ? ' is-default' : ''}" data-act="eta" data-min="${m}">${m} min</button>`).join('');
    let actions = '';
    if (o.status === 'recibido') actions = `<p class="k-label">¿En cuánto estará listo?</p><div class="k-chips">${etaBtns}</div><button class="k-cancel" data-act="cancelar">Cancelar pedido</button>`;
    else if (o.status === 'preparando') actions = `<div class="k-row"><button class="k-btn k-btn-ghost" data-act="more">+5 min</button><button class="k-btn k-btn-go" data-act="listo">Listo</button></div>`;
    else if (o.status === 'listo') actions = `<button class="k-btn k-btn-go" data-act="entregado">${o.kind === 'mesa' ? 'Servido en la mesa' : (o.kind === 'llevar' ? 'Retirado y cobrado' : 'Entregado y cobrado')}</button>`;
    else actions = `<button class="k-btn k-btn-dark" data-act="cerrar">Cobrado · cerrar cuenta ${money(o.total)}</button>`;
    const call = o.call ? `<div class="k-call k-call-${o.call}">${o.call === 'cuenta' ? '🧾 Pide la cuenta' : '🔔 Llama al mesero'}<button data-act="atendido">Atendido</button></div>` : '';
    const who = o.kind !== 'mesa' ? `<p class="k-who"><b>${esc(o.name)}</b> · <a href="tel:${esc(o.phone)}">${esc(o.phone)}</a>${o.kind === 'domicilio' ? `<br>${esc(o.address)}` : ''}</p>` : '';
    const pay = o.kind !== 'mesa' ? `<span class="k-pay k-pay-${esc(o.payment)}">${esc(o.paymentText)}</span>` : '';
    return `<article class="k-card ${kindTag(o)}${late ? ' is-late' : ''}${o.call ? ' has-call' : ''}${o.status === 'recibido' && age < 1 ? ' is-fresh' : ''}" data-id="${o.id}">
      ${call}
      <header><h3>${esc(title(o))}</h3><span class="k-num">#${o.number}</span></header>
      <p class="k-meta"><span>${ago(o.created)}</span>${o.status === 'preparando' && minutes !== null ? `<span class="k-eta">${minutes >= 0 ? 'listo en ' + minutes + ' min' : 'atrasado ' + (-minutes) + ' min'}</span>` : ''}${pay}</p>
      ${who}
      <ul class="k-items">${items}</ul>
      ${o.notes ? `<p class="k-notes">📝 ${esc(o.notes)}</p>` : ''}
      <footer>${actions}</footer>
    </article>`;
  };

  const render = () => {
    const cols = { recibido: [], preparando: [], listo: [], entregado: [] };
    orders.forEach(o => cols[o.status]?.push(o));
    Object.entries(cols).forEach(([status, list]) => {
      const col = $(`[data-col="${status}"]`, board);
      $('h2 span', col).textContent = list.length;
      $('.k-list', col).innerHTML = list.length ? list.map(card).join('') : `<p class="k-empty">${{ recibido: 'Sin pedidos nuevos', preparando: 'Nada en preparación', listo: 'Nada por entregar', entregado: 'Sin mesas atendidas' }[status]}</p>`;
    });
    const calls = orders.filter(o => o.call);
    $('[data-k-calls]').innerHTML = calls.map(o => `<span class="k-call-pill k-call-${o.call}">${o.call === 'cuenta' ? '🧾' : '🔔'} ${esc(o.label)} ${o.call === 'cuenta' ? 'pide la cuenta' : 'llama al mesero'}</span>`).join('');
  };

  const beep = () => {
    if (!sound) return;
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      [0, .2, .4].forEach((t, i) => { const o = ctx.createOscillator(); const g = ctx.createGain(); o.type = 'triangle'; o.frequency.value = [660, 880, 1100][i]; g.gain.setValueAtTime(.25, ctx.currentTime + t); g.gain.exponentialRampToValueAtTime(.001, ctx.currentTime + t + .35); o.connect(g).connect(ctx.destination); o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + .4); });
    } catch { /* sin audio */ }
  };

  const load = async () => {
    try {
      const res = await fetch('cocina.php?json=1', { cache: 'no-store' });
      if (res.status === 401) { location.href = 'index.php?caducada=1'; return; }
      const data = await res.json();
      skew = data.now - Date.now();
      orders = data.orders;
      const sig = new Set(orders.map(o => o.id + ':' + o.rounds + ':' + (o.call || '')));
      const fresh = orders.filter(o => !known?.has(o.id + ':' + o.rounds + ':' + (o.call || '')) && (o.call || o.status === 'recibido'));
      if (known && fresh.length) beep();
      known = sig;
      const res2 = $('[data-k-res]');
      res2.hidden = !data.reservations;
      $('span', res2).textContent = data.reservations + (data.reservations === 1 ? ' reserva por confirmar' : ' reservas por confirmar');
      $('[data-k-sync]').textContent = 'Actualizado ' + new Date().toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      if (!busy) render();
    } catch {
      $('[data-k-sync]').textContent = 'Sin conexión, reintentando…';
    }
  };

  board.addEventListener('click', async e => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const id = Number(btn.closest('[data-id]').dataset.id);
    const action = btn.dataset.act;
    if (action === 'cancelar' && !confirm('¿Cancelar este pedido?')) return;
    busy = true;
    btn.disabled = true;
    btn.closest('.k-card').classList.add('is-busy');
    try {
      const res = await fetch('cocina.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: K.csrf, id, action, minutes: Number(btn.dataset.min || 0) }) });
      const data = await res.json();
      if (!data.ok) alert(data.error || 'No se pudo actualizar.');
    } catch { alert('Sin conexión. Inténtalo de nuevo.'); }
    busy = false;
    load();
  });

  const soundBtn = $('[data-k-sound]');
  soundBtn.addEventListener('click', () => {
    sound = !sound;
    soundBtn.classList.toggle('is-on', sound);
    $('span', soundBtn).textContent = sound ? 'Sonido activado' : 'Activar sonido';
    if (sound) beep();
  });
  $('[data-k-full]').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.();
  });

  load();
  setInterval(load, 4000);
  setInterval(() => { if (!busy) render(); }, 30000);
})();
