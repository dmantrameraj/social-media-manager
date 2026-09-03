{{--
 | The agency app's select, styled to match the input beside it. A select that
 | drifts from the field next to it is the kind of small wrongness nobody files
 | a bug about and everybody notices.
--}}
<select {{ $attributes->merge([
    'class' => 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm',
]) }}>
    {{ $slot }}
</select>
