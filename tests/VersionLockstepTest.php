<?php
// tests/VersionLockstepTest.php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;

/**
 * FU-H — the shipped version lives in THREE places that must move together.
 *
 *   1. ai-assets-scanner.php   plugin header      ` * Version:     <v>`
 *   2. ai-assets-scanner.php   `define( 'CU_SCANNER_VERSION', '<v>' )`
 *   3. README.md               shields.io badge   `![Version](.../VERSION-<v>-<hex>?...)`
 *
 * The badge is the one that drifts: it is a static literal inside a URL, so nothing in
 * the release flow touches it and a version bump that edits (1) and (2) leaves it stale.
 * The repo has shipped that drift before (memory: "README shields.io VERSION badge is a
 * static literal the version bump MISSES").
 *
 * ⛔ This test deliberately does NOT read the CU_SCANNER_VERSION *constant*.
 * tests/bootstrap.php defines it as '1.0.0' before the plugin file is ever parsed, so a
 * constant-reading assertion sees the shadow, never the shipped value, and is green for
 * every possible state of the three files. That shadow is exactly why the suite was blind
 * to this class of drift. All three values below are read as FILE TEXT.
 *
 * Anchors are matched by CONTENT, never by line number — the three sites move.
 * Each pattern must match EXACTLY ONCE: a second copy of a version literal is itself a
 * new drift surface, so "two matches" fails as loudly as "no match".
 */
class VersionLockstepTest extends TestCase {

    /** Plugin header line. `Requires PHP:` / `Requires at least:` / `Tested up to:` do not match. */
    private const HEADER_RE = '/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)[ \t]*\r?$/m';

    /** The define() SOURCE LINE — not the constant it produces. */
    private const DEFINE_RE = "/define\(\s*'CU_SCANNER_VERSION'\s*,\s*'([^']*)'\s*\)/";

    /** shields.io badge: .../badge/VERSION-<version>-<6 hex colour>?... */
    private const BADGE_RE = '#!\[Version\]\(https://img\.shields\.io/badge/VERSION-(.+?)-[0-9a-fA-F]{6}\?#';

    /** A released AAS version is x.y.z with the beta 'b' suffix this project always carries. */
    private const SHAPE_RE = '/^\d+\.\d+\.\d+[a-z]?$/';

    /**
     * Read a repo file as text.
     *
     * @param string $rel Path relative to the plugin root.
     */
    private function read_repo_file( string $rel ): string {
        $path = dirname( __DIR__ ) . '/' . $rel;
        $this->assertFileExists( $path, $rel . ' must exist at the plugin root' );
        $src = file_get_contents( $path );
        $this->assertIsString( $src, $rel . ' must be readable' );
        return $src;
    }

    /**
     * Capture the single version literal a pattern anchors on.
     *
     * @param string $re    Pattern with one capture group.
     * @param string $src   File contents.
     * @param string $where Human label for the failure message.
     */
    private function capture_one( string $re, string $src, string $where ): string {
        $n = preg_match_all( $re, $src, $m );
        $this->assertSame(
            1,
            $n,
            $where . ': expected exactly ONE version literal here, found ' . (int) $n
                . ' — zero means the anchor moved and this guard went blind; more than one'
                . ' means a second copy was added that nothing keeps in lockstep'
        );
        return $m[1][0];
    }

    public function test_the_three_version_sites_agree(): void {
        $plugin = $this->read_repo_file( 'ai-assets-scanner.php' );
        $readme = $this->read_repo_file( 'README.md' );

        $header = $this->capture_one( self::HEADER_RE, $plugin, 'ai-assets-scanner.php plugin header' );
        $define = $this->capture_one( self::DEFINE_RE, $plugin, "ai-assets-scanner.php define( 'CU_SCANNER_VERSION', … )" );
        $badge  = $this->capture_one( self::BADGE_RE, $readme, 'README.md shields.io VERSION badge' );

        // Shape first: without it, three empty captures would "agree" and pass.
        foreach ( [ 'plugin header' => $header, 'CU_SCANNER_VERSION define' => $define, 'README badge' => $badge ] as $where => $v ) {
            $this->assertMatchesRegularExpression(
                self::SHAPE_RE,
                $v,
                $where . " captured '" . $v . "', which is not an AAS version (x.y.z with optional beta suffix)"
            );
        }

        $this->assertSame(
            $header,
            $define,
            "the CU_SCANNER_VERSION define is out of lockstep with the plugin header"
        );
        $this->assertSame(
            $header,
            $badge,
            "the README shields.io VERSION badge is out of lockstep with the plugin header —"
                . " it is a static literal inside a URL, so a version bump misses it unless bumped by hand"
        );
    }

    /**
     * Guards the guard. If someone ever "simplifies" the test above to read the constant,
     * this documents what they would be reading: the bootstrap shadow, not the shipped
     * version. A lockstep assertion built on this value cannot fail.
     */
    public function test_the_constant_is_shadowed_by_the_bootstrap_and_is_not_the_shipped_version(): void {
        $this->assertSame(
            '1.0.0',
            CU_SCANNER_VERSION,
            'tests/bootstrap.php defines CU_SCANNER_VERSION — the constant is a test fixture,'
                . ' never the shipped version, so the lockstep guard must read file text instead'
        );
    }
}
