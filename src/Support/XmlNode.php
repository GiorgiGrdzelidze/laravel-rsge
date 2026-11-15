<?php

declare(strict_types=1);

namespace Rsge\Laravel\Support;

/**
 * A raw XML fragment passed to an rs.ge operation whose parameter is an XmlNode.
 *
 * The SOAP transport sends it as-is inside the parameter element.
 */
final class XmlNode
{
    public function __construct(
        public readonly string $xml,
    ) {}
}
