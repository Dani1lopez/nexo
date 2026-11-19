// Portada · escena 3D (design/landing/07-cristal-y-camino.html, issue #60).
//
// Three.js se importa aquí y solo aquí: este archivo es la entrada de Vite de la portada y
// `resources/views/home.blade.php` es la única vista que la incluye. El resto de la
// aplicación carga `theme.js` y nunca alcanza este trozo.
//
// Reglas que este archivo no puede romper:
// - La página está completa sin él: toda la explicación vive en el HTML de la vista.
// - `prefers-reduced-motion: reduce`: ni bucle ni descarga de Three.js; queda el fotograma
//   estático de CSS (el mismo hilo con sus cuatro paradas).
// - Sin WebGL, o con el contexto perdido: el respaldo estático se queda, sin errores en
//   consola y sin botón de pausa.
// - CSP estricta: ni código en línea, ni `eval`, ni workers con blob. Three.js llega como
//   un trozo del mismo origen.
// - Sin GSAP ni Lenis: scroll nativo y este bucle de requestAnimationFrame.
//
// La escena es un cristal de cuatro facetas (una por etapa) que se une, fluye, se reparte y
// se apila con el scroll, y que responde al cursor. Presupuesto: 4 octaedros, 360 partículas,
// una sola pasada de render, sin postproceso, sin sombras. La luz la pone el halo de CSS.

const body = document.body;
const canvas = document.getElementById('lp-canvas');
const journey = document.getElementById('lp-journey');
const hud = document.getElementById('lp-hud');
const hudCap = document.getElementById('lp-hud-cap');
const hudBar = document.getElementById('lp-hud-bar');
const hudWord = document.getElementById('lp-hud-word');
const pause = document.getElementById('landing-pause');

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const stages = ['Cuota', 'Extracto', 'Conciliación', 'Saldos'];

/** The mounted scene, once there is one: the motion-preference switch needs to tear it down. */
let controller = null;

// Lifecycle counters for the e2e (issue #60 review). The scene is a resource: it must stop
// when nobody sees it and free itself when it goes away. These numbers let the browser tests
// observe that from outside; they never hold content.
const diagnostics = (window.__nexoScene ??= {
    mounted: false,
    pendingRaf: 0,
    disposals: 0,
    disposed: false,
});

// Without JavaScript the static thread is what the visitor sees; this class only enables the
// reveal transitions below, which the reduced-motion media query also cancels.
body.classList.add('lp-js');

/* ------------------------------------------------------------- reveal --- */

function revealAll() {
    for (const element of document.querySelectorAll('.lp-reveal')) {
        element.classList.add('in');
    }
}

function watchReveal() {
    if (!('IntersectionObserver' in window)) {
        revealAll();

        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    observer.unobserve(entry.target);
                }
            }
        },
        { rootMargin: '0px 0px -10% 0px', threshold: 0.1 },
    );

    for (const element of document.querySelectorAll('.lp-reveal')) {
        observer.observe(element);
    }

    // A safety net: no text stays hidden because an observer never fired.
    window.setTimeout(revealAll, 4000);
}

/* ---------------------------------------------------------------- HUD --- */

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

/** Once the page scrolls, the bar turns opaque so the text does not read through it. */
function watchBar() {
    const paint = () => body.classList.toggle('lp-scrolled', window.scrollY > 24);
    window.addEventListener('scroll', paint, { passive: true });
    paint();
}

