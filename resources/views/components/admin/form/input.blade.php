{{--
 | The Super Admin surface's text input.
 |
 | A deliberate near-duplicate of the agency one. docs/01-ARCHITECTURE.md §5
 | keeps the surfaces' component namespaces separate so a mis-scoped include
 | cannot carry a screen across them, and the cost of that guarantee is this
 | file. The surfaces are allowed to diverge -- and admin, which shows
 | cross-tenant data, is the one where divergence would be most welcome.
--}}
<input {{ $attributes->merge([
    'class' => 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm',
]) }}>
