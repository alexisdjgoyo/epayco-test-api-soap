<?php

namespace App\Interfaces;

use App\Models\Client;
use App\Models\Transaction;

interface WalletRepositoryInterface
{
    public function getClient(array $params): Client|bool;
    public function registerClient(array $params): Client;
    public function rechargeWallet(Client $client, array $params): Client|bool;
    public function getPendingTransaction(string $session_id): Transaction|bool;
    public function pay(array $params): Transaction|bool;
    public function createTransaction(array $params): Transaction|bool;
    public function updateTransaction(Transaction $transaction, string $status): Transaction;
}
