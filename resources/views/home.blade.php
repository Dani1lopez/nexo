@extends('layouts.app')

{{-- Issue #60: the public home page is the 3D landing of design/landing/07-cristal-y-camino.html.
     Dark only, one primary action, and the whole explanation lives in this HTML: the scene is
     progressive enhancement, never the only place where something is said. --}}
@section('landing', true)
@section('public', true)
@section('full_title', 'Nexo — Tesorería para asociaciones')

@section('content')
    {{-- The room: CSS halo, grain and the static thread. The canvas only covers the thread. --}}
    <div class="lp-atmosphere" aria-hidden="true"></div>
    <div class="lp-grain" aria-hidden="true"></div>
    <canvas class="lp-canvas" id="lp-canvas" width="1" height="1" aria-hidden="true" hidden></canvas>
    <div class="lp-fallback" aria-hidden="true">
        <div class="lp-fallback-line"></div>
        <div class="lp-fallback-stops lp-wrap" aria-hidden="true">
            <span style="--c: var(--thread-cuota)"></span>
            <span style="--c: var(--thread-extracto)"></span>
            <span style="--c: var(--thread-conciliacion)"></span>
            <span style="--c: var(--thread-saldos)"></span>
        </div>
    </div>

    {{-- The opening: the camera waits on the thread, and the page says what Nexo is. --}}
    <section class="lp-opening" id="inicio" aria-labelledby="titular">
        <div class="lp-wrap lp-open-grid">
            <div class="lp-open-main">
                <p class="lp-label">Tesorería para asociaciones</p>
                <h1 id="titular" class="lp-display">
                    Las cuentas <em>claras</em><br>
                    para quien venga<br>
                    después.
                </h1>
                <p class="lp-lead">Socios, cuotas y movimientos del banco en un solo sitio. El recorrido de la cuota del socio al saldo conciliado, sin hojas sueltas.</p>
                <div class="lp-cta">
                    <a class="lp-button" href="/login">Entrar a la demo <span class="arrow" aria-hidden="true">→</span></a>
                    <p class="lp-note">Demo en construcción · datos ficticios</p>
                </div>
            </div>
            <div class="lp-open-side">
                <ul class="lp-route" aria-label="Recorrido de la escena">
                    <li style="--c: var(--thread-cuota)"><i aria-hidden="true"></i>Cuota</li>
                    <li style="--c: var(--thread-extracto)"><i aria-hidden="true"></i>Extracto</li>
                    <li style="--c: var(--thread-conciliacion)"><i aria-hidden="true"></i>Conciliación</li>
                    <li style="--c: var(--thread-saldos)"><i aria-hidden="true"></i>Saldos</li>
                </ul>
                <p class="lp-hint">Desplázate para viajar</p>
            </div>
        </div>
    </section>

    {{-- The travel: four doors on one thread. Each chapter is readable on its own. --}}
    <div class="lp-journey" id="lp-journey">
        <section class="lp-chapter" id="cap1" aria-labelledby="cap1-title">
            <span class="lp-ghost" aria-hidden="true">01</span>
            <div class="lp-wrap lp-chapter-inner">
                <div class="lp-head">
                    <p class="lp-label"><span class="n">Capítulo 01</span> · Cuota</p>
                    <h2 id="cap1-title" class="lp-display lp-reveal">Una cuota entra en la asociación.</h2>
                </div>
                <div class="lp-body">
                    <p class="lp-reveal">Ana ha pagado 10,00 € de los 15,00 € de la cuota anual, un pago parcial; Luis ha pagado los 15,00 € completos. Hay dos exenciones. Nexo guarda quién está, cuánto debe cada socio y qué ha pagado.</p>
                    <dl class="lp-meta lp-reveal">
                        <div><dt>Socios</dt><dd>34 · 2 exentos</dd></div>
                        <div><dt>Cuota anual</dt><dd>15,00 €</dd></div>
                        <div><dt>Cobrado</dt><dd class="ok">300,00 €</dd></div>
                        <div><dt>Pendiente</dt><dd class="warn">180,00 €</dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <section class="lp-chapter flip" id="cap2" aria-labelledby="cap2-title">
            <span class="lp-ghost" aria-hidden="true">02</span>
            <div class="lp-wrap lp-chapter-inner">
                <div class="lp-head">
                    <p class="lp-label"><span class="n">Capítulo 02</span> · Extracto</p>
                    <h2 id="cap2-title" class="lp-display lp-reveal">El banco habla en CSV.</h2>
                </div>
                <div class="lp-body">
                    <p class="lp-reveal">El extracto de enero entra como archivo: 18 líneas con fecha, concepto e importe, tal como los dio el banco. No hay cobros reales ni conexión bancaria; solo un archivo que se importa.</p>
                    <dl class="lp-meta lp-reveal">
                        <div><dt>Archivo</dt><dd>extracto-enero.csv</dd></div>
                        <div><dt>Líneas</dt><dd>18</dd></div>
                        <div><dt>Ingreso</dt><dd class="ok">+30,00 €</dd></div>
                        <div><dt>Gasto</dt><dd class="neg">−84,50 €</dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <section class="lp-chapter" id="cap3" aria-labelledby="cap3-title">
            <span class="lp-ghost" aria-hidden="true">03</span>
            <div class="lp-wrap lp-chapter-inner">
                <div class="lp-head">
                    <p class="lp-label"><span class="n">Capítulo 03</span> · Conciliación</p>
                    <h2 id="cap3-title" class="lp-display lp-reveal">Un abono, dos cuotas.</h2>
                </div>
                <div class="lp-body">
                    <p class="lp-reveal">María transfiere 30,00 €. La tesorería lo reparte a mano entre la cuota de 2025 y la de 2026: 15,00 € a cada una. Lo que sobrara quedaría como «sin asignar», a la vista, sin esconderlo.</p>
                    <dl class="lp-meta lp-reveal">
                        <div><dt>Abono</dt><dd class="accent">30,00 €</dd></div>
                        <div><dt>Cuota 2025</dt><dd class="ok">15,00 €</dd></div>
                        <div><dt>Cuota 2026</dt><dd class="ok">15,00 €</dd></div>
                        <div><dt>Sin asignar</dt><dd>0,00 €</dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <section class="lp-chapter flip" id="cap4" aria-labelledby="cap4-title">
            <span class="lp-ghost" aria-hidden="true">04</span>
            <div class="lp-wrap lp-chapter-inner">
                <div class="lp-head">
                    <p class="lp-label"><span class="n">Capítulo 04</span> · Saldos</p>
                    <h2 id="cap4-title" class="lp-display lp-reveal">La junta lee. La tesorería trabaja.</h2>
                </div>
                <div class="lp-body">
                    <p class="lp-reveal">Una sola pantalla con la suma de los movimientos importados del banco, la deuda por ejercicio y lo que sigue sin conciliar. Dos roles —tesorería y junta— y un segundo factor opcional: un código TOTP de seis cifras que puede activarse en cada cuenta cuando se quiera.</p>
                    <dl class="lp-meta lp-reveal">
                        <div><dt>Saldo importado</dt><dd>1.842,50 €</dd></div>
                        <div><dt>Deuda 2026</dt><dd class="warn">180,00 €</dd></div>
                        <div><dt>Sin conciliar</dt><dd class="ok">0,00 €</dd></div>
                        <div><dt>Acceso</dt><dd>2 roles · segundo factor opcional</dd></div>
                    </dl>
                </div>
            </div>
        </section>
    </div>

    {{-- The manifesto: what Nexo is and what this demo does not do, once the camera stops. --}}
    <section class="lp-manifesto" aria-labelledby="que-es">
        <div class="lp-wrap lp-manifesto-grid">
            <h2 id="que-es" class="lp-display lp-reveal">Cuentas claras para el siguiente equipo.</h2>
            <div class="lp-say lp-reveal">
                <p><strong>Nexo es la tesorería de una asociación cultural ficticia.</strong> Reúne socios, cuotas y movimientos del banco, y convierte la conciliación en una decisión visible: qué abono paga qué cuota y qué queda pendiente.</p>
                <h3 class="lp-label" id="incluye">Qué incluye la demo</h3>
                <p>La demo incluye socios, cuota anual con exenciones y pagos, importación de extractos en CSV, conciliación manual con reparto de un abono entre varias cuotas y un resumen de saldos con la deuda por ejercicio.</p>
                <p>Conciliar abonos con cuotas y repartir un abono entre varias cuotas.</p>
            </div>
            <dl class="lp-facts lp-reveal" aria-labelledby="incluye">
                <div><dt>Acceso</dt><dd><b>Dos roles:</b> tesorería edita; junta consulta. Contraseña y un segundo factor opcional: el código TOTP de seis cifras puede activarse en cada cuenta.</dd></div>
                <div><dt>Extracto</dt><dd>Importado de un <b>archivo CSV</b>. No hay cobros reales ni conexión bancaria.</dd></div>
                <div><dt>Conciliación</dt><dd><b>Manual y movimiento a movimiento</b>, con reparto de un abono entre varias cuotas.</dd></div>
                <div><dt>Saldos</dt><dd>Suma de los movimientos importados, deuda por ejercicio y lo que sigue <b>sin conciliar</b>.</dd></div>
            </dl>
            <div class="lp-caveats lp-reveal">
                <p class="lp-label">Lo que esta demo no hace</p>
                <ul>
                    <li><span><b>No hay dinero real ni conexión bancaria.</b> El extracto se importa desde un archivo.</span></li>
                    <li><span><b>No utilices datos personales ni dinero real.</b> Todo lo que se ve es de una asociación ficticia.</span></li>
                    <li><span><b>Esta demo no garantiza cumplimiento fiscal, contable o legal.</b> Ordena las cuentas; no sustituye a una asesoría.</span></li>
                </ul>
            </div>
        </div>
    </section>

    {{-- The close: the same action, at headline size. --}}
    <section class="lp-close" aria-labelledby="cierre">
        <div class="lp-wrap"><div class="lp-close-rule" role="presentation"></div></div>
        <div class="lp-wrap lp-close-grid">
            <div class="lp-close-copy">
                <h2 id="cierre" class="lp-display">El recorrido termina donde empieza: en una cuota bien apuntada.</h2>
                <p>Entra con datos ficticios y recorre la misma escena por dentro.</p>
            </div>
            <div class="lp-close-action">
                <a class="lp-mega" href="/login">Entrar a la demo <span class="arrow" aria-hidden="true">↗</span></a>
                <p class="lp-demo-note">Demo en construcción · Datos ficticios · Sin cobros reales ni conexión bancaria</p>
            </div>
        </div>
    </section>

    {{-- The travel HUD: it belongs to the journey, not to the document, so it stays decorative. --}}
    <div class="lp-hud" id="lp-hud" aria-hidden="true" hidden>
        <span class="cap" id="lp-hud-cap"><b>Cap. 00</b> / 04</span>
        <span class="bar"><i id="lp-hud-bar"></i></span>
        <span class="to" id="lp-hud-word">La escena</span>
    </div>
@endsection