/** A soft round sprite: points are squares in WebGL unless they get a texture. */
function glowTexture(THREE) {
    const size = 64;
    const sprite = document.createElement('canvas');
    sprite.width = size;
    sprite.height = size;
    const context = sprite.getContext('2d');
    const gradient = context.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    gradient.addColorStop(0, 'rgba(255, 255, 255, 1)');
    gradient.addColorStop(0.35, 'rgba(255, 255, 255, 0.55)');
    gradient.addColorStop(1, 'rgba(255, 255, 255, 0)');
    context.fillStyle = gradient;
    context.fillRect(0, 0, size, size);
    const texture = new THREE.CanvasTexture(sprite);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

function scrollProgress() {
    if (!journey) return 0;

    const rect = journey.getBoundingClientRect();
    const span = journey.offsetHeight - window.innerHeight;

    return span > 0 ? clamp(-rect.top / span, 0, 1) : 0;
}

function paintHud(progress) {
    if (!hud || !hudCap || !hudBar || !hudWord) return;

    const rect = journey?.getBoundingClientRect();
    hud.hidden = !rect || !(rect.top < window.innerHeight && rect.bottom > 0);

    if (progress <= 0.001) {
        hudCap.innerHTML = '<b>Cap. 00</b> / 04';
        hudWord.textContent = 'La escena';
        hudBar.style.width = '0%';

        return;
    }

    const index = clamp(Math.ceil(progress * 4), 1, 4);
    hudCap.innerHTML = `<b>Cap. 0${index}</b> / 04`;
    hudWord.textContent = stages[index - 1];
    hudBar.style.width = `${(progress * 100).toFixed(1)}%`;
}

let hudQueued = false;

function onScroll() {
    if (hudQueued) return;
    hudQueued = true;

    window.requestAnimationFrame(() => {
        hudQueued = false;
        paintHud(scrollProgress());
    });
}

/* -------------------------------------------------------------- scene --- */

/**
 * The scene needs WebGL2: Three.js dropped WebGL1, so a browser that only exposes WebGL1 must
 * go straight to the static frame instead of downloading the library to watch it fail.
 */
function webgl2Available() {
    try {
        const probe = document.createElement('canvas');

        return Boolean(probe.getContext('webgl2'));
    } catch (error) {
        return false;
    }
}

function showFallback() {
    body.classList.remove('lp-canvas-ready');
    body.classList.add('lp-no-canvas');
    canvas?.setAttribute('hidden', '');
    if (pause) pause.hidden = true;
    revealAll();
}

/**
 * Where each of the four facets sits at each stage: [x, y, z, scaleX, scaleY, scaleZ].
 * Stage 0 is one crystal (the fee), 1 a stream (the statement), 2 a split (one deposit, two
 * fees) and 3 a tower (the balances). The scene interpolates between consecutive stages.
 */
const LAYOUTS = [
    [[-0.55, 0.35, 0, 0.9, 1.4, 0.9], [0.6, 0.3, 0.3, 0.8, 1.3, 0.8], [-0.3, -0.6, 0.4, 0.85, 1.3, 0.85], [0.5, -0.5, -0.3, 0.8, 1.2, 0.8]],
    [[-2.6, 0.3, 0, 0.55, 0.55, 1.6], [-0.9, -0.3, 0.4, 0.55, 0.55, 1.6], [0.9, 0.3, -0.4, 0.55, 0.55, 1.6], [2.6, -0.3, 0, 0.55, 0.55, 1.6]],
    [[0, 0.2, 0, 1.5, 1.4, 1.5], [-2.4, -0.9, 0.5, 0.7, 0.7, 0.7], [2.4, -0.9, 0.5, 0.7, 0.7, 0.7], [0, 2.6, -1, 0.3, 0.3, 0.3]],
    [[-2.4, -0.9, 0, 0.6, 0.7, 0.6], [-0.8, -0.4, 0, 0.6, 1.2, 0.6], [0.8, 0.1, 0, 0.6, 1.5, 0.6], [2.4, 0.6, 0, 0.6, 2, 0.6]],
];

/**
 * Where the crystal sits on wide screens, per stage, so it never covers the copy of the chapter
 * it illustrates: the horizontal shift as a share of the visible width (negative is left) and
 * the vertical lift in scene units. The chapters alternate which side holds the text card.
 */
const SHIFT = [0.1, 0.17, -0.17, 0.17];
const LIFT = [1, -0.6, 0.8, -0.9];
const FOV = 56.6;
const DUST = 360;

const smooth = (value) => value * value * (3 - 2 * value);
const lerp = (from, to, amount) => from + (to - from) * amount;

/** The resolved colour of a design token, so the scene never invents a palette. */
function tokenColor(name) {
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)';
    probe.style.color = `var(${name})`;
    body.appendChild(probe);
    const value = window.getComputedStyle(probe).color;
    probe.remove();

    return value;
}

