<div class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold text-gray-900">{{ __('pondok.psb_public.status_title') }}</h1>

    @if ($this->applicant === null)
        <x-ui.card>
            <form wire:submit="login" class="flex flex-col gap-4">
                <p class="text-base text-gray-600">{{ __('pondok.psb_public.status_intro') }}</p>

                @if ($alert)
                    <x-ui.alert color="danger">{{ $alert }}</x-ui.alert>
                @endif

                <x-ui.field :label="__('pondok.applicant.registration_no')" for="registration_no">
                    <input type="text" id="registration_no" wire:model="registration_no" autocomplete="off" autocapitalize="characters" placeholder="PSB-2027-0001" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                </x-ui.field>

                <x-ui.field :label="__('pondok.psb_public.token')" for="token">
                    <input type="text" id="token" wire:model="token" autocomplete="off" autocapitalize="characters" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base tabular-nums tracking-wide focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                </x-ui.field>

                <div>
                    <x-ui.button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="login">{{ __('pondok.psb_public.login_submit') }}</span>
                        <span wire:loading wire:target="login">{{ __('pondok.psb_public.saving') }}</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @else
        @php($applicant = $this->applicant)

        <x-ui.card :title="__('pondok.psb_public.identity_title')">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-lg font-semibold text-gray-900">{{ $applicant->name }}</p>
                        <p class="text-sm tabular-nums text-gray-600">{{ $applicant->registration_no }}</p>
                    </div>
                    <x-ui.badge :color="$applicant->statusColor()">{{ $applicant->statusLabel() }}</x-ui.badge>
                </div>
                <p class="text-base text-gray-700">{{ __('pondok.psb_public.status_help.'.$applicant->status) }}</p>
                <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    <dt class="text-gray-600">{{ __('pondok.applicant.unit') }}</dt>
                    <dd class="text-gray-900">{{ $applicant->unit?->name }}</dd>
                    <dt class="text-gray-600">{{ __('pondok.applicant.track') }}</dt>
                    <dd class="text-gray-900">{{ $applicant->admissionTrack?->name }}</dd>
                    <dt class="text-gray-600">{{ __('pondok.applicant.wave') }}</dt>
                    <dd class="text-gray-900">{{ __('pondok.psb_public.wave_option', ['wave' => $applicant->admissionWave?->name, 'year' => $applicant->admissionWave?->academicYear?->name]) }}</dd>
                </dl>
            </div>
        </x-ui.card>

        @if ($this->invoices->isNotEmpty())
            <x-ui.card :title="__('pondok.psb_public.invoices_title')">
                <ul class="flex flex-col divide-y divide-gray-200">
                    @foreach ($this->invoices as $invoice)
                        <li class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm tabular-nums text-gray-600">{{ $invoice->invoice_no }}</span>
                                <x-ui.badge :color="$invoice->statusColor()">{{ $invoice->statusLabel() }}</x-ui.badge>
                            </div>
                            <dl class="grid grid-cols-3 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-600">{{ __('pondok.invoice.total') }}</dt>
                                    <dd class="font-semibold tabular-nums text-gray-900">{{ $this->formatRupiah($invoice->total) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-600">{{ __('pondok.invoice.paid_total') }}</dt>
                                    <dd class="tabular-nums text-gray-900">{{ $this->formatRupiah($invoice->paid_total) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-600">{{ __('pondok.psb_public.invoice_remaining') }}</dt>
                                    <dd class="tabular-nums text-gray-900">{{ $this->formatRupiah($invoice->remainingAmount()) }}</dd>
                                </div>
                            </dl>
                            @if ($invoice->remainingAmount() > 0)
                                <p class="text-sm text-gray-600">{{ __('pondok.psb_public.payment_hint', ['number' => $applicant->registration_no]) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('pondok.psb_public.documents_title')">
            <div class="flex flex-col gap-4">
                @if ($notice)
                    <x-ui.alert color="success">{{ $notice }}</x-ui.alert>
                @endif

                @php($acceptsUploads = $applicant->acceptsUploads())

                @if ($applicant->status !== \App\Models\Applicant::STATUS_SUBMITTED)
                    <x-ui.alert color="info">{{ __('pondok.psb_public.uploads_closed') }}</x-ui.alert>
                @elseif (! $acceptsUploads)
                    <x-ui.alert color="info">{{ __('pondok.psb_public.uploads_locked_unpaid') }}</x-ui.alert>
                @endif

                <ul class="flex flex-col divide-y divide-gray-200">
                    @foreach ($this->requirements as $requirement)
                        @php($document = $this->uploadedDocuments->get($requirement->id))
                        <li class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0" wire:key="requirement-{{ $requirement->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-base text-gray-900">
                                    {{ $requirement->name }}
                                    <span class="text-sm text-gray-600">· {{ $requirement->is_required ? __('pondok.psb_public.required') : __('pondok.psb_public.optional') }}</span>
                                </span>
                                @if ($document)
                                    <x-ui.badge :color="$document->statusColor()">{{ $document->statusLabel() }}</x-ui.badge>
                                @else
                                    <x-ui.badge color="gray">{{ __('pondok.applicant_document.not_uploaded') }}</x-ui.badge>
                                @endif
                            </div>

                            @if ($document?->status === \App\Models\ApplicantDocument::STATUS_REJECTED && filled($document->note))
                                <p class="text-sm text-danger-700">{{ __('pondok.psb_public.rejected_note', ['note' => $document->note]) }}</p>
                            @endif

                            @if ($document)
                                <a href="{{ route('psb.document', $document) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-1 self-start text-sm font-semibold text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                    <x-heroicon-o-arrow-down-tray class="size-4" aria-hidden="true" />
                                    {{ __('pondok.applicant_document.download') }} <span class="font-normal text-gray-600">({{ $document->original_name }})</span>
                                </a>
                            @endif

                            @if ($acceptsUploads && $document?->status !== \App\Models\ApplicantDocument::STATUS_VALID)
                                <form wire:submit="uploadDocument({{ $requirement->id }})" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                    <div class="flex flex-1 flex-col gap-1">
                                        <label for="document-{{ $requirement->id }}" class="sr-only">{{ __('pondok.psb_public.upload') }} {{ $requirement->name }}</label>
                                        <input type="file" id="document-{{ $requirement->id }}" wire:model="documents.{{ $requirement->id }}" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1 file:text-sm file:font-semibold file:text-gray-900">
                                        <p class="text-sm text-gray-600">{{ __('pondok.psb_public.upload_hint') }}</p>
                                        @error('documents.'.$requirement->id)
                                            <p class="text-sm text-danger-700">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="documents.{{ $requirement->id }}, uploadDocument">
                                        <x-heroicon-o-arrow-up-tray class="size-5" aria-hidden="true" />
                                        {{ __('pondok.psb_public.upload') }}
                                    </x-ui.button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.card>

        <div>
            <x-ui.button variant="secondary" wire:click="logout">{{ __('pondok.psb_public.logout') }}</x-ui.button>
        </div>
    @endif
</div>
