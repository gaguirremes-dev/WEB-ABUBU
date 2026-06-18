/* ========== CART ========== */
const CART_KEY = 'abubu_cart';

function getCart()  { return JSON.parse(localStorage.getItem(CART_KEY) || '[]'); }
function saveCart(cart) { localStorage.setItem(CART_KEY, JSON.stringify(cart)); updateCartUI(); }

function addToCart(id, name, price, img) {
  const cart = getCart();
  const existing = cart.find(i => i.id === id);
  if (existing) { existing.qty += 1; }
  else           { cart.push({ id, name, price, img, qty: 1 }); }
  saveCart(cart);
  showToast(`✅ "${name}" añadido al carrito`);
}
function removeFromCart(id) { saveCart(getCart().filter(i => i.id !== id)); }
function changeQty(id, delta) {
  const cart = getCart();
  const item = cart.find(i => i.id === id);
  if (!item) return;
  item.qty += delta;
  if (item.qty <= 0) removeFromCart(id);
  else saveCart(cart);
}

function updateCartUI() {
  const cart  = getCart();
  const total = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const count = cart.reduce((s, i) => s + i.qty, 0);

  document.querySelectorAll('.cart-count').forEach(el => el.textContent = count);
  document.querySelectorAll('.cart-total-amount').forEach(el => el.textContent = `S/ ${total.toFixed(2)}`);

  const container = document.getElementById('cart-items');
  if (!container) return;

  if (cart.length === 0) {
    container.innerHTML = `<div class="cart-empty"><div class="icon">🛒</div><p>Tu carrito está vacío</p></div>`;
    return;
  }
  container.innerHTML = cart.map(item => `
    <div class="cart-item">
      <img src="${item.img}" alt="${item.name}">
      <div class="cart-item-info">
        <h4>${item.name}</h4>
        <div class="price">S/ ${(item.price * item.qty).toFixed(2)}</div>
        <div class="cart-qty">
          <button onclick="changeQty('${item.id}', -1)" aria-label="Restar">−</button>
          <span>${item.qty}</span>
          <button onclick="changeQty('${item.id}', 1)" aria-label="Sumar">+</button>
        </div>
      </div>
      <button class="remove-item" onclick="removeFromCart('${item.id}')" title="Eliminar" aria-label="Eliminar del carrito">✕</button>
    </div>
  `).join('');
}

/* ========== TOAST ========== */
function showToast(msg) {
  let toast = document.getElementById('toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'toast'; toast.className = 'toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
  }
  toast.innerHTML = `<span class="icon">🛍️</span>${msg}`;
  toast.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toast.classList.remove('show'), 3000);
}

/* ========== CART SIDEBAR ========== */
function openCart()  {
  document.getElementById('cart-overlay')?.classList.add('open');
  document.getElementById('cart-sidebar')?.classList.add('open');
}
function closeCart() {
  document.getElementById('cart-overlay')?.classList.remove('open');
  document.getElementById('cart-sidebar')?.classList.remove('open');
}

/* ========== HAMBURGER ========== */
function toggleNav() { document.querySelector('nav')?.classList.toggle('open'); }

/* ========== SEARCH ========== */
function handleSearch(e) {
  if (e.key !== 'Enter') return;
  const q = e.target.value.trim().toLowerCase();
  if (!q) return;
  const pages = { ropa: 'ropa.html', accesorios: 'accesorios.html', juguetes: 'juguetes.html' };
  for (const [key, url] of Object.entries(pages)) {
    if (q.includes(key)) { window.location.href = url; return; }
  }
  window.location.href = 'index.html#nuevos-ingresos';
}

/* ========== CONTACT FORM ========== */
function handleContactForm(e) {
  e.preventDefault();
  const success = document.getElementById('form-success');
  if (success) { success.style.display = 'block'; e.target.reset(); }
  setTimeout(() => { if (success) success.style.display = 'none'; }, 5000);
}

/* ========== HEADER SCROLL ========== */
function initHeaderScroll() {
  const header = document.querySelector('header');
  if (!header) return;
  const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 8);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

