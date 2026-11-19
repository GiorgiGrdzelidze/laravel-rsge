<?php

declare(strict_types=1);

namespace Rsge\Laravel\Services\Waybill;

use Illuminate\Support\Collection;
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Contracts\Service;
use Rsge\Laravel\Contracts\Transport;
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeConfigurationException;
use Rsge\Laravel\Exceptions\RsgeException;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Exceptions\RsgeUnreadMessageException;
use Rsge\Laravel\Exceptions\RsgeWaybillException;
use Rsge\Laravel\Services\Waybill\DTOs\DictionaryItem;
use Rsge\Laravel\Services\Waybill\DTOs\ErrorCode;
use Rsge\Laravel\Services\Waybill\DTOs\SavedWaybill;
use Rsge\Laravel\Services\Waybill\DTOs\ServiceUser;
use Rsge\Laravel\Services\Waybill\DTOs\ServiceUserCheck;
use Rsge\Laravel\Services\Waybill\DTOs\Waybill;
use Rsge\Laravel\Services\Waybill\Requests\WaybillDraft;
use Rsge\Laravel\Services\Waybill\Requests\WaybillFilter;
use Rsge\Laravel\Support\WaybillErrors;
use Rsge\Laravel\Support\WaybillXml;
use Rsge\Laravel\Support\XmlNode;

/**
 * The rs.ge electronic waybill service (WayBillService).
 *
 * Every operation runs as one service user (su / sp). withCredentials() gives the same
 * service for another service user, so each member of staff can act under their own.
 *
 * Operation names are rs.ge's own, misspellings included (chek_service_user, send_waybil_vd).
 */
final class WaybillService implements Service
{
    private ?int $sellerUnId = null;

    public function __construct(
        private readonly Transport $transport,
        private readonly ServiceUserCredentials $credentials,
    ) {}

    public function name(): string
    {
        return 'waybill';
    }

    /**
     * The same service acting as another service user.
     */
    public function withCredentials(ServiceUserCredentials $credentials): self
    {
        return new self($this->transport, $credentials);
    }

    // ------------------------------------------------------------------
    // service users and the server
    // ------------------------------------------------------------------

    /**
     * Whether the service user and password are right, and the ids rs.ge knows them by.
     *
     * A wrong user or password is an answer here (valid = false), not an exception.
     *
     * @throws RsgeTransportException
     */
    public function checkServiceUser(): ServiceUserCheck
    {
        $response = $this->call('chek_service_user', $this->auth());
        $valid = $this->read($response, 'chek_service_userResult') === true;
        $unId = $this->read($response, 'un_id');
        $serviceUserId = $this->read($response, 's_user_id');

        return new ServiceUserCheck(
            valid: $valid,
            unId: $valid && is_numeric($unId) ? (int) $unId : null,
            serviceUserId: $valid && is_numeric($serviceUserId) ? (int) $serviceUserId : null,
        );
    }

    /**
     * The taxpayer's service users. Needs the MAIN user and password of the taxpayer
     * (the ones for the portal), not a service user.
     *
     * @return Collection<int, ServiceUser>
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function serviceUsers(string $mainUser, #[\SensitiveParameter] string $mainPassword): Collection
    {
        if (! mb_check_encoding($mainUser, 'UTF-8') || ! mb_check_encoding($mainPassword, 'UTF-8')) {
            throw new RsgeConfigurationException('The main user and password of the taxpayer must be UTF-8 text.');
        }

        $root = $this->xml('get_service_users', ['user_name' => $mainUser, 'user_password' => $mainPassword]);

        return (new Collection(WaybillXml::rows($root, 'ServiceUser')))
            ->map(static fn (array $row): ServiceUser => ServiceUser::fromRow($row))
            ->values();
    }

    /**
     * The address rs.ge sees this machine under; a service user may be tied to one.
     *
     * @throws RsgeTransportException
     */
    public function whatIsMyIp(): string
    {
        return trim((string) $this->scalar('what_is_my_ip', []));
    }

