@extends('layouts.agency')

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">

        <div class="lg:col-span-2 space-y-6">
            <form method="POST" action="{{ route('agency.brands.update', $brand) }}"
                  class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
                @csrf
                @method('PUT')

                <fieldset @cannot('update', $brand) disabled @endcannot class="space-y-4">
                    <div>
                        <label for="name" class="block text-sm font-medium">Brand name</label>
                        <input id="name" name="name" value="{{ old('name', $brand->name) }}" required
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    </div>

                    <div>
                        <label for="industry" class="block text-sm font-medium">Industry</label>
                        <input id="industry" name="industry" value="{{ old('industry', $brand->industry) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    </div>

                    <div>
                        <label for="website" class="block text-sm font-medium">Website</label>
                        <input id="website" name="website" type="url" value="{{ old('website', $brand->website) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    </div>

                    <div>
                        <label for="timezone" class="block text-sm font-medium">Timezone</label>
                        <input id="timezone" name="timezone" value="{{ old('timezone', $brand->timezone) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    </div>

                    <div>
                        <label for="contact_email" class="block text-sm font-medium">Client contact email</label>
                        <input id="contact_email" name="contact_email" type="email"
                               value="{{ old('contact_email', $brand->contact_email) }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    </div>
                </fieldset>

                @can('update', $brand)
                    <button type="submit"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                        Save changes
                    </button>
                @endcan
            </form>
        </div>

        <aside class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold">At a glance</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Status</dt>
                        <dd>{{ $brand->status->label() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Posts</dt>
                        <dd class="tabular-nums">{{ $postCount }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Media files</dt>
                        <dd class="tabular-nums">{{ $mediaCount }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Client approval</dt>
                        <dd>{{ $brand->requiresClientApproval() ? 'Required' : 'Not required' }}</dd>
                    </div>
                </dl>
            </div>

            @canany(['archive', 'unarchive'], $brand)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="text-sm font-semibold">Lifecycle</h2>

                    @can('archive', $brand)
                        {{--
                          Archiving is offered rather than deletion. It frees the
                          brand's plan slot while keeping every post, media file and
                          approval intact -- which is what an agency losing a client
                          actually wants.
                        --}}
                        <p class="mt-2 text-sm text-slate-600">
                            Archiving keeps all content and frees a brand slot on your plan.
                        </p>
                        <form method="POST" action="{{ route('agency.brands.archive', $brand) }}" class="mt-3">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                                Archive brand
                            </button>
                        </form>
                    @endcan

                    @can('unarchive', $brand)
                        <p class="mt-2 text-sm text-slate-600">
                            Restoring uses a brand slot on your current plan.
                        </p>
                        <form method="POST" action="{{ route('agency.brands.unarchive', $brand) }}" class="mt-3">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                                Restore brand
                            </button>
                        </form>
                    @endcan
                </div>
            @endcanany
        </aside>
    </div>
@endsection
