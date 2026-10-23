<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Actions\Perizinan\SubmitLeaveRequest;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLeaveRequest extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = LeaveRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): LeaveRequest => app(SubmitLeaveRequest::class)->handle(LeaveRequestResource::user(), $data));
    }
}