    /**
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function serverTime(): \DateTimeImmutable
    {
        $time = WaybillXml::parseDate((string) $this->scalar('get_server_time', []));
        if ($time === null) {
            throw new RsgeException('rs.ge did not return its server time.');
        }

        return $time;
    }

    // ------------------------------------------------------------------
    // lists rs.ge keeps
    // ------------------------------------------------------------------

    /**
     * @return Collection<int, DictionaryItem>
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function types(): Collection
    {
        return $this->dictionary('get_waybill_types', 'WAYBILL_TYPE');
    }

    /**
     * @return Collection<int, DictionaryItem>
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function units(): Collection
    {
        return $this->dictionary('get_waybill_units', 'WAYBILL_UNIT');
    }

    /**
     * @return Collection<int, DictionaryItem>
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function transportTypes(): Collection
    {
        return $this->dictionary('get_trans_types', 'TRANSPORT_TYPE');
    }

    /**
     * The error codes as rs.ge lists them today. The package also carries a copy of this
     * list, which the exceptions use for their texts.
     *
     * @return Collection<int, ErrorCode>
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function errorCodes(): Collection
    {
        $root = $this->xml('get_error_codes', $this->auth());

        return (new Collection(WaybillXml::rows($root, 'ERROR_CODE')))
            ->map(static fn (array $row): ErrorCode => new ErrorCode(
                id: (int) ($row['ID'] ?? 0),
                text: html_entity_decode($row['TEXT'] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8'),
                type: ($row['TYPE'] ?? '') !== '' ? (int) $row['TYPE'] : null,
            ))
            ->values();
    }

    /**
     * The identification number of the taxpayer behind a un_id, as rs.ge itself reports it.
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     */
    public function tinFromUnId(int $unId): ?string
    {
        $tin = trim((string) $this->scalar('get_tin_from_un_id', $this->auth() + ['un_id' => $unId]));
        if (preg_match('/^-\d+$/', $tin) === 1) {
            throw WaybillErrors::exception('get_tin_from_un_id', (int) $tin);
        }

        return $tin === '' ? null : $tin;
    }

    /**
     * The registered name behind an identification number, or null when rs.ge has none.
     * For a number nobody is registered under rs.ge answers with the text "null".
     *
     * @throws RsgeTransportException
     */
    public function nameFromTin(string $tin): ?string
    {
        $name = trim((string) $this->scalar('get_name_from_tin', $this->auth() + ['tin' => trim($tin)]));
        if (preg_match('/^-\d+$/', $name) === 1) {
            throw WaybillErrors::exception('get_name_from_tin', (int) $name);
        }

        return $name === '' || strcasecmp($name, 'null') === 0 ? null : $name;
    }

    // ------------------------------------------------------------------
    // a waybill's life
    // ------------------------------------------------------------------

    /**
     * Save a new waybill, or change a saved one. A saved waybill has no number yet and
     * can still be deleted; activate() makes it official.
     *
     * @param  ?int  $sellerUnId  The seller's un_id; looked up once with chek_service_user when left out.
     *
     * @throws RsgeUnreadMessageException When a mandatory portal message has to be read first.
     * @throws RsgeAuthenticationException On a wrong service user or password.
     * @throws RsgeWaybillException When rs.ge refuses the waybill; lineErrors names the goods lines at fault.
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function save(WaybillDraft $draft, ?int $sellerUnId = null): SavedWaybill
    {
        $auth = $this->auth();
        $sellerUnId ??= $this->sellerUnId();

        $response = $this->call('save_waybill', $auth + [
            'waybill' => new XmlNode($draft->toXml($sellerUnId)),
        ]);
        $root = WaybillXml::parse(WaybillXml::fragment($this->result($response, 'save_waybill')));
        $fields = WaybillXml::fields($root);
        $status = (int) ($fields['STATUS'] ?? 0);

        $goods = [];
        foreach ($root->children() as $child) {
            if ($child->getName() === 'GOODS_LIST') {
                $goods = WaybillXml::rows($child, 'GOODS');
            }
        }

        if ($status < 0) {
            $lineErrors = [];
            foreach ($goods as $index => $row) {
                $code = (int) ($row['ERROR'] ?? 0);
                if ($code !== 0) {
                    $lineErrors[] = ['line' => $index, 'code' => $code, 'text' => WaybillErrors::text($code)];
                }
            }

            throw WaybillErrors::exception('save_waybill', $status, $lineErrors);
        }

        $id = (int) ($fields['ID'] ?? 0);
        if ($id <= 0) {
            throw new RsgeException('rs.ge accepted the waybill but returned no id.');
        }

        return new SavedWaybill(
            id: $id,
            goodsIds: array_map(static fn (array $row): int => (int) ($row['ID'] ?? 0), $goods),
        );
    }

    /**
     * Activate a saved waybill and get its number. From here it cannot be deleted, only
     * cancelled, and the buyer sees it.
     *
     * Not for a waybill handed to a carrier (WaybillDraft::$handedToCarrier): the carrier starts
     * that one, and rs.ge refuses this call for it.
     *
     * @param  ?\DateTimeInterface  $beginDate  Start the transport later; rs.ge allows at most 3 days.
     * @return string The waybill number.
     *
     * @throws RsgeUnreadMessageException
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function activate(int $waybillId, ?\DateTimeInterface $beginDate = null): string
    {
        $operation = $beginDate === null ? 'send_waybill' : 'send_waybil_vd';
        $parameters = $this->auth();
        if ($beginDate !== null) {
            $parameters['begin_date'] = WaybillXml::date($beginDate);
        }
        $parameters['waybill_id'] = $waybillId;

        $number = trim((string) $this->scalar($operation, $parameters));
        if (preg_match('/^-\d+$/', $number) === 1) {
            throw WaybillErrors::exception($operation, (int) $number);
        }
        // a waybill number is digits; anything else is not taken for one
        if (preg_match('/^\d+$/', $number) !== 1 || (int) $number === 0) {
            throw new RsgeWaybillException("rs.ge waybill operation '{$operation}' returned no waybill number.");
        }

        return $number;
    }

    /**
     * Close an active waybill.
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     */
    public function close(int $waybillId, ?\DateTimeInterface $deliveryDate = null): void
    {
        if ($deliveryDate === null) {
            $this->done('close_waybill', ['waybill_id' => $waybillId]);

            return;
        }

        $this->done('close_waybill_vd', ['delivery_date' => WaybillXml::date($deliveryDate), 'waybill_id' => $waybillId]);
    }

