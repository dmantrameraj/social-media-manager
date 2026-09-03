{{--
 | The agency app's text input.
 |
 | Per-surface rather than shared, on purpose. docs/01-ARCHITECTURE.md §5: the
 | portal does not share a layout or component namespace with the agency app,
 | "so a mis-scoped Blade include cannot leak an agency screen into the
 | portal". One components/ namespace spanning all three surfaces would be the
 | on-ramp to exactly that -- harmless for a styled input, and the same
 | directory somebody later adds a post card to.
 |
 | So agency and admin each own their primitives and are free to diverge. The
 | portal has two such call sites in total and gets no component set: two is
 | not duplication, and a component nothing calls is the thing this codebase
 | keeps finding and deleting.
 |
 | $attributes->merge() rather than fixed props, so every call site keeps its
 | id, name, value, type, required and placeholder untouched, and the variants
 | that append their own classes -- disabled:bg-slate-50 on read-only forms,
 | font-mono on the Brand Brain lists -- still work by adding to this.
--}}
<input {{ $attributes->merge([
    'class' => 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm',
]) }}>
