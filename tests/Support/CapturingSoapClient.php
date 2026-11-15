<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Support;

/**
 * A SoapClient that sends nothing: it keeps the request it would have sent and answers with a canned body.
 */
final class CapturingSoapClient extends \SoapClient
{
    public string $sent = '';

    public string $answer = '';

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false, ?string $uriParserClass = null): ?string
    {
        $this->sent = $request;

        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'.$this->answer.'</soap:Body></soap:Envelope>';
    }

    /**
     * What stands inside the SOAP body of the request.
     */
    public function body(): string
    {
        return (string) preg_replace('/^.*<SOAP-ENV:Body>|<\/SOAP-ENV:Body>.*$/s', '', $this->sent);
    }
}