    /**
     * Delete a waybill that is only saved.
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     */
    public function delete(int $waybillId): void
    {
        $this->done('del_waybill', ['waybill_id' => $waybillId]);
    }

    /**
     * Cancel an activated waybill, or one that is with a carrier. It stays on rs.ge as cancelled.
     *
     * For a waybill that is with a carrier rs.ge answers the call that carries a comment with 0
     * and changes nothing; such a waybill is then cancelled without the comment, and the comment
     * is not kept anywhere.
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     */
    public function cancel(int $waybillId, ?string $comment = null): void
    {
        if ($comment === null || trim($comment) === '') {
            $this->done('ref_waybill', ['waybill_id' => $waybillId]);

            return;
        }

        $result = $this->scalar('ref_waybill_vd', $this->auth() + ['waybill_id' => $waybillId, 'comment' => $comment]);
        // only rs.ge's own 0: an answer that cannot be read is an error, as everywhere else
        if ($result !== null && is_numeric($result) && (int) $result === 0) {
            $this->done('ref_waybill', ['waybill_id' => $waybillId]);

            return;
        }
        $code = $result !== null && is_numeric($result) ? (int) $result : 0;
        if ($code !== 1) {
            throw WaybillErrors::exception('ref_waybill_vd', $code);
        }
    }

    /**
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function get(int $waybillId): Waybill
    {
        return $this->waybill('get_waybill', $this->auth() + ['waybill_id' => $waybillId]);
    }

    /**
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function getByNumber(string $waybillNumber): Waybill
    {
        return $this->waybill('get_waybill_by_number', $this->auth() + ['waybill_number' => trim($waybillNumber)]);
    }

    /**
     * The seller's waybills created in a range of at most 72 hours. Deleted ones are not listed.
     *
     * @return Collection<int, Waybill>
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function list(WaybillFilter $filter): Collection
    {
        $answer = WaybillXml::fragment($this->result($this->call('get_waybills', $this->auth() + $filter->toSoap()), 'get_waybills'));
        if (trim($answer) === '') {
            return new Collection;
        }
        $root = $this->checked('get_waybills', WaybillXml::parse($answer));

        $waybills = [];
        foreach ($root->children() as $child) {
            if ($child->getName() === 'WAYBILL') {
                $waybills[] = Waybill::fromXml($child);
            }
        }

        return new Collection($waybills);
    }

    /**
     * The printable waybill as rs.ge renders it.
     *
     * @return string The PDF's bytes.
     *
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    public function printPdf(int $waybillId): string
    {
        $pdf = $this->scalar('get_print_pdf', $this->auth() + ['waybill_id' => $waybillId]);
        if ($pdf === null || $pdf === '') {
            throw new RsgeException('rs.ge returned no printable waybill.');
        }

        return $pdf;
    }

    // ------------------------------------------------------------------

    /**
     * su and sp for a call. A service user that is not set up is refused here: sent
     * empty, rs.ge would answer "wrong service user or password", which sends people
     * looking in the wrong place.
     *
     * @return array{su: string, sp: string}
     *
     * @throws RsgeConfigurationException
     */
    private function auth(): array
    {
        // text that is no UTF-8 would be quoted back, password and all, in the SOAP encoder's error
        $readable = mb_check_encoding($this->credentials->serviceUser, 'UTF-8') && mb_check_encoding($this->credentials->servicePassword, 'UTF-8');
        if (! $this->credentials->isUsable() || ! $readable) {
            throw new RsgeConfigurationException(
                'No usable rs.ge service user is set for the waybill service: set RSGE_WAYBILL_SU and RSGE_WAYBILL_SP '
                .'(rsge.auth.waybill.su / sp), or pass ServiceUserCredentials to Rsge::waybill(). Both must be UTF-8 text.',
            );
        }

        return $this->credentials->toArray();
    }

