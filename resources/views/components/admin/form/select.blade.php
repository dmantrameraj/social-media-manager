{{-- The Super Admin surface's select. See admin/form/input for why this is
     not shared with the agency app. --}}
<select {{ $attributes->merge([
    'class' => 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm',
]) }}>
    {{ $slot }}
</select>
