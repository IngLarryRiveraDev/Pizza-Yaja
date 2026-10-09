/* ==========================================================
   ANIMACIONES — GSAP 3.13 + ScrollTrigger + Lenis 1.3
   Se controla 100% desde el HTML con atributos:

   data-anim="subir"       el elemento sube y aparece al entrar en pantalla
   data-anim="cascada"     sus hijos aparecen uno tras otro
   data-anim="lote"        como cascada, pero cada hijo espera a entrar en pantalla
                           (ideal para galerías o listas largas)
   data-anim="zoom"        la <img> de adentro se aleja suavemente (el marco
                           necesita overflow:hidden)
   data-anim="contador"    cuenta desde 0 hasta el número que tiene escrito
   data-anim="lineas"      título: cada línea sube detrás de una máscara (CSS)
   data-anim="cinta"       cinta infinita; acelera con la velocidad del scroll
   data-anim="pizza-hero"  momento wow: entra girando (CSS) y gira con el scroll (aquí)
   data-anim="rebote"      rebota cuando se agrega algo al carrito
   data-anim-hero="1"      elementos del héroe que aparecen al cargar (CSS)
   data-velocidad="-12"    parallax: se desplaza ese % de su alto (solo escritorio)
   data-progreso           barra de progreso de lectura
   data-nav-ocultar        nav que se esconde al bajar y aparece al subir

   API para la página:
   Animaciones.entrar(el)  anima contenido que se acaba de mostrar (ej. una pestaña)
   evento 'carrito:agregado' en document → dispara los data-anim="rebote"

   Reglas: solo se anima transform y opacity, todo usa gsap.from() (sin JS el
   contenido se ve igual) y con "reducir movimiento" no se anima nada.
   ========================================================== */
