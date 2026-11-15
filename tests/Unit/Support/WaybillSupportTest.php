<?php

declare(strict_types=1);

namespace Rsge\Laravel\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeUnreadMessageException;
use Rsge\Laravel\Exceptions\RsgeWaybillException;
use Rsge\Laravel\Support\WaybillErrors;
use Rsge\Laravel\Support\WaybillXml;
use Rsge\Laravel\Support\XmlNode;

final class WaybillSupportTest extends TestCase
{
    #[Test]
    public function service_user_credentials_carry_su_and_sp_and_the_tin(): void
    {
        $credentials = new ServiceUserCredentials('SHOP ONE:206322102', 'secret');

        $this->assertSame(['su' => 'SHOP ONE:206322102', 'sp' => 'secret'], $credentials->toArray());
        $this->assertTrue($credentials->isUsable());
        $this->assertSame('206322102', $credentials->tin());
        $this->assertSame('206322102', (new ServiceUserCredentials('A:B:206322102', 'x'))->tin());
        $this->assertNull((new ServiceUserCredentials('no-colon', 'x'))->tin());
        $this->assertNull((new ServiceUserCredentials('name:', 'x'))->tin());
        $this->assertFalse((new ServiceUserCredentials('', 'x'))->isUsable());
        $this->assertFalse((new ServiceUserCredentials('name:1', ''))->isUsable());
    }

    #[Test]
    public function every_bundled_error_code_has_a_text_and_maps_to_an_exception(): void
    {
        /** @var array<int, array{type: int, text: string}> $codes */
        $codes = require __DIR__.'/../../../resources/waybill-error-codes.php';

        $this->assertCount(150, $codes, 'the list rs.ge returned on 2026-11-15');
        foreach ($codes as $code => $entry) {
            $this->assertLessThan(0, $code);
            $this->assertNotSame('', trim($entry['text']), (string) $code);
            $this->assertSame($entry['text'], WaybillErrors::text($code));
            $exception = WaybillErrors::exception('save_waybill', $code);
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($entry['text'], $exception->errorDescription);
            $this->assertStringContainsString((string) $code, $exception->getMessage());
            $this->assertStringContainsString('save_waybill', $exception->getMessage());
            $this->assertStringNotContainsString('&amp;', $entry['text'], (string) $code);
        }
    }

    #[Test]
    public function the_exception_class_follows_the_code(): void
    {
        $this->assertInstanceOf(RsgeAuthenticationException::class, WaybillErrors::exception('x', -100));
        $this->assertNotInstanceOf(RsgeWaybillException::class, WaybillErrors::exception('x', -100));
        $unread = WaybillErrors::exception('x', -1061, [['line' => 0, 'code' => -2005, 'text' => 't']]);
        $this->assertInstanceOf(RsgeUnreadMessageException::class, $unread);
        $this->assertSame([['line' => 0, 'code' => -2005, 'text' => 't']], $unread->lineErrors);
        $this->assertSame(-1061, RsgeUnreadMessageException::ERROR_CODE);

        $plain = WaybillErrors::exception('x', -1036);
        $this->assertSame(RsgeWaybillException::class, $plain::class);

        // a code rs.ge adds later still makes an exception, just without a text
        $unknown = WaybillErrors::exception('send_waybill', -987654);
        $this->assertSame(RsgeWaybillException::class, $unknown::class);
        $this->assertSame([-987654, null], [$unknown->errorCode, $unknown->errorDescription]);
        $this->assertNull(WaybillErrors::text(-987654));
        $this->assertSame("rs.ge waybill operation 'send_waybill' failed with code -987654.", $unknown->getMessage());
    }

    #[Test]
    public function the_xml_string_is_found_in_every_form_a_soap_result_takes(): void
    {
        $this->assertSame('<A/>', WaybillXml::fragment('<A/>'));
        $this->assertSame('<A/>', WaybillXml::fragment((object) ['any' => '<A/>']));
        $this->assertSame('<A/>', WaybillXml::fragment(['any' => '<A/>']));
        $this->assertSame('', WaybillXml::fragment(null));
        $this->assertSame('', WaybillXml::fragment((object) ['other' => '<A/>']));
        $this->assertSame('', WaybillXml::fragment(['any' => 5]));
    }