    /**
     * The seller's un_id, asked once per service instance.
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeTransportException
     */
    private function sellerUnId(): int
    {
        if ($this->sellerUnId === null) {
            $check = $this->checkServiceUser();
            if (! $check->valid || $check->unId === null) {
                throw WaybillErrors::exception('chek_service_user', WaybillErrors::WRONG_SERVICE_USER);
            }
            $this->sellerUnId = $check->unId;
        }

        return $this->sellerUnId;
    }

    /**
     * An operation that answers with a number: 1 means done, anything else is its error code.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     */
    private function done(string $operation, #[\SensitiveParameter] array $parameters): void
    {
        $result = $this->scalar($operation, $this->auth() + $parameters);
        $code = $result !== null && is_numeric($result) ? (int) $result : 0;
        if ($code !== 1) {
            throw WaybillErrors::exception($operation, $code);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return Collection<int, DictionaryItem>
     */
    private function dictionary(string $operation, string $rowTag, #[\SensitiveParameter] array $parameters = []): Collection
    {
        $root = $this->xml($operation, $this->auth() + $parameters);

        return (new Collection(WaybillXml::rows($root, $rowTag)))
            ->map(static fn (array $row): DictionaryItem => new DictionaryItem((int) ($row['ID'] ?? 0), $row['NAME'] ?? ''))
            ->values();
    }

    /**
     * An operation that answers with an XML fragment. rs.ge reports an error as
     * <RESULT><STATUS>negative code</STATUS></RESULT> in place of the fragment.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    private function xml(string $operation, #[\SensitiveParameter] array $parameters): \SimpleXMLElement
    {
        return $this->checked($operation, WaybillXml::parse(WaybillXml::fragment($this->result($this->call($operation, $parameters), $operation))));
    }

    /**
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     */
    private function checked(string $operation, \SimpleXMLElement $root): \SimpleXMLElement
    {
        if ($root->getName() === 'RESULT') {
            $status = (int) (WaybillXml::fields($root)['STATUS'] ?? 0);
            if ($status < 0) {
                throw WaybillErrors::exception($operation, $status);
            }
        }

        return $root;
    }

    /**
     * One waybill. An answer that holds none is an error, never an empty waybill.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws RsgeAuthenticationException
     * @throws RsgeWaybillException
     * @throws RsgeTransportException
     * @throws RsgeException
     */
    private function waybill(string $operation, #[\SensitiveParameter] array $parameters): Waybill
    {
        $root = $this->xml($operation, $parameters);
        if ($root->getName() !== 'WAYBILL' || (int) (WaybillXml::fields($root)['ID'] ?? 0) <= 0) {
            throw new RsgeWaybillException("rs.ge waybill operation '{$operation}' returned no waybill.");
        }

        return Waybill::fromXml($root);
    }

    /**
     * An operation that answers with a plain value.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws RsgeTransportException
     */
    private function scalar(string $operation, #[\SensitiveParameter] array $parameters): ?string
    {
        $result = $this->result($this->call($operation, $parameters), $operation);

        return is_string($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|object
     *
     * @throws RsgeTransportException
     */
    private function call(string $operation, #[\SensitiveParameter] array $parameters): array|object
    {
        return $this->transport->call($operation, $parameters);
    }

    /**
     * @param  array<string, mixed>|object  $response
     * @return array<array-key, mixed>|object|string|null
     */
    private function result(array|object $response, string $operation): array|object|string|null
    {
        $result = $this->read($response, $operation.'Result');

        if (is_int($result) || is_float($result) || is_bool($result)) {
            return (string) (is_bool($result) ? (int) $result : $result);
        }

        return is_array($result) || is_object($result) || is_string($result) ? $result : null;
    }

    /**
     * @param  array<array-key, mixed>|object  $source
     */
    private function read(array|object $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return property_exists($source, $key) ? $source->{$key} : null;
    }
}