(function () {
  'use strict';

  // Modo suave: la persona pidió menos movimiento en su teléfono. Nada se
  // desplaza ni gira, pero las cosas siguen apareciendo con fundidos.
  var suave = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (!window.gsap || !window.ScrollTrigger) { window.Animaciones = { entrar: function () {} }; return; }

  gsap.registerPlugin(ScrollTrigger);
  var $$ = function (sel, ctx) { return gsap.utils.toArray(sel, ctx); };

  /* ---------- 1. Scroll suave (Lenis) sincronizado con ScrollTrigger ---------- */
  var lenis = null;
  if (window.Lenis && !suave) {
    lenis = new Lenis({ autoRaf: false, anchors: true, stopInertiaOnNavigate: true });
    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add(function (t) { lenis.raf(t * 1000); });
    gsap.ticker.lagSmoothing(0);

    // Si la página bloquea el scroll (body overflow:hidden, ej. carrito o
    // menú móvil abiertos), Lenis también se detiene.
    new MutationObserver(function () {
      if (document.body.style.overflow === 'hidden') lenis.stop(); else lenis.start();
    }).observe(document.body, { attributes: true, attributeFilter: ['style'] });
  }

  /* ---------- 2. Héroe ----------
     La ENTRADA del héroe es CSS puro (ver animaciones.css) para que arranque
     al instante aunque GSAP tarde en llegar. Aquí solo va lo atado al scroll. */

  /* ---------- 3. Entradas al hacer scroll ---------- */
  var entrada = suave
    ? { autoAlpha: 0, duration: 1, ease: 'power1.out' }
    : { y: 40, autoAlpha: 0, duration: .9, ease: 'power3.out' };

  $$('[data-anim="subir"]').forEach(function (el) {
    gsap.from(el, Object.assign({}, entrada, { scrollTrigger: { trigger: el, start: 'top 88%', once: true } }));
  });

  $$('[data-anim="cascada"]').forEach(function (grupo) {
    gsap.from(grupo.children, {
      y: suave ? 0 : 30, autoAlpha: 0, duration: .7, ease: 'power3.out', stagger: .07,
      scrollTrigger: { trigger: grupo, start: 'top 85%', once: true }
    });
  });

  // Para grillas largas (galería): cada hijo aparece cuando ÉL entra en pantalla,
  // agrupando los que entran juntos para que salgan en cascada.
  $$('[data-anim="lote"]').forEach(function (grupo) {
    var hijos = gsap.utils.toArray(grupo.children);
    gsap.set(hijos, suave ? { autoAlpha: 0 } : { autoAlpha: 0, y: 50, scale: .96 });
    ScrollTrigger.batch(hijos, {
      start: 'top 92%', once: true,
      onEnter: function (lote) {
        gsap.to(lote, { autoAlpha: 1, y: 0, scale: 1, duration: .9, ease: 'power3.out', stagger: .09 });
      }
    });
  });

  $$('[data-anim="zoom"]').forEach(function (marco) {
    var img = marco.querySelector('img');
    if (!img || suave) return;
    gsap.from(img, {
      scale: 1.25, duration: 1.6, ease: 'power2.out',
      scrollTrigger: { trigger: marco, start: 'top 85%', once: true }
    });
  });

  $$('[data-anim="contador"]').forEach(function (el) {
    var final = parseInt(el.textContent, 10);
    if (isNaN(final)) return;
    var obj = { v: 0 };
    gsap.to(obj, {
      v: final, duration: 1.6, ease: 'power2.out',
      onStart: function () { el.textContent = '0'; },
      onUpdate: function () { el.textContent = Math.round(obj.v); },
      scrollTrigger: { trigger: el, start: 'top 90%', once: true }
    });
  });

  // Barra de progreso de lectura (la mueve la persona con su dedo: no marea)
  $$('[data-progreso]').forEach(function (barra) {
    gsap.to(barra, { scaleX: 1, ease: 'none',
      scrollTrigger: { start: 0, end: 'max', scrub: .3 } });
  });

  /* ---------- 4. Efectos con movimiento (se saltan en modo suave) ---------- */
  if (!suave) efectosConMovimiento();

  function efectosConMovimiento() {
  var mm = gsap.matchMedia();

  // La pizza del héroe gira mientras bajás (también en celular: es solo transform)
  $$('[data-anim="pizza-hero"]').forEach(function (pizza) {
    var giro = pizza.querySelector('.pizza-giro') || pizza;
    var seccion = pizza.closest('section') || pizza;
    gsap.to(giro, {
      rotate: 160, ease: 'none',
      scrollTrigger: { trigger: seccion, start: 'top top', end: 'bottom top', scrub: 1 }
    });
  });

  mm.add('(min-width: 900px)', function () {
    // En escritorio el héroe se separa en capas: la pizza baja más lento que el texto
    $$('[data-anim="pizza-hero"]').forEach(function (pizza) {
      var seccion = pizza.closest('section') || pizza;
      gsap.to(pizza, { yPercent: 18, scale: 1.08, ease: 'none',
        scrollTrigger: { trigger: seccion, start: 'top top', end: 'bottom top', scrub: true } });
      var texto = seccion.querySelector('[data-hero-texto]');
      if (texto) gsap.to(texto, { y: -90, autoAlpha: .2, ease: 'none',
        scrollTrigger: { trigger: seccion, start: 'top top', end: 'bottom top', scrub: true } });
    });

    $$('[data-velocidad]').forEach(function (el) {
      gsap.to(el, { yPercent: parseFloat(el.dataset.velocidad) || -10, ease: 'none',
        scrollTrigger: { trigger: el.parentElement, start: 'top bottom', end: 'bottom top', scrub: true } });
    });
  });

  // Cinta infinita: gira sola y acelera según qué tan rápido scrolleás
  $$('[data-anim="cinta"]').forEach(function (cinta) {
    var pista = cinta.querySelector('.cinta-pista');
    if (!pista) return;
    var bucle = gsap.to(pista, { xPercent: -50, ease: 'none', duration: parseFloat(cinta.dataset.duracion) || 40, repeat: -1 });
    ScrollTrigger.create({
      trigger: cinta, start: 'top bottom', end: 'bottom top',
      onToggle: function (self) { self.isActive ? bucle.resume() : bucle.pause(); }, // fuera de pantalla no gasta
      onUpdate: function (self) {
        var extra = Math.min(Math.abs(self.getVelocity()) / 300, 5);
        gsap.to(bucle, { timeScale: 1 + extra, duration: .2, overwrite: true,
          onComplete: function () { gsap.to(bucle, { timeScale: 1, duration: 1 }); } });
      }
    });
  });

  // Nav que se esconde al bajar y vuelve al subir
  $$('[data-nav-ocultar]').forEach(function (nav) {
    ScrollTrigger.create({
      start: 0, end: 'max',
      onUpdate: function (self) {
        var ocultar = self.direction === 1 && self.scroll() > 400;
        nav.classList.toggle('nav-oculta', ocultar);
      }
    });
  });

  /* ---------- 5. Microinteracción: rebote al agregar al carrito ---------- */
  document.addEventListener('carrito:agregado', function () {
    $$('[data-anim="rebote"]').forEach(function (el) {
      gsap.fromTo(el, { scale: 1 }, { scale: 1.3, duration: .16, ease: 'power2.out', yoyo: true, repeat: 1,
        overwrite: true, clearProps: 'transform' });
    });
  });

  } // fin efectosConMovimiento

  /* ---------- 6. Mantener las posiciones al día ----------
     Si la página cambia de alto (pestañas del menú, imágenes que cargan),
     ScrollTrigger recalcula dónde empieza cada animación. */
  var espera;
  new ResizeObserver(function () {
    clearTimeout(espera);
    espera = setTimeout(function () { ScrollTrigger.refresh(); }, 200);
  }).observe(document.body);

  /* ---------- API pública ---------- */
  window.Animaciones = {
    lenis: lenis,
    entrar: function (contenedor) {
      if (!contenedor) return;
      var grupo = contenedor.querySelector('[data-anim="cascada"]') || contenedor;
      gsap.fromTo(grupo.children,
        suave ? { autoAlpha: 0 } : { y: 20, autoAlpha: 0 },
        { y: 0, autoAlpha: 1, duration: .5, ease: 'power2.out', stagger: .05, overwrite: true });
    }
  };
})();
