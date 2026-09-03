{{--
 | The agency app's textarea.
 |
 | The slot carries the value, because a textarea's content IS its value --
 | which also keeps old() handling at the call site, rather than smuggling
 | request state into a presentational component.
--}}
<textarea {{ $attributes->merge([
    'class' => 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm',
]) }}>{{ $slot }}</textarea>
