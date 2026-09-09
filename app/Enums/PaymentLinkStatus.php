<?php

namespace App\Enums;

enum PaymentLinkStatus: string
{
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case DEACTIVATED = 'DEACTIVATED';
    case EXPIRED = 'EXPIRED';
}
