<?php

namespace App\Http\Controllers;

use DOMDocument;
use Illuminate\Http\Request;
use App\Services\WalletService;

class SoapController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
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
                return $this->walletService->createClient($params);
            case 'recargarBilletera':
                return $this->walletService->fundWallet($params);
            case 'pagar':
                return $this->walletService->pay($params);
            case 'confirmarPago':
                return $this->walletService->confirmPayment($params);
            case 'consultarSaldo':
                return $this->walletService->checkBalance($params);
            default:
                return $this->walletService->soapError('Operación no válida: ' . $operation);
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
}
