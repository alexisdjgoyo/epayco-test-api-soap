<?php

namespace App\Repositories;

use App\Interfaces\WalletRepositoryInterface;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class WalletRepository implements WalletRepositoryInterface
{
    public function getClient(array $params): Client|bool
    {
        $client = Client::where('document', $params['document'])
            ->where('phone_number', $params['phone_number'])
            ->first();

        if (!$client) {
            return false;
        }

        return $client;
    }


    public function registerClient(array $params): Client
    {
        try {
            DB::beginTransaction();
            $client = Client::create([
                'document' => $params['document'],
                'names' => $params['names'],
                'email' => $params['email'],
                'phone_number' => $params['phone_number'],
                'balance' => 0.00,
            ]);
            DB::commit();
            return $client;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function rechargeWallet(Client $client, array $params): Client|bool
    {
        try {
            DB::beginTransaction();

            $client->balance += $params['amount'];
            $client->save();

            Transaction::create([
                'client_id' => $client->id,
                'type' => 'RECHARGE',
                'amount' => $params['amount'],
                'status' => 'COMPLETED'
            ]);

            DB::commit();
            return $client;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function pay(array $params): Transaction|bool
    {
        try {
            DB::beginTransaction();
            $transaction = Transaction::create([
                'client_id' => $params['client_id'],
                'type' => $params['type'],
                'amount' => $params['amount'],
                'session_id' => $params['session_id'],
                'token' => $params['token'],
                'token_expires_at' => $params['token_expires_at'],
                'status' => $params['status'],
            ]);

            DB::commit();
            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createTransaction(array $params): Transaction|bool
    {
        try {
            DB::beginTransaction();
            $transaction = Transaction::create([
                'client_id' => $params['client_id'],
                'type' => $params['type'],
                'amount' => $params['amount'],
                'session_id' => $params['session_id'],
                'token' => $params['token'],
                'token_expires_at' => $params['token_expires_at'],
                'status' => $params['status'],
            ]);

            DB::commit();
            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getPendingTransaction(string $session_id): Transaction|bool
    {
        $transaction = Transaction::where('session_id', $session_id)
            ->where('status', 'PENDING')
            ->first();

        if (!$transaction) {
            return false;
        }

        return $transaction;
    }

    public function updateTransaction(Transaction $transaction, string $status): Transaction
    {
        try {
            DB::beginTransaction();
            $transaction->status = $status;
            $transaction->save();
            DB::commit();
            return $transaction;
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }
}