/* ========== GSAP ANIMATIONS ========== */
function initAnimations() {
  if (typeof gsap === 'undefined') return;

  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (prefersReduced) return;

  /* Register ScrollTrigger if available */
  if (typeof ScrollTrigger !== 'undefined') {
    gsap.registerPlugin(ScrollTrigger);
  }

  const ease = 'power3.out';

  /* ── Hero entrance (above fold, no ScrollTrigger) ── */
  if (document.querySelector('.hero-text')) {
    /* set() es síncrono: fija el estado invisible ANTES del primer paint */
    gsap.set('.hero-text .tag, .hero-text h1, .hero-text p, .hero-btns', { opacity: 0, y: 20 });
    gsap.set('.hero-img img',  { opacity: 0, x: 44 });
    gsap.set('.hero-badge',    { opacity: 0, x: -22 });

    const heroTl = gsap.timeline({ defaults: { ease, clearProps: 'transform,opacity' } });
    heroTl
      .to('.hero-text .tag',  { opacity: 1, y: 0, duration: 0.5 })
      .to('.hero-text h1',    { opacity: 1, y: 0, duration: 0.65 }, '-=0.25')
      .to('.hero-text p',     { opacity: 1, y: 0, duration: 0.55 }, '-=0.35')
      .to('.hero-btns',       { opacity: 1, y: 0, duration: 0.5  }, '-=0.3')
      .to('.hero-img img',    { opacity: 1, x: 0, duration: 0.8, ease: 'power2.out' }, '-=0.55')
      .to('.hero-badge',      { opacity: 1, x: 0, duration: 0.5, ease: 'back.out(1.7)' }, '-=0.35');
  }

  if (typeof ScrollTrigger === 'undefined') return;

  /* ── Values strip stagger ── */
  if (document.querySelector('.value-item')) {
    gsap.from('.value-item', {
      opacity: 0, y: 32, duration: 0.6,
      stagger: 0.10, ease,
      clearProps: 'transform,opacity',
      overwrite: 'auto',
      scrollTrigger: { trigger: '.values', start: 'top 82%', once: true }
    });
  }

  /* ── Section titles ── */
  gsap.utils.toArray('.section-title').forEach(el => {
    gsap.from(el, {
      opacity: 0, y: 24, duration: 0.6, ease,
      clearProps: 'transform,opacity',
      scrollTrigger: { trigger: el, start: 'top 87%', once: true }
    });
  });

  /* ── Category cards batch reveal ── */
  if (document.querySelector('.cat-card')) {
    gsap.set('.cat-card', { opacity: 0, y: 40 });
    ScrollTrigger.batch('.cat-card', {
      onEnter: (elements) => {
        gsap.to(elements, {
          opacity: 1, y: 0,
          duration: 0.7, stagger: 0.12, ease,
          clearProps: 'transform,opacity',
          overwrite: 'auto'
        });
      },
      start: 'top 87%',
      once: true
    });
  }

  /* ── Product cards batch reveal ── */
  if (document.querySelector('.product-card')) {
    gsap.set('.product-card', { opacity: 0, y: 36 });
    ScrollTrigger.batch('.product-card', {
      onEnter: (elements) => {
        gsap.to(elements, {
          opacity: 1, y: 0,
          duration: 0.65, stagger: 0.08, ease,
          clearProps: 'transform,opacity',
          overwrite: 'auto'
        });
      },
      start: 'top 87%',
      once: true
    });
  }

  /* ── Promo banner reveal ── */
  if (document.querySelector('.promo-banner')) {
    gsap.from('.promo-banner .container > *', {
      opacity: 0, y: 28, duration: 0.7, stagger: 0.14, ease,
      clearProps: 'transform,opacity',
      scrollTrigger: { trigger: '.promo-banner', start: 'top 78%', once: true }
    });
  }

  /* ── Page hero (category pages) ── */
  if (document.querySelector('.page-hero')) {
    gsap.from('.page-hero h1, .page-hero p, .breadcrumb', {
      opacity: 0, y: 20, duration: 0.55, stagger: 0.1, ease,
      clearProps: 'transform,opacity'
    });
  }

  /* ── Shop products reveal ── */
  if (document.querySelector('.shop-products .product-card')) {
    gsap.set('.shop-products .product-card', { opacity: 0, y: 32 });
    ScrollTrigger.batch('.shop-products .product-card', {
      onEnter: (elements) => {
        gsap.to(elements, {
          opacity: 1, y: 0,
          duration: 0.6, stagger: 0.07, ease,
          clearProps: 'transform,opacity',
          overwrite: 'auto'
        });
      },
      start: 'top 87%',
      once: true
    });
  }

  /* ── Footer brand reveal ── */
  if (document.querySelector('.footer-brand')) {
    gsap.from('.footer-brand, .footer-col', {
      opacity: 0, y: 24, duration: 0.55, stagger: 0.1, ease,
      scrollTrigger: { trigger: 'footer', start: 'top 88%', once: true }
    });
  }
}

/* ========== INIT ========== */
document.addEventListener('DOMContentLoaded', () => {
  updateCartUI();
  initHeaderScroll();

  /* Cart */
  document.querySelectorAll('.cart-btn').forEach(btn => btn.addEventListener('click', openCart));
  document.getElementById('cart-overlay')?.addEventListener('click', closeCart);
  document.querySelectorAll('.close-cart').forEach(btn => btn.addEventListener('click', closeCart));

  /* Nav */
  document.querySelector('.hamburger')?.addEventListener('click', toggleNav);

  /* Keyboard: close cart on Escape */
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeCart();
  });

  /* Search */
  document.querySelectorAll('.search-box input').forEach(inp => inp.addEventListener('keydown', handleSearch));

  /* Contact form */
  document.getElementById('contact-form')?.addEventListener('submit', handleContactForm);

  /* Add to cart buttons */
  document.querySelectorAll('.add-cart-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const { id, name, price, img } = this.dataset;
      addToCart(id, name, parseFloat(price), img);
      this.classList.add('added');
      this.textContent = '✓ Añadido';
      setTimeout(() => {
        this.classList.remove('added');
        this.innerHTML = '🛒 Añadir al carrito';
      }, 2000);
    });
  });

  /* Newsletter */
  const newsForm = document.getElementById('newsletter-form');
  newsForm?.addEventListener('submit', e => {
    e.preventDefault();
    showToast('📧 ¡Te has suscrito exitosamente!');
    newsForm.reset();
  });

  /* GSAP — corre después del primer paint */
  requestAnimationFrame(initAnimations);
});
