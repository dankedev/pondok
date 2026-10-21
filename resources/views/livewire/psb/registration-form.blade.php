<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        @if ($registeredNumber === null && $this->waves->isNotEmpty())
            <x-ui.badge color="accent" class="self-start">{{ __('pondok.psb_public.opens_badge') }}</x-ui.badge>
        @endif
        <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{{ __('pondok.psb_public.title') }}</h1>
        @if ($registeredNumber === null && $this->waves->isNotEmpty())
            <p class="text-base text-gray-600">
                {{ $this->waves->map(fn ($wave) => $this->waveLabel($wave))->implode(', ') }}
            </p>
        @endif
    </div>

    @if ($registeredNumber !== null)
        {{-- Layar berhasil: token hanya tampil di sini, sekali. --}}
        <x-ui.card :title="__('pondok.psb_public.success_title')">
            <div class="flex flex-col gap-4">
                <p class="text-base text-gray-600">{{ __('pondok.psb_public.success_body') }}</p>

                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                        <dt class="text-sm text-gray-600">{{ __('pondok.applicant.registration_no') }}</dt>
                        <dd class="text-2xl font-semibold tabular-nums text-gray-900">{{ $registeredNumber }}</dd>
                    </div>
                    <div class="rounded-xl border border-gray-100 bg-gray-50 p-4" x-data="{ copied: false }">
                        <dt class="text-sm text-gray-600">{{ __('pondok.psb_public.token') }}</dt>
                        <dd class="flex flex-wrap items-center gap-3">
                            <span class="text-2xl font-semibold tabular-nums tracking-wide text-gray-900" x-ref="token">{{ $issuedToken }}</span>
                            <button type="button"
                                class="inline-flex min-h-11 items-center gap-1 rounded-full px-3 text-sm font-semibold text-primary-700 hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                x-on:click="navigator.clipboard?.writeText($refs.token.innerText); copied = true; setTimeout(() => copied = false, 2000)">
                                <x-heroicon-o-clipboard-document class="size-5" aria-hidden="true" />
                                <span x-show="! copied">{{ __('pondok.psb_public.copy_token') }}</span>
                                <span x-show="copied" x-cloak>{{ __('pondok.psb_public.copied') }}</span>
                            </button>
                        </dd>
                    </div>
                </dl>

                <x-ui.alert color="warning">{{ __('pondok.psb_public.token_warning') }}</x-ui.alert>

                <div class="flex flex-col gap-1">
                    <p class="text-sm text-gray-600">{{ __('pondok.psb_public.fee_total') }}</p>
                    @if ($feeTotal === null)
                        <p class="text-base text-gray-900">{{ __('pondok.psb_public.no_fee') }}</p>
                    @else
                        <p class="text-lg font-semibold tabular-nums text-gray-900">{{ $this->formatRupiah($feeTotal) }}</p>
                        <p class="text-sm text-gray-600">{{ __('pondok.psb_public.payment_hint', ['number' => $registeredNumber]) }}</p>
                    @endif
                </div>

                <div>
                    <x-ui.button :href="route('psb.status')">{{ __('pondok.psb_public.check_status') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @elseif ($this->waves->isEmpty())
        <x-ui.card :title="__('pondok.psb_public.closed_title')">
            <div class="flex flex-col gap-4">
                <p class="text-base text-gray-600">{{ __('pondok.psb_public.closed_body') }}</p>
                <div>
                    <x-ui.button variant="secondary" :href="route('psb.status')">{{ __('pondok.psb_public.check_status') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @else
        <x-ui.steps :current="$step" :steps="[
            __('pondok.psb_public.steps.choice'),
            __('pondok.psb_public.steps.student'),
            __('pondok.psb_public.steps.guardian'),
            __('pondok.psb_public.steps.review'),
        ]" />

        @if ($alert)
            <x-ui.alert color="danger">{{ $alert }}</x-ui.alert>
        @endif

        <form wire:submit="{{ $step < 4 ? 'next' : 'submit' }}" class="flex flex-col gap-6" novalidate>
            {{-- Honeypot: tidak terlihat dan tidak bisa difokus pengguna. --}}
            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                <label for="website">{{ __('pondok.psb_public.honeypot') }}</label>
                <input type="text" id="website" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <x-ui.card :title="__('pondok.psb_public.steps.'.['choice', 'student', 'guardian', 'review'][$step - 1])">
                <div class="flex flex-col gap-4">
                    @if ($step === 1)
                        <x-ui.field :label="__('pondok.applicant.unit')" for="unit_id">
                            <select id="unit_id" wire:model.live="unit_id" class="ui-input">
                                <option value="">{{ __('pondok.psb_public.choose') }}</option>
                                @foreach ($this->units as $unit)
                                    <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        <x-ui.field :label="__('pondok.applicant.track')" for="admission_track_id">
                            <select id="admission_track_id" wire:model.live="admission_track_id" class="ui-input">
                                <option value="">{{ __('pondok.psb_public.choose') }}</option>
                                @foreach ($this->tracks as $track)
                                    <option value="{{ $track->id }}">{{ $track->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        @if (filled($this->selectedTrack?->terms))
                            <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 text-sm text-gray-700">
                                <p class="font-semibold text-gray-900">{{ __('pondok.psb_public.track_terms') }}</p>
                                <p class="whitespace-pre-line">{{ $this->selectedTrack->terms }}</p>
                            </div>
                        @endif

                        @if ($this->waves->count() > 1)
                            <fieldset class="flex flex-col gap-2">
                                <legend class="mb-1 text-sm font-semibold text-gray-900">{{ __('pondok.applicant.wave') }}</legend>
                                @foreach ($this->waves as $wave)
                                    <label class="flex min-h-11 items-center gap-3 rounded-xl border border-gray-300 bg-white px-4 py-2 text-base has-[:checked]:border-primary-900 has-[:checked]:bg-primary-50">
                                        <input type="radio" name="admission_wave_id" value="{{ $wave->id }}" wire:model="admission_wave_id" class="size-4 accent-brand">
                                        <span>{{ $this->waveLabel($wave) }}</span>
                                    </label>
                                @endforeach
                                @error('admission_wave_id')
                                    <p class="text-sm text-danger-700">{{ $message }}</p>
                                @enderror
                            </fieldset>
                        @endif
                    @elseif ($step === 2)
                        <x-ui.field :label="__('pondok.applicant.name')" for="name">
                            <input type="text" id="name" wire:model="name" autocomplete="off" class="ui-input">
                        </x-ui.field>

                        <fieldset class="flex flex-col gap-2">
                            <legend class="mb-1 text-sm font-semibold text-gray-900">{{ __('pondok.applicant.gender') }}</legend>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (['L', 'P'] as $value)
                                    <label class="flex min-h-11 items-center gap-3 rounded-xl border border-gray-300 bg-white px-4 text-base has-[:checked]:border-primary-900 has-[:checked]:bg-primary-50">
                                        <input type="radio" name="gender" value="{{ $value }}" wire:model="gender" class="size-4 accent-brand">
                                        <span>{{ __('pondok.common.genders.'.$value) }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('gender')
                                <p class="text-sm text-danger-700">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field :label="__('pondok.applicant.birth_place')" for="birth_place">
                                <input type="text" id="birth_place" wire:model="birth_place" class="ui-input">
                            </x-ui.field>
                            <x-ui.field :label="__('pondok.applicant.birth_date')" for="birth_date">
                                <input type="date" id="birth_date" wire:model="birth_date" max="{{ now('Asia/Jakarta')->subDay()->toDateString() }}" class="ui-input">
                            </x-ui.field>
                        </div>

                        <x-ui.field :label="__('pondok.applicant.nisn')" for="nisn" :hint="__('pondok.psb_public.nisn_hint')">
                            <input type="text" id="nisn" wire:model="nisn" inputmode="numeric" maxlength="10" class="ui-input tabular-nums">
                        </x-ui.field>

                        <x-ui.field :label="__('pondok.applicant.previous_school')" for="previous_school">
                            <input type="text" id="previous_school" wire:model="previous_school" class="ui-input">
                        </x-ui.field>
                    @elseif ($step === 3)
                        <x-ui.field :label="__('pondok.applicant.guardian_name')" for="guardian_name">
                            <input type="text" id="guardian_name" wire:model="guardian_name" autocomplete="name" class="ui-input">
                        </x-ui.field>
                        <x-ui.field :label="__('pondok.applicant.guardian_phone')" for="guardian_phone" :hint="__('pondok.psb_public.phone_hint')">
                            <input type="tel" id="guardian_phone" wire:model="guardian_phone" inputmode="tel" autocomplete="tel" class="ui-input tabular-nums">
                        </x-ui.field>
                        <x-ui.field :label="__('pondok.applicant.email')" for="email" :hint="__('pondok.psb_public.email_hint')">
                            <input type="email" id="email" wire:model="email" autocomplete="email" class="ui-input">
                        </x-ui.field>
                    @else
                        <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
                            <dt class="text-gray-600">{{ __('pondok.applicant.unit') }}</dt>
                            <dd class="text-gray-900">{{ $this->units->firstWhere('id', (int) $unit_id)?->name ?? '-' }}</dd>
                            <dt class="text-gray-600">{{ __('pondok.applicant.track') }}</dt>
                            <dd class="text-gray-900">{{ $this->selectedTrack?->name ?? '-' }}</dd>
                            <dt class="text-gray-600">{{ __('pondok.applicant.wave') }}</dt>
                            <dd class="text-gray-900">
                                @php($chosenWave = $this->waves->firstWhere('id', (int) $admission_wave_id))
                                {{ $chosenWave ? $this->waveLabel($chosenWave) : '-' }}
                            </dd>
                            <dt class="text-gray-600">{{ __('pondok.applicant.name') }}</dt>
                            <dd class="text-gray-900">{{ $name }}</dd>
                            <dt class="text-gray-600">{{ __('pondok.applicant.birth_place') }}, {{ __('pondok.applicant.birth_date') }}</dt>
                            <dd class="text-gray-900">{{ $birth_place }}, {{ filled($birth_date) ? \Illuminate\Support\Carbon::parse($birth_date)->translatedFormat('j M Y') : '-' }}</dd>
                            <dt class="text-gray-600">{{ __('pondok.applicant.guardian_name') }}</dt>
                            <dd class="text-gray-900">{{ $guardian_name }}</dd>
                            <dt class="text-gray-600">{{ __('pondok.applicant.guardian_phone') }}</dt>
                            <dd class="tabular-nums text-gray-900">{{ $guardian_phone }}</dd>
                        </dl>

                        @if ($this->requirements->isNotEmpty())
                            <div class="flex flex-col gap-2">
                                <h3 class="text-base font-semibold text-gray-900">{{ __('pondok.psb_public.prepare_documents') }}</h3>
                                <ul class="flex flex-col gap-2">
                                    @foreach ($this->requirements as $requirement)
                                        <li class="flex items-center justify-between gap-3 text-sm">
                                            <span class="text-gray-900">{{ $requirement->name }}</span>
                                            <x-ui.badge :color="$requirement->is_required ? 'primary' : 'gray'">
                                                {{ $requirement->is_required ? __('pondok.psb_public.required') : __('pondok.psb_public.optional') }}
                                            </x-ui.badge>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="text-sm text-gray-600">{{ __('pondok.psb_public.prepare_documents_hint') }}</p>
                            </div>
                        @endif

                        <div class="flex flex-col gap-1">
                            <label class="flex items-start gap-3 text-sm text-gray-900">
                                <input type="checkbox" id="consent" wire:model="consent" class="mt-0.5 size-5 shrink-0 accent-brand">
                                <span>{{ __('pondok.psb_public.consent') }}</span>
                            </label>
                            @error('consent')
                                <p class="text-sm text-danger-700">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>
            </x-ui.card>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
                @if ($step > 1)
                    <x-ui.button variant="secondary" wire:click="back">{{ __('pondok.psb_public.back') }}</x-ui.button>
                @else
                    <span class="hidden sm:block"></span>
                @endif

                <x-ui.button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ $step < 4 ? __('pondok.psb_public.next') : __('pondok.psb_public.submit') }}</span>
                    <span wire:loading>{{ __('pondok.psb_public.saving') }}</span>
                </x-ui.button>
            </div>
        </form>
    @endif
</div>
