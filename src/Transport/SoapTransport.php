<?php

declare(strict_types=1);

namespace Rsge\Laravel\Transport;

use Psr\Log\LoggerInterface;
use Rsge\Laravel\Contracts\Transport;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Support\XmlNode;

/**
 * SOAP transport wrapping PHP's native SoapClient.
 */
final class SoapTransport implements Transport
{
    private ?\SoapClient $client = null;

    /**
     * @param  array<string, mixed>  $options  SoapClient constructor options.
     */
    public function __construct(
        private readonly ?string $wsdl = null,
        #[\SensitiveParameter]
        private readonly array $options = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * The WSDL this transport talks to.
     */
    public function wsdl(): ?string
    {
        return $this->wsdl;
    }

    /**
     * Inject a pre-built SoapClient (useful for testing).
     */
    public function setClient(\SoapClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|object
     *
     * @throws RsgeTransportException
     */
    public function call(string $method, #[\SensitiveParameter] array $parameters): array|object
    {
        $client = $this->resolveClient();

        $start = hrtime(true);

        try {
            /** @var array<string, mixed>|object $result */
            $result = $client->__soapCall($method, [$this->encode($parameters)]);
        } catch (\SoapFault $e) {
            // the fault is not chained: its own trace holds the whole request, passwords included
            throw new RsgeTransportException(
                message: "SOAP call '{$method}' failed: {$e->getMessage()}".(isset($e->faultcode) && is_string($e->faultcode) ? " [{$e->faultcode}]" : ''),
            );
        } finally {
            $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
            $this->logger?->debug('rsge.soap.call', [
                'method' => $method,
                'duration_ms' => $durationMs,
            ]);
        }

        return $result;
    }

    /**
     * Turn raw XML fragments into the form SoapClient sends untouched.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function encode(#[\SensitiveParameter] array $parameters): array
    {
        foreach ($parameters as $name => $value) {
            if ($value instanceof XmlNode) {
                $parameters[$name] = ['any' => new \SoapVar($value->xml, XSD_ANYXML)];
            }
        }

        return $parameters;
    }

    /**
     * @throws RsgeTransportException
     */
    private function resolveClient(): \SoapClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if ($this->wsdl === null || $this->wsdl === '') {
            throw new RsgeTransportException(
                message: 'No WSDL configured for SOAP transport. Set config/rsge.php endpoints.*.wsdl or inject a SoapClient via setClient().',
            );
        }

        try {
            $this->client = new \SoapClient($this->wsdl, $this->options);
        } catch (\SoapFault $e) {
            // not chained either: the fault's trace holds the client's options, a proxy password among them
            throw new RsgeTransportException(
                message: "Failed to create SoapClient for WSDL '{$this->wsdl}': {$e->getMessage()}",
            );
        }

        return $this->client;
    }
}
