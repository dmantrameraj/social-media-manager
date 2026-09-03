@extends('layouts.agency')

@section('content')
    @if ($brands->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No brands yet',
            'description' => 'Media belongs to a brand, so create one first.',
        ])
    @else
        <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
            <form method="GET" class="flex items-end gap-2">
                <div>
                    <label for="brand" class="block text-sm font-medium">Brand</label>
                    <select id="brand" name="brand"
                            class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->getKey() }}" @selected($selected === $brand->getKey())>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                    Show
                </button>
            </form>

            @if ($canUpload)
                <form method="POST" action="{{ route('agency.media.store') }}"
                      enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="brand" value="{{ $selected }}">
                    <div>
                        <label for="file" class="block text-sm font-medium">Upload</label>
                        <input id="file" name="file" type="file" required
                               class="mt-1 block text-sm file:mr-3 file:rounded-lg file:border-0
                                      file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white">
                    </div>

                    {{--
                     | Asked for here, at upload, while whoever chose the file is
                     | still looking at it. Deferring to a later "add your alt
                     | text" screen reliably produces "photo" and "image1".
                     |
                     | Not required: a forced field produces a character typed to
                     | get past it, which is worse than absent, because a screen
                     | reader announces it as though it were a description.
                    --}}
                    <div>
                        <label for="alt_text" class="block text-sm font-medium">Describe it</label>
                        <input id="alt_text" name="alt_text" type="text" maxlength="1000"
                               value="{{ old('alt_text') }}"
                               placeholder="What is in this image?"
                               class="mt-1 w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <button type="submit"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                        Add file
                    </button>
                </form>
            @endif
        </div>

        @if ($media->isEmpty())
            @include('agency.partials.empty', [
                'title' => 'No media for this brand',
                'description' => 'Images, video and PDFs uploaded here can be attached to posts.',
            ])
        @else
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($media as $item)
                    <li class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        @php $preview = $previews[$item->getKey()] ?? null; @endphp

                        <div class="grid aspect-square place-items-center overflow-hidden bg-slate-100 text-xs text-slate-500">
                            @if ($preview !== null)
                                {{-- A signed, short-lived, policy-checked route. Never a
                                     disk path: the files sit on a private disk. --}}
                                <img src="{{ $preview }}" alt="{{ $item->describedAs() }}"
                                     loading="lazy" class="h-full w-full object-cover">
                            @else
                                {{-- Video and PDF show their type rather than a thumbnail:
                                     drawing one would stream the whole file to fill a tile. --}}
                                {{ strtoupper($item->extension) }}
                            @endif
                        </div>
                        <div class="p-2">
                            <p class="truncate text-xs font-medium" title="{{ $item->original_name }}">
                                {{ $item->original_name }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ number_format($item->size_bytes / 1024, 0) }} KB
                            </p>

                            @if ($canUpload)
                                {{--
                                 | Editable in place, because every file that
                                 | predates this feature has no description and
                                 | somebody has to be able to add one without
                                 | re-uploading the image.
                                --}}
                                <form method="POST" action="{{ route('agency.media.update', $item) }}"
                                      class="mt-2">
                                    @csrf
                                    @method('PUT')
                                    <label class="sr-only" for="alt-{{ $item->getKey() }}">
                                        Description for {{ $item->original_name }}
                                    </label>
                                    <input id="alt-{{ $item->getKey() }}" name="alt_text" type="text"
                                           maxlength="1000" value="{{ $item->alt_text }}"
                                           placeholder="{{ $item->needsAltText() ? 'Needs a description' : 'Description' }}"
                                           class="w-full rounded border px-2 py-1 text-xs
                                                  {{ $item->needsAltText()
                                                      ? 'border-amber-400 bg-amber-50 placeholder-amber-700'
                                                      : 'border-slate-300' }}">
                                    <button type="submit" class="mt-1 text-xs underline">Save</button>
                                </form>
                            @elseif (filled($item->alt_text))
                                <p class="mt-1 text-xs text-slate-600">{{ $item->alt_text }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">{{ $media->withQueryString()->links() }}</div>
        @endif
    @endif
@endsection
