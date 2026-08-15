<?php

namespace App\Enums\HR;

enum EmployeeStatus: string
{
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Suspended = 'suspended';
    case Notice = 'notice';
    case Exited = 'exited';
    case Archived = 'archived';
}
