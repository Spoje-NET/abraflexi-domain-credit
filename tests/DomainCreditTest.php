<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi Domain Credit package
 *
 * (c) Vítězslav Dvořák <http://spojenet.cz/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\AbraFlexiDomainCredit\Tests;

use PHPUnit\Framework\TestCase;
use SpojeNet\AbraFlexiDomainCredit\DomainCredit;

class DomainCreditTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function invoice(array $data, bool $unsetOk = true): \AbraFlexi\FakturaVydana
    {
        $invoice = $this->getMockBuilder(\AbraFlexi\FakturaVydana::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDataValue', 'getRecordIdent', 'unsetLabel'])
            ->getMock();
        $invoice->method('getDataValue')->willReturnCallback(static fn ($k) => $data[$k] ?? null);
        $invoice->method('getRecordIdent')->willReturn('code:ZAL0001/2026');
        $invoice->expects($unsetOk ? $this->any() : $this->once())->method('unsetLabel')->willReturn($unsetOk);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function paid(string $kod = 'code:KREDIT_DOMENA', string $labels = 'API,SYSTEM'): array
    {
        return [
            'stitky' => $labels,
            'stavUhrK' => 'stavUhr.uhrazeno',
            'firma' => 'code:ACME',
            'polozkyFaktury' => [['kod' => $kod, 'mnozMj' => '500', 'cenaMj' => '1']],
        ];
    }

    private function credit(?\Subreg\Client $subreg, ?string $username = 'acme', array $credits = ['10', '510']): DomainCredit
    {
        $credit = $this->getMockBuilder(DomainCredit::class)
            ->setConstructorArgs([$subreg])
            ->onlyMethods(['subregUserName', 'subregCredit'])
            ->getMock();
        $credit->method('subregUserName')->willReturn($username);
        $credit->method('subregCredit')->willReturnOnConsecutiveCalls(...$credits);

        return $credit;
    }

    private function subreg(string $status): \Subreg\Client
    {
        $subreg = $this->getMockBuilder(\Subreg\Client::class)->disableOriginalConstructor()->onlyMethods(['creditCorrection'])->getMock();
        $subreg->lastStatus = $status;
        $subreg->expects($status === 'none' ? $this->never() : $this->once())->method('creditCorrection')->with('acme', 500.0, $this->anything());

        return $subreg;
    }

    public function testCreditAmount(): void
    {
        $credit = new DomainCredit($this->createStub(\Subreg\Client::class));
        $this->assertSame(500.0, $credit->creditAmount([['mnozMj' => '500', 'cenaMj' => '1']]));
        $this->assertSame(7.5, $credit->creditAmount([['cenaMj' => '7.5']]));
    }

    public function testSuccessRemovesLabel(): void
    {
        $report = $this->credit($this->subreg('ok'))->processInvoice($this->invoice($this->paid()));
        $this->assertSame('success', $report['status']);
        $this->assertSame(0, $report['exitcode']);
        $this->assertSame(500.0, $report['amount']);
    }

    public function testSubregFailureKeepsLabel(): void
    {
        $invoice = $this->invoice($this->paid());
        $invoice->expects($this->never())->method('unsetLabel');
        $report = $this->credit($this->subreg('error'))->processInvoice($invoice);
        $this->assertSame('error', $report['status']);
        $this->assertSame(1, $report['exitcode']);
    }

    public function testNoLabelIsSkippedWithoutCallingSubreg(): void
    {
        $report = $this->credit($this->subreg('none'))->processInvoice($this->invoice($this->paid('code:KREDIT_DOMENA', '')));
        $this->assertSame('skipped', $report['status']);
    }

    public function testOtherProductIsSkipped(): void
    {
        $report = $this->credit($this->subreg('none'))->processInvoice($this->invoice($this->paid('code:DOMENACZ')));
        $this->assertSame('skipped', $report['status']);
        $this->assertSame(0, $report['exitcode']);
    }

    public function testUnknownSubregClientFails(): void
    {
        $report = $this->credit($this->subreg('none'), null)->processInvoice($this->invoice($this->paid()));
        $this->assertSame('error', $report['status']);
    }

    public function testLabelRemovalFailureIsReported(): void
    {
        $report = $this->credit($this->subreg('ok'))->processInvoice($this->invoice($this->paid(), false));
        $this->assertSame('error', $report['status']);
        $this->assertStringContainsString('do not rerun', $report['message']);
    }

    public function testUnpaidInvoiceIsSkipped(): void
    {
        $data = ['stavUhrK' => 'stavUhr.neuhrazeno', 'zbyvaUhradit' => '500'] + $this->paid();
        $report = $this->credit($this->subreg('none'))->processInvoice($this->invoice($data));
        $this->assertSame('skipped', $report['status']);
    }
}