async function mountScene() {
    const THREE = await import('three');

    const renderer = new THREE.WebGLRenderer({
        canvas,
        antialias: true,
        alpha: true,
        powerPreference: 'high-performance',
    });

    renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));
    renderer.setClearColor(0x000000, 0);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 100);
    const glow = glowTexture(THREE);

    const palette = {
        ink: new THREE.Color(tokenColor('--color-ink')),
        cuota: new THREE.Color(tokenColor('--thread-cuota')),
        extracto: new THREE.Color(tokenColor('--thread-extracto')),
        conciliacion: new THREE.Color(tokenColor('--thread-conciliacion')),
        saldos: new THREE.Color(tokenColor('--thread-saldos')),
    };

    /* The crystal: four octahedra, each a translucent body plus its edges, in stage colours. */
    const root = new THREE.Group();
    scene.add(root);
    const octahedron = new THREE.OctahedronGeometry(1, 0);
    const edgeGeometry = new THREE.EdgesGeometry(octahedron);
    const stageColors = [palette.cuota, palette.extracto, palette.conciliacion, palette.saldos];
    const facets = stageColors.map((color) => {
        const body = new THREE.Mesh(
            octahedron,
            new THREE.MeshBasicMaterial({
                color,
                transparent: true,
                opacity: 0.26,
                side: THREE.DoubleSide,
                depthWrite: false,
            }),
        );
        const edges = new THREE.LineSegments(
            edgeGeometry,
            new THREE.LineBasicMaterial({ color, transparent: true, opacity: 0.95 }),
        );
        const group = new THREE.Group();
        group.add(body, edges);
        root.add(group);

        return { group, body, edges };
    });

    /* The thread of light that joins the four facets, in order. */
    const threadPositions = new Float32Array(facets.length * 3);
    const threadGeometry = new THREE.BufferGeometry();
    threadGeometry.setAttribute('position', new THREE.BufferAttribute(threadPositions, 3));
    const threadLine = new THREE.Line(
        threadGeometry,
        new THREE.LineBasicMaterial({ color: palette.ink, transparent: true, opacity: 0.35 }),
    );
    threadLine.frustumCulled = false;
    root.add(threadLine);

    /* Dust: a fixed budget of motes in the stage colours. */
    const dustPositions = new Float32Array(DUST * 3);
    const dustColors = new Float32Array(DUST * 3);
    for (let index = 0; index < DUST; index += 1) {
        dustPositions.set(
            [(Math.random() - 0.5) * 22, (Math.random() - 0.5) * 12, (Math.random() - 0.5) * 18],
            index * 3,
        );
        const color = stageColors[index % 4];
        dustColors.set([color.r, color.g, color.b], index * 3);
    }

    const dustGeometry = new THREE.BufferGeometry();
    dustGeometry.setAttribute('position', new THREE.BufferAttribute(dustPositions, 3));
    dustGeometry.setAttribute('color', new THREE.BufferAttribute(dustColors, 3));

    const dust = new THREE.Points(
        dustGeometry,
        new THREE.PointsMaterial({
            size: 0.16,
            map: glow,
            vertexColors: true,
            transparent: true,
            opacity: 0.7,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            sizeAttenuation: true,
        }),
    );
    root.add(dust);

    const pointer = { x: 0, y: 0 };
    let width = 0;
    let height = 0;
    let progress = scrollProgress();
    let raf = 0;
    let paused = false;
    let elapsed = 0;
    let previous = 0;

    function resize() {
        width = window.innerWidth;
        height = window.innerHeight;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    }

    /** The camera only dollies in a little; the crystal does the travelling. */
    function placeCamera() {
        camera.position.set(0, 0, 9 - progress * 1.4);
        camera.lookAt(0, 0, 0);
    }

    function render() {
        const stage = clamp(progress * (LAYOUTS.length - 1), 0, LAYOUTS.length - 1);
        const from = Math.floor(stage);
        const to = Math.min(LAYOUTS.length - 1, from + 1);
        const blend = smooth(stage - from);
        const spread = width < 720 ? 0.62 : 1;

        facets.forEach((facet, index) => {
            const a = LAYOUTS[from][index];
            const b = LAYOUTS[to][index];
            const value = (slot) => lerp(a[slot], b[slot], blend);
            facet.group.position.set(value(0) * spread, value(1) * spread, value(2));
            facet.group.scale.set(value(3) * spread, value(4) * spread, value(5));
            threadPositions.set(
                [facet.group.position.x, facet.group.position.y, facet.group.position.z],
                index * 3,
            );
        });
        threadGeometry.attributes.position.needsUpdate = true;

        placeCamera();

        // Wide screens move the crystal into the free side of the current chapter.
        const worldWidth = 2 * camera.position.z * Math.tan((FOV * Math.PI) / 360) * camera.aspect;
        const wide = width > 900;
        root.position.x = wide ? worldWidth * lerp(SHIFT[from], SHIFT[to], blend) : 0;
        root.position.y = wide ? lerp(LIFT[from], LIFT[to], blend) : 0;

        root.rotation.y = Math.sin(elapsed * 0.00035) * 0.4 + pointer.x * 0.55 + progress * 0.5;
        root.rotation.x = 0.25 - pointer.y * 0.3;
        dust.position.z = progress * 6;

        renderer.render(scene, camera);
    }

    function stop() {
        if (raf) cancelAnimationFrame(raf);
        raf = 0;
        diagnostics.pendingRaf = 0;
    }

    function loop(now) {
        raf = requestAnimationFrame(loop);
        diagnostics.pendingRaf = 1;
        const delta = previous === 0 ? 16 : Math.min(64, now - previous);
        previous = now;
        elapsed += delta;
        progress += (scrollProgress() - progress) * 0.08;
        paintHud(progress);
        render();
    }

    function start() {
        stop();
        previous = 0;
        raf = requestAnimationFrame(loop);
        diagnostics.pendingRaf = 1;
    }

    function setPaused(next) {
        paused = next;
        if (pause) {
            pause.setAttribute('aria-pressed', String(paused));
            pause.setAttribute('aria-label', paused ? 'Reanudar la animación de la escena' : 'Pausar la animación de la escena');
            const label = pause.querySelector('.txt');
            if (label) label.textContent = paused ? 'Reanudar escena' : 'Pausar escena';
        }

        if (paused) {
            stop();
            paintHud(progress);
            renderer.render(scene, camera);
        } else {
            sync();
        }
    }

    /**
     * Everything the scene created, so teardown can free it. WebGL memory is not garbage
     * collected: without this, a lost context or a motion switch leaks buffers and programs.
     */
    const disposables = {
        geometries: [octahedron, edgeGeometry, threadGeometry, dustGeometry],
        materials: [
            threadLine.material,
            dust.material,
            ...facets.flatMap((facet) => [facet.body.material, facet.edges.material]),
        ],
        textures: [glow],
    };

    let disposed = false;
    // Where the scene is actually seen: the opening and the journey. The manifesto and the
    // closing section are opaque, so covering them means there is nothing to animate.
    const stageTargets = ['#inicio', '#lp-journey']
        .map((selector) => document.querySelector(selector))
        .filter((element) => element !== null);
    const inView = new Map();
    let visible = true;

    function shouldRun() {
        return !disposed && !paused && !document.hidden && visible && !reducedMotion.matches;
    }

    /** One decision point: the loop runs only while the scene is mounted, visible and wanted. */
    function sync() {
        if (shouldRun()) start();
        else stop();
    }

    const observer = typeof IntersectionObserver === 'function' && stageTargets.length > 0
        ? new IntersectionObserver(
              (entries) => {
                  for (const entry of entries) inView.set(entry.target, entry.isIntersecting);
                  visible = [...inView.values()].some(Boolean);
                  sync();
              },
              { threshold: 0 },
          )
        : null;

    function onResize() {
        if (disposed) return;
        resize();
        paintHud(progress);
    }

    function onPointer(event) {
        pointer.x = (event.clientX / window.innerWidth - 0.5) * 2;
        pointer.y = (event.clientY / window.innerHeight - 0.5) * 2;
    }

    function onVisibility() {
        if (disposed) return;
        if (document.hidden) stop();
        else sync();
    }

    function onContextLost(event) {
        // A lost context is not an error to report: the static fallback is the answer.
        event.preventDefault();
        destroy();
    }

    function onPauseClick() {
        setPaused(!paused);
    }

    /** Frees every geometry, material, texture and the renderer itself. */
    function dispose() {
        let count = 0;
        for (const item of [...disposables.geometries, ...disposables.materials, ...disposables.textures]) {
            if (typeof item?.dispose === 'function') {
                item.dispose();
                count += 1;
            }
        }
        renderer.dispose();
        count += 1;
        diagnostics.disposals = count;
        diagnostics.disposed = true;
    }

    /** Stops the loop, detaches every handler it registered and frees the GPU resources. */
    function destroy() {
        if (disposed) return;
        disposed = true;
        stop();
        observer?.disconnect();
        window.removeEventListener('resize', onResize);
        window.removeEventListener('pointermove', onPointer);
        document.removeEventListener('visibilitychange', onVisibility);
        canvas.removeEventListener('webglcontextlost', onContextLost);
        pause?.removeEventListener('click', onPauseClick);
        dispose();
        diagnostics.mounted = false;
        showFallback();
    }

    resize();
    placeCamera();
    paintHud(progress);
    body.classList.add('lp-canvas-ready');
    canvas.removeAttribute('hidden');
    diagnostics.mounted = true;

    if (pause) {
        pause.hidden = false;
        pause.addEventListener('click', onPauseClick);
    }

    window.addEventListener('resize', onResize);
    window.addEventListener('pointermove', onPointer, { passive: true });
    document.addEventListener('visibilitychange', onVisibility);
    canvas.addEventListener('webglcontextlost', onContextLost);

    if (observer) {
        for (const target of stageTargets) {
            inView.set(target, true);
            observer.observe(target);
        }
    }

    sync();

    return { sync, destroy };
}

/* -------------------------------------------------------------- boot ---- */

watchReveal();
watchBar();
onScroll();
window.addEventListener('scroll', onScroll, { passive: true });

async function boot() {
    if (reducedMotion.matches || !webgl2Available()) {
        showFallback();

        return;
    }

    try {
        controller = await mountScene();
    } catch (error) {
        // A failed chunk or a renderer that cannot start: the static frame is the answer.
        // (Three.js logs its own diagnostic before throwing; that line is not ours to hide.)
        showFallback();
    }
}

boot();

// A motion preference that changes while the page is open must free the scene, not just hide it.
reducedMotion.addEventListener('change', (event) => {
    if (!event.matches) return;
    if (controller) controller.destroy();
    else showFallback();
});
