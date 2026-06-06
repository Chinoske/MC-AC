<?php
/**
 * Soap — Cliente SOAP para AzerothCore worldserver
 *
 * AzerothCore expone SOAP en puerto 7878 por defecto.
 * URI correcta: 'urn:AC'  (TrinityCore usaba 'urn:TC')
 * Requiere extensión PHP: soap
 */
class Soap
{
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $uri;

    public function __construct(int $realmId = 1)
    {
        $r = REALMS[$realmId]
            ?? throw new RuntimeException("Realm {$realmId} no configurado.");
        $this->host = $r['soap_host'];
        $this->port = (int) $r['soap_port'];
        $this->user = $r['soap_user'];
        $this->pass = $r['soap_pass'];
        $this->uri  = $r['soap_uri'];
    }

    /**
     * Ejecuta un comando GM en el worldserver vía SOAP.
     * Devuelve la respuesta del servidor o lanza RuntimeException si falla.
     */
    public function command(string $cmd): string
    {
        if (!extension_loaded('soap')) {
            throw new RuntimeException('La extensión PHP "soap" no está habilitada en php.ini.');
        }

        $client = new SoapClient(null, [
            'login'      => $this->user,
            'password'   => $this->pass,
            'trace'      => false,
            'exceptions' => true,
            'location'   => "http://{$this->host}:{$this->port}/",
            'uri'        => $this->uri,
            'style'      => SOAP_RPC,
            'use'        => SOAP_ENCODED,
        ]);

        try {
            $result = $client->executeCommand(new SoapParam($cmd, 'command'));
            return (string) ($result ?? '');
        } catch (SoapFault $e) {
            throw new RuntimeException("SOAP error: " . $e->getMessage());
        }
    }

    /**
     * Comprueba si el worldserver del realm está accesible (TCP).
     * Timeout de 0.5 segundos para no bloquear la UI.
     */
    public static function isOnline(int $realmId = 1): bool
    {
        $r = REALMS[$realmId] ?? null;
        if (!$r) {
            return false;
        }
        $fp = @fsockopen($r['soap_host'], (int) $r['soap_port'], $errno, $errstr, 0.5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }
}
