<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
// Define la ruta base del endpoint SOAP
const SOAP_ENDPOINT = '/api/soap';

// Datos de prueba
const CLIENT_DATA = [
    'document' => '1001234567',
    'names' => 'Juan Pérez',
    'email' => 'juan.perez@test.com',
    'phone_number' => '3001234567',
];

/**
 * Helper: Crea el XML de petición SOAP para una operación
 */
function createSoapRequestXml(string $operation, array $params): string
{
    $paramXml = '';
    foreach ($params as $key => $value) {
        $paramXml .= "<$key>$value</$key>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>
            <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://epayco.com/soap">
                <soap:Body>
                    <' . $operation . '>
                        ' . $paramXml . '
                    </' . $operation . '>
                </soap:Body>
            </soap:Envelope>';
}

test('el endpoint soap debe fallar si la operacion no es valida', function () {
    $xml = createSoapRequestXml('operacionInvalida', ['param' => 'value']);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(500);
    $response->assertSee('Operación no válida: ', false);
});

// --- OPERACIONES CRUD ---

test('debe registrar un cliente nuevo y devolver exito', function () {
    $xml = createSoapRequestXml('registroCliente', CLIENT_DATA);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>true</success>', false);
    $response->assertSee('<cod_error>00</cod_error>', false);
    $response->assertSee('Cliente registrado exitosamente', false);
});

test('debe fallar si el cliente ya esta registrado', function () {
    // 1. Registrar al cliente primero (usamos la prueba anterior para asegurar que existe)
    $xml = createSoapRequestXml('registroCliente', CLIENT_DATA);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    // 2. Intentar registrar de nuevo
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>false</success>', false);
    $response->assertSee('<cod_error>02</cod_error>', false); // Código de validación fallida
    $response->assertSee('The document has already been taken', false); // Mensaje de Laravel Validator
})->uses(RefreshDatabase::class);

test('debe recargar la billetera de un cliente existente', function () {
    // 1. Asegurar que el cliente existe
    $xmlReg = createSoapRequestXml('registroCliente', ['document' => '111', 'names' => 'Recarga Test', 'email' => 'recharge@test.com', 'phone_number' => '111']);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xmlReg),
    ], $xmlReg);

    // 2. Recargar billetera
    $xml = createSoapRequestXml('recargarBilletera', [
        'document' => '111',
        'phone_number' => '111',
        'amount' => 50000,
    ]);

    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>true</success>', false);
    $response->assertSee('Recarga exitosa', false);
    $response->assertSee('"nuevo_saldo":50000', false); // Verificar el nuevo saldo
})->uses(RefreshDatabase::class);

test('debe fallar al recargar si el cliente no existe', function () {
    $xml = createSoapRequestXml('recargarBilletera', [
        'document' => '999',
        'phone_number' => '999',
        'amount' => 10000,
    ]);

    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>false</success>', false);
    $response->assertSee('<cod_error>03</cod_error>', false); // Cliente no encontrado
});

test('debe consultar el saldo de un cliente y devolver exito', function () {
    // 1. Asegurar que el cliente existe (con un saldo inicial)
    $xmlReg = createSoapRequestXml('registroCliente', ['document' => '222', 'names' => 'Saldo Test', 'email' => 'balance@test.com', 'phone_number' => '222']);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xmlReg),
    ], $xmlReg);

    // 2. Consultar saldo
    $xml = createSoapRequestXml('consultarSaldo', [
        'document' => '222',
        'phone_number' => '222',
    ]);

    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>true</success>', false);
    $response->assertSee('Consulta exitosa', false);
    $response->assertSee('"saldo":0', false); // Saldo inicial
});

test('debe fallar al consultar saldo si el cliente no existe', function () {
    $xml = createSoapRequestXml('consultarSaldo', [
        'document' => '888',
        'phone_number' => '888',
    ]);

    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>false</success>', false);
    $response->assertSee('<cod_error>03</cod_error>', false); // Cliente no encontrado
});

test('debe iniciar un pago (pagar) y generar un token', function () {
    // 1. Asegurar cliente con saldo
    $xmlReg = createSoapRequestXml('registroCliente', ['document' => '333', 'names' => 'Pago Test', 'email' => 'pay@test.com', 'phone_number' => '333']);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xmlReg),
    ], $xmlReg);
    $xmlRec = createSoapRequestXml('recargarBilletera', ['document' => '333', 'phone_number' => '333', 'amount' => 10000]);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xmlRec),
    ], $xmlRec);

    // 2. Iniciar pago
    $xml = createSoapRequestXml('pagar', [
        'document' => '333',
        'phone_number' => '333',
        'amount' => 5000,
    ]);

    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>true</success>', false);
    $response->assertSee('Token enviado al correo', false);
    $response->assertSee('session_id', false);
    $response->assertSee('Token simulado', false);
});

test('debe confirmar un pago con token valido y actualizar saldo', function () {
    // 1. Iniciar pago (usando la prueba anterior si es posible, o registrar y pagar de nuevo)
    $xmlReg = createSoapRequestXml('registroCliente', ['document' => '444', 'names' => 'Confirm Test', 'email' => 'confirm@test.com', 'phone_number' => '444']);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xmlReg),
    ], $xmlReg);
    $xmlRec = createSoapRequestXml('recargarBilletera', ['document' => '444', 'phone_number' => '444', 'amount' => 10000]);
    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xmlRec),
    ], $xmlRec);

    $xmlPay = createSoapRequestXml('pagar', [
        'document' => '444',
        'phone_number' => '444',
        'amount' => 3000,
    ]);
    $responsePay = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xmlPay),
    ], $xmlPay);

    // Extraer Session ID y Token simulado de la respuesta (esto requiere un parser XML real,
    // pero para fines de prueba, extraemos las cadenas)
    $sessionId = extractValueFromSoapResponse($responsePay->getContent(), 'session_id');
    $simulatedToken = substr(extractValueFromSoapResponse($responsePay->getContent(), 'mensaje'), 16); // "Token simulado: XXXXXX"

    // 2. Confirmar pago
    $xml = createSoapRequestXml('confirmarPago', [
        'session_id' => $sessionId,
        'token' => $simulatedToken,
    ]);

    $response = $this->call('POST', SOAP_ENDPOINT, [], [], [], [
        'CONTENT_TYPE' => 'text/xml',
        'Accept' => 'text/xml',
        'Content-Length' => strlen($xml),
    ], $xml);

    $response->assertStatus(200);
    $response->assertSee('<success>true</success>', false);
    $response->assertSee('Pago confirmado exitosamente', false);
    $response->assertSee('"nuevo_saldo":7000', false); // 10000 - 3000
    $response->assertSee('"monto_pagado":3000', false);
});

// --- HELPER PARA EXTRAER amountES (puedes añadir esto dentro de la clase de prueba) ---
// NOTA: Esto es un hack simple para pruebas y no un parser XML robusto.
function extractValueFromSoapResponse(string $content, string $key): ?string
{
    // El 'data' viene en formato JSON dentro del XML
    if (preg_match('/<data>(.*?)<\/data>/s', $content, $matches)) {
        $dataJson = $matches[1];
        $data = json_decode($dataJson, true);
        return $data[$key] ?? null;
    }
    return null;
}
