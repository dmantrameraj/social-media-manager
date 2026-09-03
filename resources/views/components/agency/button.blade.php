{{--
 | The agency app's primary action button.
 |
 | Defaults to type="submit" because all nine call sites are inside a form and
 | submitting it -- and a button with no type already defaults to submit in
 | HTML, so this states the existing behaviour rather than changing it. A call
 | site that wants otherwise says type="button" and wins, because merge() lets
 | the caller override.
--}}
<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800',
]) }}>
    {{ $slot }}
</button>
