{{--
  A shared empty state. Every list uses it, so a first-time user is always told
  what the screen is for and what to do next, rather than seeing a blank panel
  and assuming something is broken.
--}}
<div class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
    <p class="text-sm font-medium text-slate-900">{{ $title }}</p>
    <p class="mx-auto mt-1 max-w-md text-sm text-slate-600">{{ $description }}</p>
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
