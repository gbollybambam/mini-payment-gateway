<?php

enum LedgerEntryType: string
{
    case PAYMENT = 'PAYMENT';
    case WITHDRAWAL = 'WITHDRAWAL';
    case REFUND = 'REFUND';
    case REVERSAL = 'REVERSAL';
    case FEE = 'FEE';
    case ADJUSTMENT = 'ADJUSTMENT';
}