    #[Test]
    public function fields_and_rows_are_read_and_nested_elements_are_left_to_their_own_reader(): void
    {
        $root = WaybillXml::parse('<LIST xmlns=""><ROW><ID> 1 </ID><NAME>ა &amp; ბ</NAME><INNER><X>1</X></INNER><EMPTY/></ROW><OTHER><ID>9</ID></OTHER><ROW><ID>2</ID></ROW></LIST>');

        $this->assertSame([['ID' => '1', 'NAME' => 'ა & ბ', 'EMPTY' => ''], ['ID' => '2']], WaybillXml::rows($root, 'ROW'));
        $this->assertSame([], WaybillXml::rows($root, 'MISSING'));
        $this->assertSame([], WaybillXml::fields($root));
    }

    #[Test]
    public function an_answer_that_is_empty_or_broken_is_an_error(): void
    {
        foreach (['', "  \n ", '<A><B></A>', 'plain text'] as $xml) {
            try {
                WaybillXml::parse($xml);
                $this->fail('nothing thrown for '.var_export($xml, true));
            } catch (RsgeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame([], libxml_get_errors(), 'no parser errors are left behind');
    }

    #[Test]
    public function parsing_leaves_the_host_apps_libxml_setting_and_error_list_as_they_were(): void
    {
        $before = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            try {
                WaybillXml::parse('<A><B></A>');
                $this->fail('nothing thrown');
            } catch (RsgeException) {
                // expected
            }
            WaybillXml::parse('<A/>');

            $this->assertSame([], libxml_get_errors(), 'the errors of a broken answer are not left in the app\'s list');
            $this->assertTrue(libxml_use_internal_errors(), 'the app had them switched on and still has');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }

        libxml_use_internal_errors(false);
        try {
            WaybillXml::parse('<A><B></A>');
        } catch (RsgeException) {
            // expected
        }
        $this->assertFalse(libxml_use_internal_errors(), 'and switched off stays switched off');
        libxml_use_internal_errors($before);
    }

    #[Test]
    public function credentials_never_show_the_password_when_dumped_logged_or_stored(): void
    {
        $credentials = new ServiceUserCredentials('SHOP_ONE:206322102', 'SERVICE-PASSWORD-9');

        foreach ([json_encode($credentials), json_encode(['c' => $credentials]), print_r($credentials, true), var_export($credentials->__debugInfo(), true)] as $shown) {
            $this->assertIsString($shown);
            $this->assertStringContainsString('SHOP_ONE:206322102', $shown);
            $this->assertStringNotContainsString('SERVICE-PASSWORD-9', $shown);
        }
        $this->assertSame('{"serviceUser":"","servicePassword":""}', json_encode(new ServiceUserCredentials('', '')));
        // the service still gets the real one
        $this->assertSame('SERVICE-PASSWORD-9', $credentials->toArray()['sp']);

        $this->expectException(\LogicException::class);
        serialize($credentials);
    }

    #[Test]
    public function an_answer_cannot_pull_a_local_file_in(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rsge');
        $this->assertIsString($file);
        file_put_contents($file, 'LOCAL-FILE-CONTENT');

        try {
            $root = WaybillXml::parse('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://'.$file.'">]><r><v>&x;</v></r>');
            $this->assertStringNotContainsString('LOCAL-FILE-CONTENT', (string) $root->asXML());
            $this->assertSame('', WaybillXml::fields($root)['v'] ?? '');
        } catch (RsgeException) {
            // refusing the document outright is just as good
            $this->addToAssertionCount(1);
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function an_element_escapes_values_and_inserts_fragments_untouched(): void
    {
        $this->assertSame(
            '<W xmlns=""><A>1 &lt; 2 &amp; &quot;x&quot; &apos;y&apos;</A><B></B><C>1.5</C><D><X>raw</X></D></W>',
            WaybillXml::element('W', ['A' => '1 < 2 & "x" \'y\'', 'B' => null, 'C' => 1.5, 'D' => new XmlNode('<X>raw</X>')], root: true),
        );
        $this->assertSame('<W><A>0</A></W>', WaybillXml::element('W', ['A' => 0]));
        $this->assertSame("a\tb\nc", WaybillXml::escape("a\tb\nc\x01\x08"));
    }

    #[Test]
    public function dates_are_written_and_read_in_the_shape_rs_ge_uses(): void
    {
        $this->assertSame('2026-11-15T13:10:28', WaybillXml::date(new \DateTimeImmutable('2026-11-15 13:10:28', new \DateTimeZone('Asia/Tbilisi'))));
        $this->assertSame('2026-11-15 13:10:28', WaybillXml::parseDate('2026-11-15T13:10:28')?->format('Y-m-d H:i:s'));
        $this->assertNull(WaybillXml::parseDate(''));
        $this->assertNull(WaybillXml::parseDate(null));
        $this->assertNull(WaybillXml::parseDate('not a date'));
    }
}
