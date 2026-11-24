@props(['step'])

@php
    $guide = \App\Modules\Demo\Support\GuideSteps::find($step);
    // Only a demo session carries the guide: a real account never sees it and nothing changes for it.
    $demo = session(\App\Modules\Demo\Support\DemoSession::MARK) === true;
@endphp

{{-- Issue #74, S19: a notice in context, not a dialog. It is a native disclosure, so it closes with
     mouse and keyboard and without JavaScript, and closing it never changes the URL. The notice
     sits in the same block as the real control, after it, in normal flow: it never covers a control
     and it never navigates by itself. Its copy comes from GuideSteps and nowhere else. --}}
@if ($demo && $guide !== null)
    <details class="guide" open data-guide="{{ $step }}">
        <summary class="guide-toggle">
            <span class="guide-close">Cerrar la guía</span>
            <span class="guide-repeat">Ver la guía otra vez</span>
            <span class="guide-mark" aria-hidden="true">▾</span>
        </summary>
        <p class="guide-copy">{{ \App\Modules\Demo\Support\GuideSteps::notice($step) }}</p>
    </details>
@endif
