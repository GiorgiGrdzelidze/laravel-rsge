<?php

declare(strict_types=1);

namespace Rsge\Laravel\Support;

use Rsge\Laravel\Exceptions\RsgeException;

/**
 * Reads the XML fragments the waybill service answers with and writes the ones it takes.
 */
final class WaybillXml
{
    public const TIMEZONE = 'Asia/Tbilisi';

    /**
     * Pull the XML string out of a SOAP result ({any: "<…>"}, a bare string, or the envelope itself).
     *
     * @param  array<array-key, mixed>|object|string|null  $result
     */
    public static function fragment(array|object|string|null $result): string
    {
        if (is_string($result)) {
            return $result;
        }
        if (is_object($result) && property_exists($result, 'any') && is_string($result->any)) {
            return $result->any;
        }
        if (is_array($result) && isset($result['any']) && is_string($result['any'])) {
            return $result['any'];
        }

        return '';
    }

    /**
     * @throws RsgeException When the fragment is not well-formed XML.
     */
    public static function parse(string $xml): \SimpleXMLElement
    {
        if (trim($xml) === '') {
            throw new RsgeException('rs.ge returned an empty waybill answer.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($element === false) {
            throw new RsgeException('rs.ge returned a waybill answer that is not valid XML.');
        }

        return $element;
    }

    /**
     * The direct children of an element as tag => text; children that hold elements are left out.
     *
     * @return array<string, string>
     */
    public static function fields(\SimpleXMLElement $element): array
    {
        $fields = [];
        foreach ($element->children() as $child) {
            if ($child->count() === 0) {
                $fields[$child->getName()] = trim((string) $child);
            }
        }

        return $fields;
    }

    /**
     * Every element called $tag under $parent, each as tag => text.
     *
     * @return list<array<string, string>>
     */
    public static function rows(\SimpleXMLElement $parent, string $tag): array
    {
        $rows = [];
        foreach ($parent->children() as $row) {
            if ($row->getName() === $tag) {
                $rows[] = self::fields($row);
            }
        }

        return $rows;
    }

    /**
     * Build an XML fragment with no namespace from tag => value pairs and nested fragments.
     *
     * A string value is escaped; an XmlNode value is inserted as it is.
     *
     * @param  array<string, string|int|float|XmlNode|null>  $children
     */
    public static function element(string $name, array $children, bool $root = false): string
    {
        $xml = $root ? "<{$name} xmlns=\"\">" : "<{$name}>";
        foreach ($children as $tag => $value) {
            $xml .= $value instanceof XmlNode
                ? "<{$tag}>{$value->xml}</{$tag}>"
                : "<{$tag}>".self::escape($value === null ? '' : (string) $value)."</{$tag}>";
        }

        return $xml."</{$name}>";
    }

    public static function escape(string $value): string
    {
        // bytes that are no UTF-8 (a name cut in the middle of a letter) become "?" and the rest stays
        $value = mb_scrub($value, 'UTF-8');
        // control characters are not allowed in XML 1.0 and rs.ge answers with a parse error (-102)
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * A number the way rs.ge reads it: a point as the decimal separator, no thousands separator, no exponent.
     */
    public static function number(int|float|string $value): string
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
            if (! is_numeric($value)) {
                throw new \InvalidArgumentException("Not a number: '{$value}'.");
            }
            $value = (float) $value;
        }
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw new \InvalidArgumentException('A waybill number must be finite.');
        }

        $text = number_format((float) $value, 4, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * rs.ge writes and reads its dates as Tbilisi wall-clock time, with no offset.
     */
    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /**
     * The moment as rs.ge writes it: converted to Tbilisi time, whatever zone the object is in.
     */
    public static function date(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(self::timezone())->format('Y-m-d\TH:i:s');
    }

    /**
     * A date rs.ge returned, as the moment it means: read as Tbilisi time.
     */
    public static function parseDate(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, self::timezone());
        } catch (\Exception) {
            return null;
        }
    }
}
