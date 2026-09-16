<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\Scraper;

use PHPUnit\Framework\TestCase;
use Project1960\CaseChargeFocusBackfill;
use Project1960\Schema;
use Project1960\Scraper\PressReleaseChargeParser;
use PDO;

final class PressReleaseChargeParserTest extends TestCase
{
    public function testParsesThaiStructuredDefendantChargesAndDockets(): void
    {
        $html = <<<'HTML'
<p>This case is filed as United States v. Michael Morris, et al., 17-cr-107 (DWF/TNL) and United States v. Sumalee Intarathong, et al., 16-cr-257 (DWF/TNL).</p>
<p><strong>MICHAEL J. MORRIS, 65</strong></p>
<p>Seal Beach, Calif.</p>
<p>Convicted:</p>
<ul>
	<li>Conspiracy to commit sex trafficking, 1 count</li>
	<li>Conspiracy to engage in money laundering, 1 count</li>
</ul>
<p><strong>BHUNNA WIN, 51</strong></p>
<p>San Diego, Calif.</p>
<p>Convicted:</p>
<ul>
	<li>Unlicensed money transmitting business, 1 count</li>
</ul>
HTML;
        $parsed = (new PressReleaseChargeParser())->parse($html);
        self::assertSame('structured', $parsed['mode']);
        self::assertCount(3, $parsed['charges']);
        $win = array_values(array_filter(
            $parsed['charges'],
            static fn (array $c): bool => ($c['defendant'] ?? '') === 'BHUNNA WIN'
        ));
        self::assertCount(1, $win);
        self::assertTrue($win[0]['is_1960']);
        self::assertSame('Unlicensed money transmitting business', $win[0]['charge_description']);
        self::assertSame(1, $win[0]['count_num']);

        $morris = array_values(array_filter(
            $parsed['charges'],
            static fn (array $c): bool => ($c['defendant'] ?? '') === 'MICHAEL J. MORRIS'
        ));
        self::assertFalse($morris[0]['is_1960']);

        $nums = array_column($parsed['docket_refs'], 'docket_number');
        self::assertContains('17-cr-107', $nums);
        self::assertContains('16-cr-257', $nums);
    }

    public function testProseExtractsUnlicensedMoneyPhrase(): void
    {
        $html = '<p>KuCoin pled guilty today to one count of operating an unlicensed money transmitting business.</p>';
        $parsed = (new PressReleaseChargeParser())->parse($html);
        self::assertSame('prose', $parsed['mode']);
        self::assertNotSame([], $parsed['charges']);
        self::assertTrue($parsed['charges'][0]['is_1960']);
    }

    public function testBackfillWritesFocusRows(): void
    {
        $path = sys_get_temp_dir() . '/p1960_bf_' . bin2hex(random_bytes(4)) . '.db';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($pdo);
        $html = <<<'HTML'
<p>United States v. Michael Morris, et al., 17-cr-107 and United States v. Sumalee Intarathong, et al., 16-cr-257.</p>
<p><strong>BHUNNA WIN, 51</strong></p>
<p>Convicted:</p>
<ul><li>Unlicensed money transmitting business, 1 count</li></ul>
<p><strong>MICHAEL J. MORRIS, 65</strong></p>
<p>Convicted:</p>
<ul><li>Conspiracy to commit sex trafficking, 1 count</li></ul>
HTML;
        $pdo->exec(
            "INSERT INTO cases (id, title, date, body, url, verified_1960, mentions_crypto)
             VALUES ('thai', 'Thai Sex Trafficking', '1544745600', " . $pdo->quote($html) . ", 'https://ex/thai', 1, 0)"
        );
        $pdo->exec(
            "INSERT INTO courtlistener_dockets (cl_docket_id, docket_number, case_name)
             VALUES (7508872, '0:17-cr-00107', 'United States v. Morris')"
        );
        $pdo->exec(
            "INSERT INTO case_courtlistener_links (case_id, cl_docket_id, match_method)
             VALUES ('thai', 7508872, 'auto_docket_court')"
        );
        $pdo->exec(
            "INSERT INTO charges (case_id, charge_description, defendant, is_1960)
             VALUES ('thai', 'Conspiracy to commit sex trafficking', 'WARALEE WANLESS', 0)"
        );

        $r = (new CaseChargeFocusBackfill($pdo))->processCase('thai', dryRun: false);
        self::assertSame('structured', $r['mode']);
        self::assertSame(1, $r['charges_1960']);
        self::assertSame(2, $r['docket_refs']);
        self::assertSame(1, $r['links_tagged']);

        $focus = $pdo->query(
            "SELECT defendant, charge_description FROM charges WHERE case_id='thai' AND is_1960=1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('BHUNNA WIN', $focus['defendant']);
        $rel = $pdo->query(
            "SELECT relevance FROM case_courtlistener_links WHERE case_id='thai'"
        )->fetchColumn();
        self::assertSame('ambient', $rel);
        $refs = (int) $pdo->query("SELECT COUNT(*) FROM case_docket_refs WHERE case_id='thai'")->fetchColumn();
        self::assertSame(2, $refs);

        unlink($path);
    }
}
