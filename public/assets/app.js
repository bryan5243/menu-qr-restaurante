/* Menú digital: carrito, hojas de plato y pedido, pedido activo y animaciones. */
(() => {
  'use strict';
  const M = window.MENU;
  if (!M) return;
  document.documentElement.classList.add('js');

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const money = n => '$' + (Math.round(n * 100) / 100).toFixed(2);
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = {
    get(k, d) { try { return JSON.parse(localStorage.getItem(M.store + ':' + k)) ?? d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem(M.store + ':' + k, JSON.stringify(v)); } catch { /* modo privado */ } },
    del(k) { try { localStorage.removeItem(M.store + ':' + k); } catch { /* */ } },
  };

  /* ---------- Aviso ---------- */
  const toastEl = $('[data-toast]');
  let toastTimer;
  const toast = msg => { toastEl.textContent = msg; toastEl.classList.add('is-on'); clearTimeout(toastTimer); toastTimer = setTimeout(() => toastEl.classList.remove('is-on'), 2600); };

  /* ---------- Aparición, categorías y mapa ---------- */
  const io = 'IntersectionObserver' in window ? new IntersectionObserver(entries => entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px' }) : null;
  $$('.reveal').forEach(el => io ? io.observe(el) : el.classList.add('is-in'));

  const cats = $('[data-cats]');
  const pills = $$('.cat-pill');
  const sections = $$('[data-section]');
  const onScroll = () => {
    if (cats) cats.classList.toggle('is-stuck', cats.getBoundingClientRect().top <= 0);
    let current = sections[0];
    sections.forEach(s => { if (s.getBoundingClientRect().top < 160) current = s; });
    pills.forEach(p => {
      const on = current && p.getAttribute('href') === '#' + current.id;
      if (on && !p.classList.contains('is-active')) p.parentElement.scrollTo({ left: p.offsetLeft - 16, behavior: 'smooth' });
      p.classList.toggle('is-active', on);
    });
  };
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  const map = $('[data-map]');
  if (map) {
    const load = () => { map.innerHTML = '<iframe src="' + esc(map.dataset.map) + '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Mapa de ubicación"></iframe>'; };
    if ('IntersectionObserver' in window) {
      const mo = new IntersectionObserver(en => { if (en[0].isIntersecting) { load(); mo.disconnect(); } }, { rootMargin: '300px' });
      mo.observe(map);
    } else load();
  }

  $$('[data-copy]').forEach(b => b.addEventListener('click', () => {
    const text = b.parentElement.querySelector('[data-copy-src]').textContent.trim();
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => toast('Número copiado'), () => toast(text));
  }));

  /* ---------- Hojas (plato y carrito) ---------- */
  let lastFocus = null;
  const openSheet = sheet => {
    lastFocus = document.activeElement;
    sheet.hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(() => (sheet.querySelector('[data-focus]') || sheet.querySelector('.sheet-x'))?.focus({ preventScroll: true }), 50);
  };
  const closeSheet = sheet => {
    if (sheet.hidden) return;
    sheet.classList.add('is-closing');
    setTimeout(() => { sheet.hidden = true; sheet.classList.remove('is-closing'); document.body.style.overflow = ''; lastFocus?.focus?.({ preventScroll: true }); }, 240);
  };
  $$('.sheet').forEach(sheet => sheet.addEventListener('click', e => { if (e.target.closest('[data-close]')) closeSheet(sheet); }));
  addEventListener('keydown', e => { if (e.key === 'Escape') $$('.sheet:not([hidden])').forEach(closeSheet); });

  /* ---------- Carrito ---------- */
  let cart = store.get('cart', []);
  const bar = $('[data-cart-bar]');
  const save = () => { store.set('cart', cart); renderBar(); };
  const count = () => cart.reduce((n, i) => n + i.qty, 0);
  const subtotal = () => cart.reduce((n, i) => n + i.qty * i.price, 0);
  const renderBar = () => {
    const n = count();
    bar.hidden = n === 0;
    $('[data-cart-count]').textContent = n;
    $('[data-cart-total]').textContent = money(subtotal());
  };
  const lower1 = s => s.charAt(0).toLowerCase() + s.slice(1);
  const summary = (remove = [], extras = []) => [
    remove.length ? 'Sin ' + remove.map(lower1).join(', sin ') : '',
    extras.length ? '+ ' + extras.join(', + ') : '',
  ].filter(Boolean).join(' · ');
  const addToCart = (dish, qty = 1, note = '', remove = [], extras = []) => {
    const opts = dish.opts || { remove: [], extras: [] };
    const price = Math.round((dish.price + opts.extras.filter(x => extras.includes(x.name)).reduce((n, x) => n + x.price, 0)) * 100) / 100;
    const key = JSON.stringify([dish.id, remove, extras, note]);
    const line = cart.find(i => JSON.stringify([i.id, i.remove || [], i.extras || [], i.note || '']) === key);
    if (line) line.qty = Math.min(50, line.qty + qty);
    else cart.push({ id: dish.id, name: dish.name, price, img: dish.img, art: dish.art, qty, note, remove, extras });
    save();
    bar.classList.remove('is-bump'); void bar.offsetWidth; bar.classList.add('is-bump');
  };
  const fly = (from, img) => {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || !from) return;
    const a = from.getBoundingClientRect();
    const el = document.createElement('img');
    el.src = img; el.className = 'fly'; el.alt = '';
    el.style.left = a.left + a.width / 2 - 27 + 'px'; el.style.top = a.top + a.height / 2 - 27 + 'px';
    document.body.append(el);
    requestAnimationFrame(() => {
      const b = (bar.hidden ? { left: innerWidth / 2, top: innerHeight - 40, width: 0, height: 0 } : $('[data-cart-count]').getBoundingClientRect());
      el.style.transform = `translate(${b.left + b.width / 2 - a.left - a.width / 2}px, ${b.top + b.height / 2 - a.top - a.height / 2}px) scale(.3)`;
      el.style.opacity = '.4';
    });
    setTimeout(() => el.remove(), 750);
  };
  const dishOf = el => JSON.parse(el.closest('[data-dish]').dataset.dish);
  const hasOpts = d => d.opts && (d.opts.remove.length || d.opts.extras.length);

  $$('[data-add]').forEach(btn => btn.addEventListener('click', e => {
    e.stopPropagation();
    const dish = dishOf(btn);
    // Si el plato tiene opciones, se abre su hoja para elegirlas.
    if (hasOpts(dish)) { openDish(btn); return; }
    fly(btn, dish.img);
    addToCart(dish);
    btn.classList.remove('is-done'); void btn.offsetWidth; btn.classList.add('is-done');
    toast(dish.name + ' agregado');
  }));

  /* Hoja del plato */
  const ds = $('#dish-sheet');
  let dsDish = null, dsQty = 1;
  const removeBox = $('[data-ds-remove]');
  const extrasBox = $('[data-ds-extras]');
  const picked = box => $$('input:checked', box).map(i => i.value);
  const dsUnit = () => dsDish.price + (dsDish.opts?.extras || []).filter(x => picked(extrasBox).includes(x.name)).reduce((n, x) => n + x.price, 0);
  const dsRender = () => { $('output', $('[data-ds-qty]')).textContent = dsQty; $('[data-ds-price]').textContent = money(dsUnit() * dsQty); };
  const renderOpts = () => {
    const o = dsDish.opts || { remove: [], extras: [] };
    removeBox.innerHTML = o.remove.map(n => `<label class="opt-chip"><input type="checkbox" value="${esc(n)}"><span>Sin ${esc(lower1(n))}</span></label>`).join('');
    extrasBox.innerHTML = o.extras.map(x => `<label class="opt-row"><input type="checkbox" value="${esc(x.name)}"><span class="opt-box" aria-hidden="true"></span><span class="opt-name">${esc(x.name)}</span><b>${x.price > 0 ? '+' + money(x.price) : 'Gratis'}</b></label>`).join('');
    $('[data-ds-remove-wrap]').hidden = !dsDish.ok || !o.remove.length;
    $('[data-ds-extras-wrap]').hidden = !dsDish.ok || !o.extras.length;
  };
  extrasBox.addEventListener('change', () => dsRender());
  const openDish = btn => {
    dsDish = dishOf(btn); dsQty = 1;
    renderOpts();
    const img = $('[data-ds-img]');
    img.onerror = () => { img.onerror = null; img.src = dsDish.art; };
    img.src = dsDish.img; img.alt = dsDish.name;
    $('[data-ds-name]').textContent = dsDish.name;
    $('[data-ds-desc]').textContent = dsDish.desc;
    const badge = $('[data-ds-badge]'); badge.hidden = !dsDish.badge; badge.textContent = dsDish.badge;
    $('[data-ds-note]').value = '';
    $('.ds-foot', ds).hidden = !dsDish.ok; $('[data-ds-note]').closest('label').hidden = !dsDish.ok; $('[data-ds-sold]').hidden = dsDish.ok;
    dsRender();
    openSheet(ds);
  };
  $$('[data-open]').forEach(btn => btn.addEventListener('click', () => openDish(btn)));
  $$('[data-qty]', ds).forEach(b => b.addEventListener('click', () => { dsQty = Math.max(1, Math.min(50, dsQty + Number(b.dataset.qty))); dsRender(); }));
  $('[data-ds-add]').addEventListener('click', e => {
    const note = $('[data-ds-note]').value.trim().slice(0, 120);
    fly(e.currentTarget, dsDish.img);
    addToCart(dsDish, dsQty, note, picked(removeBox), picked(extrasBox));
    toast(dsQty + ' × ' + dsDish.name + ' agregado');
    closeSheet(ds);
  });

  /* Hoja del pedido */
  const cs = $('#cart-sheet');
  const form = $('[data-cart-form]');
  const errorEl = $('[data-cart-error]');
  const fe = n => form.elements.namedItem(n);
  let active = store.get('order', null); // pedido abierto de esta mesa: se le agregan rondas
  const adding = () => active && active.open && active.kind === 'mesa' && (!M.mesa || M.mesa === active.table);

  const kindInputs = $$('input[name=kind]', form);
  const currentKind = () => (kindInputs.find(i => i.checked) || {}).value || '';
  if (M.mesa && M.kinds.mesa) {
    kindInputs.forEach(i => { i.checked = i.value === 'mesa'; });
    $('[data-cart-mode]').hidden = true;
    $('[data-mesa-fixed]').hidden = false;
    $('[data-mesa-fixed] b').textContent = M.mesa;
    $('[data-mesa-pick]').hidden = true;
    fe('table').value = String(M.mesa);
  } else {
    const first = kindInputs.find(i => i.value === 'llevar') || kindInputs[0];
    if (first) first.checked = true;
  }
  const saved = store.get('customer', {});
  ['name', 'phone', 'address'].forEach(k => { if (saved[k] && fe(k)) fe(k).value = saved[k]; });

  const syncFields = () => {
    const kind = currentKind();
    $$('[data-for]', form).forEach(el => { el.hidden = adding() || !el.dataset.for.split(' ').includes(kind); });
    $('[data-cart-mode]').hidden = adding() || (M.mesa && M.kinds.mesa) || Object.keys(M.kinds).length < 2;
    const pay = (($$('input[name=payment]', form).find(i => i.checked)) || {}).value;
    $$('[data-pay]', form).forEach(el => { el.hidden = el.dataset.pay !== pay; });
    const fee = kind === 'domicilio' && !adding() ? M.fee : 0;
    $('[data-fee-line]').hidden = fee <= 0;
    $('[data-fee]').textContent = money(fee);
    $('[data-cart-sum]').textContent = money(subtotal() + fee);
    const btn = $('[data-cart-submit]');
    btn.disabled = !M.accepting || cart.length === 0;
    btn.textContent = adding() ? 'Agregar a mi pedido' : (kind === 'mesa' ? 'Enviar pedido a cocina' : 'Hacer pedido');
    const note = $('[data-cart-adding]');
    note.hidden = !adding();
    if (adding()) $('span', note).textContent = 'Se agregará a tu pedido #' + active.number + ' de la Mesa ' + active.table + '.';
  };
  form.addEventListener('change', syncFields);

  const renderCart = () => {
    const list = $('[data-cart-items]');
    list.innerHTML = cart.map((i, idx) => `
      <li class="cart-item">
        <img src="${esc(i.img)}" alt="" onerror="this.onerror=null;this.src='${esc(i.art || '')}'">
        <b>${esc(i.name)}${(i.remove?.length || i.extras?.length) ? `<span class="ci-opts">${esc(summary(i.remove, i.extras))}</span>` : ''}</b>
        <div class="cart-item-side" style="grid-row: span 2">
          <span class="qty qty-sm"><button type="button" data-line="${idx}" data-d="-1" aria-label="Quitar uno">−</button><output>${i.qty}</output><button type="button" data-line="${idx}" data-d="1" aria-label="Agregar uno">+</button></span>
          <small>${money(i.qty * i.price)}</small>
        </div>
        <small>${money(i.price)} c/u</small>
        <input class="ci-note" data-note="${idx}" maxlength="120" placeholder="Indicación (opcional)" value="${esc(i.note || '')}" aria-label="Indicación para ${esc(i.name)}">
      </li>`).join('');
    $('[data-cart-empty]').hidden = cart.length > 0;
    syncFields();
  };
  $('[data-cart-items]').addEventListener('click', e => {
    const b = e.target.closest('[data-line]');
    if (!b) return;
    const line = cart[Number(b.dataset.line)];
    line.qty += Number(b.dataset.d);
    if (line.qty <= 0) cart.splice(Number(b.dataset.line), 1);
    save(); renderCart();
    if (!cart.length) setTimeout(() => closeSheet(cs), 400);
  });
  $('[data-cart-items]').addEventListener('input', e => {
    const n = e.target.dataset.note;
    if (n !== undefined) { cart[Number(n)].note = e.target.value.slice(0, 120); store.set('cart', cart); }
  });
  bar.addEventListener('click', () => { errorEl.hidden = true; renderCart(); openSheet(cs); });

  let sending = false;
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (sending || !cart.length) return;
    errorEl.hidden = true;
    const kind = currentKind();
    const body = {
      csrf: M.csrf, kind, code: adding() ? active.code : '',
      table: fe('table').value, name: fe('name').value.trim(), phone: fe('phone').value.trim(),
      address: fe('address') ? fe('address').value.trim() : '', notes: fe('notes').value.trim(),
      payment: (($$('input[name=payment]', form).find(i => i.checked)) || {}).value || '',
      items: cart.map(i => ({ id: i.id, qty: i.qty, note: i.note || '', remove: i.remove || [], extras: i.extras || [] })),
    };
    if (!adding()) {
      let msg = '';
      if (kind === 'mesa' && !body.table) msg = 'Elige tu número de mesa.';
      else if (kind !== 'mesa' && (!body.name || body.phone.replace(/\D/g, '').length < 7)) msg = 'Escribe tu nombre y tu celular.';
      else if (kind === 'domicilio' && body.address.length < 6) msg = 'Escribe la dirección de entrega.';
      else if (kind !== 'mesa' && !body.payment) msg = 'Elige cómo vas a pagar.';
      if (msg) { errorEl.textContent = msg; errorEl.hidden = false; return; }
    }
    sending = true;
    const btn = $('[data-cart-submit]');
    btn.disabled = true; btn.textContent = 'Enviando…';
    try {
      const res = await fetch('pedido.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'No pudimos enviar el pedido.');
      if (kind !== 'mesa') store.set('customer', { name: body.name, phone: body.phone, address: body.address });
      cart = []; save();
      store.set('order', { code: data.code, kind: adding() ? 'mesa' : kind, table: adding() ? active.table : Number(body.table) || 0, open: true, number: active?.number || 0 });
      location.href = 'estado.php?c=' + encodeURIComponent(data.code);
    } catch (err) {
      errorEl.textContent = err.message || 'No hay conexión. Inténtalo de nuevo.';
      errorEl.hidden = false;
      sending = false;
      syncFields();
    }
  });

  /* ---------- Pedido activo: aviso arriba del menú ---------- */
  const aoEl = $('[data-active-order]');
  const checkActive = async () => {
    if (!active?.code) return;
    try {
      const res = await fetch('estado.php?json=1&c=' + encodeURIComponent(active.code), { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok || !data.order.open) { store.del('order'); active = null; aoEl.hidden = true; return; }
      const o = data.order;
      active = { code: o.code, kind: o.kind, table: o.kind === 'mesa' ? Number(o.label.replace(/\D/g, '')) : 0, open: true, number: o.number };
      store.set('order', active);
      aoEl.href = 'estado.php?c=' + encodeURIComponent(o.code);
      $('[data-ao-title]', aoEl).textContent = 'Pedido #' + o.number + ' · ' + o.statusText;
      $('[data-ao-text]', aoEl).textContent = o.eta !== null && o.status === 'preparando' ? 'Listo en unos ' + o.eta + ' min · Ver estado' : (adding() ? 'Lo que agregues se suma a este pedido' : 'Toca para ver cómo va');
      aoEl.hidden = false;
    } catch { /* sin conexión: se reintenta */ }
  };
  checkActive();
  setInterval(checkActive, 20000);

  renderBar();
  if (!M.accepting) bar.querySelector('.cart-bar-text').textContent = 'Ver mi pedido (cerrado ahora)';
})();
