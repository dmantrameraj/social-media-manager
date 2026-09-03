{{--
 | The Super Admin surface's primary action button.
 |
 | No hover state, and that is not an oversight: all five call sites here are
 | written without one, where all nine on the agency side have it. The
 | surfaces had already diverged before anything was extracted -- this records
 | that rather than quietly normalising admin to look like the agency app.
 |
 | Submits by default, as every call site does. See admin/form/input for why
 | this is not shared with the agency component.
--}}
<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white',
]) }}>
    {{ $slot }}
</button>
