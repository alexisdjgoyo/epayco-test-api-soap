<?php

namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Interfaces\WalletRepositoryInterface;
use App\Notifications\PaymentTokenNotification;

class WalletService
{
    protected WalletRepositoryInterface $walletRepository;

    public function __construct(WalletRepositoryInterface $walletRepository)
    {
        $this->walletRepository = $walletRepository;
    }

    private function buildResponse($success, $cod_error, $message_error, $data = null)
    {
        return [
            'success' => $success,
            'cod_error' => $cod_error,
            'message_error' => $message_error,
            'data' => $data
        ];
    }

    private function soapResponse($data)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
                    <soap:Body>
                        <response>
                            <success>' . ($data['success'] ? 'true' : 'false') . '</success>
                            <cod_error>' . $data['cod_error'] . '</cod_error>
                            <message_error>' . $data['message_error'] . '</message_error>
                            <data>' . json_encode($data['data'] ?? []) . '</data>
                        </response>
                    </soap:Body>
                </soap:Envelope>';

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }

    public function createClient(array $params): Response
    {
        try {
            $document = $params['document'] ?? null;
            $names = $params['names'] ?? null;
            $email = $params['email'] ?? null;
            $phone_number = $params['phone_number'] ?? null;

            $validator = Validator::make([
                'document' => $document,
                'names' => $names,
                'email' => $email,
                'phone_number' => $phone_number
            ], [
                'document' => 'required|unique:clients',
                'names' => 'required',
                'email' => 'required|email|unique:clients',
                'phone_number' => 'required'
            ]);

            if ($validator->fails()) {
                return $this->soapResponse($this->buildResponse(false, '02', $validator->errors()->first()));
            }

            $client = $this->walletRepository->registerClient([
                'document' => $document,
                'names' => $names,
                'email' => $email,
                'phone_number' => $phone_number
            ]);

            return $this->soapResponse($this->buildResponse(true, '00', 'Cliente registrado exitosamente', [
                'cliente_id' => $client->id
            ]));
        } catch (\Exception $e) {
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }

    public function fundWallet(array $params): Response
    {
        try {

            $document = $params['document'] ?? null;
            $phone_number = $params['phone_number'] ?? null;
            $amount = $params['amount'] ?? null;


            $validator = Validator::make([
                'document' => $document,
                'phone_number' => $phone_number,
                'amount' => $amount
            ], [
                'document' => 'required',
                'phone_number' => 'required',
                'amount' => 'required|numeric|min:1'
            ]);

            if ($validator->fails()) {
                return $this->soapResponse($this->buildResponse(false, '02', $validator->errors()->first()));
            }

            $amount = floatval($amount);

            $client = $this->walletRepository->getClient([
                'document' => $document,
                'phone_number' => $phone_number
            ]);

            if (!$client)
                return $this->soapResponse($this->buildResponse(false, '03', 'Cliente no encontrado'));

            $client = $this->walletRepository->rechargeWallet($client, [
                'document' => $document,
                'phone_number' => $phone_number,
                'amount' => $amount
            ]);

            return $this->soapResponse($this->buildResponse(true, '00', 'Recarga exitosa', [
                'nuevo_saldo' => $client->balance
            ]));
        } catch (\Exception $e) {
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }

    public function pay(array $params): Response
    {
        try {
            $document = $params['document'] ?? null;
            $phone_number = $params['phone_number'] ?? null;
            $amount = $params['amount'] ?? null;

            $validator = Validator::make([
                'document' => $document,
                'phone_number' => $phone_number,
                'amount' => $amount
            ], [
                'document' => 'required',
                'phone_number' => 'required',
                'amount' => 'required|numeric|min:1'
            ]);

            if ($validator->fails()) {
                return $this->soapResponse($this->buildResponse(false, '02', $validator->errors()->first()));
            }

            $amount = floatval($amount);

            $client = $this->walletRepository->getClient([
                'document' => $document,
                'phone_number' => $phone_number
            ]);

            if (!$client)
                return $this->soapResponse($this->buildResponse(false, '03', 'Cliente no encontrado'));

            if ($client->balance < $amount) {
                return $this->soapResponse($this->buildResponse(false, '04', 'Saldo insuficiente'));
            }

            $token = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $sessionId = uniqid('pay_', true);
            $tokenExpiresAt = now()->addMinutes(5);

            $transaction = $this->walletRepository->createTransaction([
                'client_id' => $client->id,
                'type' => 'PAY',
                'amount' => $amount,
                'session_id' => $sessionId,
                'token' => $token,
                'token_expires_at' => $tokenExpiresAt,
                'status' => 'PENDING',
            ]);

            $client->notify(new PaymentTokenNotification($token, $amount));

            return $this->soapResponse($this->buildResponse(true, '00', 'Token enviado al correo', [
                'session_id' => $sessionId,
                'mensaje' => 'Token simulado: ' . $token
            ]));
        } catch (\Exception $e) {
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        };
    }

    public function confirmPayment(array $params): Response
    {
        try {
            $session_id = $params['session_id'] ?? null;
            $token = $params['token'] ?? null;

            $validator = Validator::make([
                'session_id' => $session_id,
                'token' => $token
            ], [
                'session_id' => 'required',
                'token' => 'required',
            ]);

            if ($validator->fails()) {
                return $this->soapResponse($this->buildResponse(false, '02', $validator->errors()->first()));
            }

            $transaction = $this->walletRepository->getPendingTransaction($session_id);

            if (!$transaction) {
                return $this->soapResponse($this->buildResponse(false, '03', 'session_id inválido'));
            }

            if ($transaction->token_expires_at < now()) {
                $transaction = $this->walletRepository->updateTransaction(
                    $transaction,
                    'FAILED'
                );
                return $this->soapResponse($this->buildResponse(false, '04', 'Token expirado'));
            }

            if ($transaction->token != $token) {
                return $this->soapResponse($this->buildResponse(false, '05', 'Token inválido'));
            }

            $client = $transaction->client;
            $client->balance -= $transaction->amount;
            $client->save();

            $transaction = $this->walletRepository->updateTransaction(
                $transaction,
                'COMPLETED'
            );

            return $this->soapResponse($this->buildResponse(true, '00', 'Pago confirmado exitosamente', [
                'nuevo_saldo' => $client->balance,
                'monto_pagado' => $transaction->amount
            ]));
        } catch (\Exception $e) {
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }

    public function checkBalance(array $params): Response
    {
        try {
            if (count($params) < 2) {
                return $this->soapResponse($this->buildResponse(false, '01', 'Parámetros insuficientes'));
            }

            $document = $params['document'];
            $phone_number = $params['phone_number'];

            $validator = Validator::make([
                'document' => $document,
                'phone_number' => $phone_number,
            ], [
                'document' => 'required',
                'phone_number' => 'required'
            ]);

            if ($validator->fails()) {
                return $this->soapResponse($this->buildResponse(false, '02', $validator->errors()->first()));
            }

            $client = $this->walletRepository->getClient([
                'document' => $document,
                'phone_number' => $phone_number
            ]);

            if (!$client)
                return $this->soapResponse($this->buildResponse(false, '03', 'Cliente no encontrado'));

            return $this->soapResponse($this->buildResponse(true, '00', 'Consulta exitosa', [
                'saldo' => $client->balance
            ]));
        } catch (\Exception $e) {
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }
}
