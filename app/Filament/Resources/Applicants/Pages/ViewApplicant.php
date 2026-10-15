<?php

namespace App\Filament\Resources\Applicants\Pages;

use App\Filament\Resources\Applicants\ApplicantResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewApplicant extends ViewRecord
{
    protected static string $resource = ApplicantResource::class;

    /**
     * Relasi yang dipakai infolist dimuat sekali.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'unit',
            'admissionWave.academicYear',
            'admissionTrack',
            'invoices' => fn ($query) => $query->orderBy('id'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ApplicantResource::generateInvoicesAction(),
            ApplicantResource::resetTokenAction(),
            ApplicantResource::deleteAction(),
        ];
    }
}
