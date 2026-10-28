<?php
/**
 * Header del sitio (usado por las páginas PHP).
 * Requiere: $activePage = 'inicio' | 'ropa' | 'accesorios' | 'juguetes' | 'contacto' | ''
 */
$activePage = $activePage ?? '';
$navClass = static fn(string $key): string => $activePage === $key ? ' class="active"' : '';
?>
<header>
  <div class="header-top">
    🎁 ¡Envío gratis en compras mayores a S/ 100! &nbsp;|&nbsp; Atención 24/7:
    <a href="https://wa.me/51967285628" target="_blank" rel="noopener noreferrer">+51 967 285 628</a>
  </div>
  <div class="header-main container">
    <a href="index.html" class="logo">
      <img src="img/LOGO - ABUBU.png" alt="Abubu – Tienda para bebés">
    </a>

    <nav>
      <ul>
        <li><a href="index.html"<?= $navClass('inicio') ?>>Inicio</a></li>
        <li><a href="ropa.html"<?= $navClass('ropa') ?>>Ropa</a></li>
        <li><a href="accesorios.html"<?= $navClass('accesorios') ?>>Accesorios</a></li>
        <li><a href="juguetes.html"<?= $navClass('juguetes') ?>>Juguetes</a></li>
        <li><a href="contacto.html"<?= $navClass('contacto') ?>>Contacto</a></li>
      </ul>
    </nav>

    <div class="header-actions">
      <div class="search-box">
        <button aria-label="Buscar">🔍</button>
        <input type="text" placeholder="Buscar productos…" aria-label="Buscar productos">
      </div>
      <button class="cart-btn" aria-label="Ver carrito">
        🛒 <span class="cart-label">Carrito</span>
        <span class="cart-count">0</span>
      </button>
      <button class="hamburger" aria-label="Menú">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>
