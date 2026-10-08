<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi Domain Credit package
 *
 * https://github.com/Spoje-NET/abraflexi-domain-credit
 *
 * (c) Vítězslav Dvořák <http://spojenet.cz/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\AbraFlexiDomainCredit;

/**
 * Add Subreg credit for a paid AbraFlexi invoice containing KREDIT_DOMENA.
 *
 * Headless successor of SpojeNet\System\orderplugins\DomainCredit::settled().
 * The "API" label on the invoice is the pending flag: it is removed only
 * after the credit was really added.
 *
 * @author vitex
 */
class DomainCredit extends \Ease\Sand
{
    /**
     * AbraFlexi price list item code.
     */
    public const PRODUCT_CODE = 'KREDIT_DOMENA';

    /**
     * Label marking invoices waiting for fulfilment.
     */
    public const PENDING_LABEL = 'API';

    /**
     * External ID prefix of the Subreg user in AbraFlexi address book.
     */
    public const EXT_PREFIX = 'subreg';

    public function __construct(private ?\Subreg\Client $subreg = null) {}

    /**
     * Subreg API client (logged in on first use).
     */
    public function subreger(): \Subreg\Client
    {
        if (null === $this->subreg) {
            $this->subreg = new \Subreg\Client();
        }

        return $this->subreg;
    }

    /**
     * Process one invoice.
     *
     * @return array{status: string, message: string, invoice: string, amount: float, username: ?string, exitcode: int}
     */
    public function processInvoice(\AbraFlexi\FakturaVydana $invoice): array
    {
        $ident = (string) $invoice->getRecordIdent();
        $report = ['status' => 'skipped', 'message' => '', 'invoice' => $ident, 'amount' => 0.0, 'username' => null, 'exitcode' => 0];

        if (!$this->isPaid($invoice)) {
            $report['message'] = sprintf(_('%s is not paid'), $ident);

            return $report;
        }

        $amount = $this->creditAmount($this->itemsOfInterest($invoice));

        if ($amount <= 0.0) {
            $report['message'] = sprintf(_('%s: no %s item'), $ident, self::PRODUCT_CODE);

            return $report;
        }

        if (!\in_array(self::PENDING_LABEL, array_map([\AbraFlexi\Functions::class, 'uncode'], array_keys(\AbraFlexi\Stitek::listToArray($invoice->getDataValue('stitky')))), true)) {
            $report['message'] = sprintf(_('%s: already processed (no %s label)'), $ident, self::PENDING_LABEL);

            return $report;
        }

        $report['amount'] = $amount;
        $firma = (string) $invoice->getDataValue('firma');
        $username = $this->subregUserName($firma);
        $report['username'] = $username;

        if (null === $username) {
            return $this->fail($report, sprintf(_('%s: %s is not a Subreg client'), $ident, \AbraFlexi\Functions::uncode($firma)));
        }

        $before = $this->subregCredit($firma);
        $this->subreger()->creditCorrection($username, $amount, sprintf('faktura %s uhrazena', $ident));

        if ('ok' !== $this->subreger()->lastStatus) {
            return $this->fail($report, sprintf(_('Subreg credit correction for %s failed'), $ident));
        }

        $after = $this->subregCredit($firma);
        $this->addStatusMessage(sprintf(_('Subreg credit %s added to %s by %s'), $amount, $username, $ident), 'success');

        if (null !== $before && null !== $after && (float) $before === (float) $after) {
            $this->addStatusMessage(sprintf(_('Credit of %s did not change after correction'), $username), 'warning');
        }

        if (!$invoice->unsetLabel(self::PENDING_LABEL)) {
            // Credit is added but the pending flag remains - rerun would double it.
            return $this->fail($report, sprintf(_('%s: credit added but label %s NOT removed; do not rerun!'), $ident, self::PENDING_LABEL));
        }

        $report['status'] = 'success';
        $report['message'] = sprintf(_('%s: credit %s added to %s'), $ident, $amount, $username);

        return $report;
    }

    /**
     * Fully paid invoice? Exact state match (note "stavUhr.neuhrazeno" contains "uhrazeno").
     */
    public function isPaid(\AbraFlexi\FakturaVydana $invoice): bool
    {
        $state = (string) $invoice->getDataValue('stavUhrK');
        $remaining = (string) $invoice->getDataValue('zbyvaUhradit');

        if ('' !== $remaining && (float) $remaining > 0.0) {
            return false;
        }

        return \in_array($state, ['stavUhr.uhrazeno', 'stavUhr.uhrazenoRucne'], true) || ('' !== $remaining && 0.0 === (float) $remaining);
    }

    /**
     * Invoice items with the credit product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function itemsOfInterest(\AbraFlexi\FakturaVydana $invoice): array
    {
        $items = $invoice->getDataValue('polozkyFaktury');
        $found = [];

        foreach (\is_array($items) ? $items : [] as $item) {
            $kod = $item['kod'] ?? '';
            $kod = \is_array($kod) ? (string) ($kod['kod'] ?? reset($kod)) : (string) $kod;

            if (\AbraFlexi\Functions::uncode($kod) === self::PRODUCT_CODE) {
                $found[] = $item;
            }
        }

        return $found;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function creditAmount(array $items): float
    {
        $amount = 0.0;

        foreach ($items as $item) {
            $amount += isset($item['mnozMj']) ? (float) $item['mnozMj'] * (float) $item['cenaMj'] : (float) ($item['cenaMj'] ?? 0);
        }

        return $amount;
    }

    /**
     * Subreg user name for an AbraFlexi address (via ext:subreg:ID).
     */
    public function subregUserName(string $addressCode): ?string
    {
        $subregID = $this->subregId($addressCode);

        if (null === $subregID) {
            return null;
        }

        $users = $this->subreger()->call('Users_List');

        foreach ((int) ($users['count'] ?? 0) ? $users['users'] : [] as $user) {
            if ((string) $user['id'] === $subregID) {
                return (string) $user['username'];
            }
        }

        return null;
    }

    public function subregCredit(string $addressCode): ?string
    {
        $subregID = $this->subregId($addressCode);
        $info = $subregID ? $this->subreger()->infoUser((int) $subregID) : [];

        return \is_array($info) && \array_key_exists('credit', $info) ? (string) $info['credit'] : null;
    }

    protected function subregId(string $addressCode): ?string
    {
        $customer = new \AbraFlexi\Adresar(\AbraFlexi\Functions::code($addressCode), ['detail' => 'id']);
        $id = $customer->getExternalID(self::EXT_PREFIX);

        if (empty($id)) {
            $this->addStatusMessage(\AbraFlexi\Functions::uncode($addressCode).': '._('client without ext:subreg:xxx'), 'warning');

            return null;
        }

        return (string) $id;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function fail(array $report, string $message): array
    {
        $this->addStatusMessage($message, 'error');
        $report['status'] = 'error';
        $report['message'] = $message;
        $report['exitcode'] = 1;

        return $report;
    }
}
