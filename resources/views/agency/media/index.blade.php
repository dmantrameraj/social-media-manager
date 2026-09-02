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
                      enctype="multipart/form-data" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="brand" value="{{ $selected }}">
                    <div>
                        <label for="file" class="block text-sm font-medium">Upload</label>
                        <input id="file" name="file" type="file" required
                               class="mt-1 block text-sm file:mr-3 file:rounded-lg file:border-0
                                      file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white">
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
                        <div class="grid aspect-square place-items-center bg-slate-100 text-xs text-slate-500">
                            {{-- Files are on a private disk and served only through signed,
                                 policy-checked URLs, so no direct src is rendered here. --}}
                            {{ strtoupper($item->extension) }}
                        </div>
                        <div class="p-2">
                            <p class="truncate text-xs font-medium" title="{{ $item->original_name }}">
                                {{ $item->original_name }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ number_format($item->size_bytes / 1024, 0) }} KB
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">{{ $media->withQueryString()->links() }}</div>
        @endif
    @endif
@endsection
