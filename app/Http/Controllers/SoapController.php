<?php

namespace App\Http\Controllers;

use DOMDocument;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Http\Request;
use function Laravel\Prompts\info;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Notifications\PaymentTokenNotification;
use Exception;

class SoapController extends Controller
{
    private function buildResponse($success, $cod_error, $message_error, $data = null)
    {
        return [
            'success' => $success,
            'cod_error' => $cod_error,
            'message_error' => $message_error,
            'data' => $data
        ];
    }

    public function handle(Request $request)
    {
        $xml = $request->getContent();

        $dom = new DOMDocument();
        @$dom->loadXML($xml);

        $body = $dom->getElementsByTagName('Body')->item(0);
        if (!$body) {
            return $this->soapError('Cuerpo SOAP no encontrado');
        }

        $operation = null;
        foreach ($body->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $operation = $child->nodeName;
                break;
            }
        }

        if (!$operation) {
            return $this->soapError('Operación SOAP no especificada');
        }

        $params = [];
        $operationNode = $body->getElementsByTagName($operation)->item(0);
        if ($operationNode) {
            foreach ($operationNode->childNodes as $paramNode) {
                if ($paramNode->nodeType === XML_ELEMENT_NODE) {
                    $params[$paramNode->nodeName] = $paramNode->nodeValue;
                }
            }
        }

        switch ($operation) {
            case 'registroCliente':
                return $this->createClient($params);
            case 'recargarBilletera':
                return $this->fundWallet($params);
            case 'pagar':
                return $this->pay($params);
            case 'confirmarPago':
                return $this->confirmPayment($params);
            case 'consultarSaldo':
                return $this->checkBalance($params);
            default:
                return $this->soapError('Operación no válida: ' . $operation);
        }
    }

    private function soapError($message)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
                    <soap:Body>
                        <soap:Fault>
                            <faultcode>soap:Client</faultcode>
                            <faultstring>' . $message . '</faultstring>
                        </soap:Fault>
                    </soap:Body>
                </soap:Envelope>';

        return response($xml, 500)->header('Content-Type', 'text/xml');
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

        return response($xml)->header('Content-Type', 'text/xml');
    }

    public function createClient($params)
    {
        try {
            if (count($params) < 4) {
                return $this->soapResponse($this->buildResponse(false, '01', 'Parámetros insuficientes'));
            }

            $document = $params['documento'] ?? null;
            $names = $params['nombres'] ?? null;
            $email = $params['email'] ?? null;
            $phone_number = $params['celular'] ?? null;

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

            DB::beginTransaction();

            $client = Client::create([
                'document' => $document,
                'names' => $names,
                'email' => $email,
                'phone_number' => $phone_number
            ]);

            DB::commit();
            return $this->soapResponse($this->buildResponse(true, '00', 'Cliente registrado exitosamente', [
                'cliente_id' => $client->id
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }

    public function fundWallet($params)
    {
        try {

            $document = $params['documento'] ?? null;
            $phone_number = $params['celular'] ?? null;
            $amount = $params['valor'] ?? null;


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

            DB::beginTransaction();
            $client = Client::where('document', $document)
                ->where('phone_number', $phone_number)
                ->first();

            if (!$client) {
                return $this->soapResponse($this->buildResponse(false, '03', 'Cliente no encontrado'));
            }

            $client->balance += $amount;
            $client->save();

            Transaction::create([
                'client_id' => $client->id,
                'type' => 'RECHARGE',
                'amount' => $amount,
                'status' => 'COMPLETED'
            ]);

            DB::commit();

            return $this->soapResponse($this->buildResponse(true, '00', 'Recarga exitosa', [
                'nuevo_saldo' => $client->balance
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }

    public function checkBalance($params)
    {
        try {
            if (count($params) < 2) {
                return $this->soapResponse($this->buildResponse(false, '01', 'Parámetros insuficientes'));
            }

            $document = $params['documento'];
            $phone_number = $params['celular'];

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

            $client = Client::where('document', $document)
                ->where('phone_number', $phone_number)
                ->first();

            if (!$client) {
                return $this->soapResponse($this->buildResponse(false, '03', 'Cliente no encontrado'));
            }
            return $this->soapResponse($this->buildResponse(true, '00', 'Consulta exitosa', [
                'saldo' => $client->balance
            ]));
        } catch (\Exception $e) {
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }

    public function pay($params)
    {
        try {
            $document = $params['documento'] ?? null;
            $phone_number = $params['celular'] ?? null;
            $amount = $params['monto'] ?? null;

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

            $client = Client::where('document', $document)
                ->where('phone_number', $phone_number)
                ->first();

            if (!$client)
                return $this->soapResponse($this->buildResponse(false, '03', 'Cliente no encontrado'));


            if ($client->balance < $amount) {
                return $this->soapResponse($this->buildResponse(false, '04', 'Saldo insuficiente'));
            }


            $token = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $sessionId = uniqid('pay_', true);
            $tokenExpiresAt = now()->addMinutes(5);

            DB::beginTransaction();

            Transaction::create([
                'client_id' => $client->id,
                'type' => 'PAY',
                'amount' => $amount,
                'session_id' => $sessionId,
                'token' => $token,
                'token_expires_at' => $tokenExpiresAt,
                'status' => 'PENDING',
            ]);

            $client->notify(new PaymentTokenNotification($token, $amount));

            DB::commit();

            return $this->soapResponse($this->buildResponse(true, '00', 'Token enviado al correo', [
                'session_id' => $sessionId,
                'mensaje' => 'Token simulado: ' . $token
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }

    public function confirmPayment($params)
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

            $transaction = Transaction::where('session_id', $session_id)
                ->where('status', 'PENDING')
                ->first();

            if (!$transaction) {
                throw new \Exception('test exception');
                return $this->soapResponse($this->buildResponse(false, '02', 'session_id inválido'));
            }

            DB::beginTransaction();

            if ($transaction->token_expires_at < now()) {
                $transaction->update([
                    'status' => 'FAILED',
                ]);
                return $this->soapResponse($this->buildResponse(false, '03', 'Token expirado'));
            }

            if ($transaction->token != $token) {
                return $this->soapResponse($this->buildResponse(false, '04', 'Token inválido'));
            }

            $client = $transaction->client;
            $client->balance -= $transaction->amount;
            $client->save();

            $transaction->update([
                'status' => 'COMPLETED',
            ]);

            DB::commit();

            return $this->soapResponse($this->buildResponse(true, '00', 'Pago confirmado exitosamente', [
                'nuevo_saldo' => $client->balance,
                'monto_pagado' => $transaction->amount
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->soapResponse($this->buildResponse(false, '99', 'Error interno del servidor'));
        }
    }
}
