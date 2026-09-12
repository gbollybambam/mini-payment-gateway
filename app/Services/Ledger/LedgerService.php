<?php

namespace App\Services\Ledger;

use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Credit funds to a wallet securely.
     */
    public function credit(
        Wallet $wallet,
        float $amount,
        LedgerEntryType $type,
        string $reference,
        ?Transaction $transaction = null,
        string $description = ''
    ): LedgerEntry {
        return DB::transaction(function () use ($wallet, $amount, $type, $reference, $transaction, $description) {
            if (LedgerEntry::where('reference', $reference)->exists()) {
                throw new Exception('Duplicate ledger entry reference: ' . $reference);
            }

            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $balanceBefore = $lockedWallet->available_balance;
            $balanceAfter = $balanceBefore + $amount;

            $entry = LedgerEntry::create([
                'wallet_id' => $lockedWallet->id,
                'transaction_id' => $transaction?->id,
                'type' => $type,
                'direction' => LedgerEntryDirection::CREDIT,
                'amount' => $amount,
                'currency' => $lockedWallet->currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference' =>$reference,
            ]);

            $lockedWallet->update([
                'available_balance' => $balanceAfter
            ]);

            return $entry;
        });

        }
        /**
         * Debit funds from a wallet securely.
         */
        public function debit(
            Wallet $wallet,
            float $amount,
            LedgerEntryType $type,
            string $reference,
            ?Transaction $transaction = null,
            string $description = ''
        ): LedgerEntry {
            return DB::transaction(function () use ($wallet, $amount, $type, $reference, $transaction, $description) {
                if (LedgerEntry::where('reference', $reference)->exists()) {
                    throw new Exception('Duplicate ledger entry reference: ' . $reference);
                }

                $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

                if ($lockedWallet->available_balance < $amount) {
                    throw new Exception('Insufficient available balance.');
                }

                $balanceBefore = $lockedWallet->available_balance;
                $balanceAfter = $balanceBefore - $amount;

                $entry = LedgerEntry::create([
                    'wallet_id' => $lockedWallet->id,
                    'transaction_id' => $transaction?->id,
                    'type' => $type,
                    'direction' => LedgerEntryDirection::DEBIT,
                    'amount' => $amount,
                    'currency' => $lockedWallet->currency,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => $description,
                    'reference' => $reference,
                ]);

                $lockedWallet->update([
                    'available_balance' => $balanceAfter
                ]);

                return $entry;
            });
        }
}