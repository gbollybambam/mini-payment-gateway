<?php

namespace App\Enums;

enum LedgerEntryDirection: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
}