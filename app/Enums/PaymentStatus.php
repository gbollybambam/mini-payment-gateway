<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case INITIALIZED = 'INITIALIZED';
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case REFUNDED = 'REFUNDED';
}
