@props(['name'])
<span class="avatar" aria-hidden="true">{{ collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') }}</span>
