@props(['step'])

@php
    $guide = \App\Modules\Demo\Support\GuideSteps::find($step);
    // Only a demo session carries the guide: a real account never sees it and nothing changes for it.
    $demo = session(\App\Modules\Demo\Support\DemoSession::MARK) === true;
    // The real control each step names, as a selector of the screen the notice lives on (V2, H3a).
    // This is the only thing the notice knows about the markup: the control itself never moves, a
    // real session never renders it, and nothing here is an inline script (the CSP allows only 'self').
    $targets = [
        \App\Modules\Demo\Support\GuideSteps::PANEL => 'nav.next a[href="/bank"]',
        \App\Modules\Demo\Support\GuideSteps::BANK => 'form.reconcile button[type="submit"]',
        \App\Modules\Demo\Support\GuideSteps::SALDOS => 'p.notice a[href="/bank"]',
    ];
@endphp

{{-- Issue #74, S19: a notice in context, not a dialog. It is a native disclosure, so it closes with
     mouse and keyboard and without JavaScript, and closing it never changes the URL. The notice
     sits in the same block as the real control, after it, in normal flow: it never covers a control
     and it never navigates by itself. Its copy comes from GuideSteps and nowhere else. --}}
@if ($demo && $guide !== null)
    {{-- data-guide-target tells resources/js/guide.js where the focus goes when the visitor closes
         the notice with the keyboard. It is progressive enhancement: without JavaScript the notice
         opens, closes and repeats the same way and the focus stays on the summary, which is a real
         focusable control, so nothing is lost. --}}
    <details class="guide" open data-guide="{{ $step }}" data-guide-target="{{ $targets[$step] ?? '' }}">
        <summary class="guide-toggle">
            <span class="guide-close">Cerrar la guía</span>
            <span class="guide-repeat">Ver la guía otra vez</span>
            <span class="guide-mark" aria-hidden="true">▾</span>
        </summary>
        <p class="guide-copy">{{ \App\Modules\Demo\Support\GuideSteps::notice($step) }}</p>
    </details>
@endif
