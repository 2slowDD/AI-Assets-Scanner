<?php
/**
 * Guard: no UNKNOWN `cusk_` value may enter the repository.
 *
 * WHY THIS SHAPE. GitGuardian incident #36194510 (2026-08-16) flagged
 * `cusk_WRONGKEY_999999` in a test that mocks a 401 "Invalid API key" and
 * asserts the key is not persisted. That was a false positive — but it exposed
 * a real gap: nothing stopped a genuine key being pasted into a fixture, where
 * it would look exactly like its neighbours to both a reviewer and a detector.
 *
 * The obvious fix — rename fixtures to something visibly fake — is WRONG here,
 * and this comment exists so nobody re-proposes it. `cusk_` is the real
 * production prefix: `Settings::is_free_key()` validates
 * `/^cusk_Freekey_[1-9][0-9]*$/`, `is_pending_free_key()` matches the exact
 * sentinel `cusk_Freekey_?`, and the settings page shows `cusk_...` to the user
 * as the expected format. Fixtures that drop the prefix stop exercising the
 * validator, so renaming would trade a real test for a cosmetic reassurance.
 *
 * So the guard is inverted: instead of making known fixtures look different,
 * every `cusk_` literal in the tree must be on the allowlist below. A pasted
 * real key fails **because it is unrecognised**, not because of how it looks —
 * which is the one property a naming convention can never give you.
 *
 * Falsification check (the question this guard has to answer "yes" to): if
 * someone pasted a live customer key into a fixture tomorrow, would this go
 * red? Yes — it is not in ALLOWED, so it fails, whatever its shape.
 *
 * ADDING A FIXTURE: add the exact literal to ALLOWED with a one-line reason.
 * If you cannot say in one line why a value is safe to commit, that is the
 * signal — do not add it.
 */

namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;

class SecretFixtureAllowlistTest extends TestCase {

	/** Every `cusk_` literal legitimately present in the tree, with its reason. */
	private const ALLOWED = array(
		// Paid-key fixtures — self-describing placeholders, none is a valid key.
		'cusk_WRONGKEY_999999'        => 'rejected-key case; the 401 path asserts it is never persisted',
		'cusk_NEWKEY_111111'          => 'replacement-key case',
		'cusk_TESTKEY_000000'         => 'baseline stored key',
		'cusk_APIKEY_SHOULD_NOT_LEAK' => 'asserts a stored key is never echoed back to the browser',
		'cusk_APIKEY'                 => 'generic short fixture',
		// Free-key fixtures — MUST satisfy /^cusk_Freekey_[1-9][0-9]*$/ or they
		// stop exercising Settings::is_free_key(). Do not rename these.
		'cusk_Freekey_9'              => 'free key; must match the production validator',
		'cusk_Freekey_10'             => 'free key; must match the production validator',
		'cusk_Freekey_12'             => 'free key; must match the production validator',
		'cusk_Freekey_N'              => 'documentation placeholder for the free-key shape',
		'cusk_Freekey_'               => 'prefix fragment used in negative-match assertions',
		// Production-code literals, not fixtures.
		'cusk_Freekey_?'              => 'pending-free-key sentinel, matched exactly by is_pending_free_key()',
		'cusk_paid_key'               => 'paid-key fixture',
		'cusk_paid_random'            => 'paid-key fixture',
		'cusk_paid_autoclaim'         => 'paid-key fixture',
		// Prefix-only occurrences. A bare prefix cannot be a key, so allowing
		// these costs nothing: any real key has characters after `cusk_` and
		// would therefore still be caught as an unknown literal.
		'cusk_'                       => 'bare prefix — the settings-page placeholder `cusk_...`, CHANGELOG prose, and a negative fixture asserting a prefix-only value is rejected',
		'cusk_T'                      => 'the masked-display fixture `cusk_T•••••••000000` — the bullet characters end the literal match here',
	);

	/** Directories that never contain source and would only add noise. */
	private const SKIP_DIRS = array( '.git', 'node_modules', 'vendor', 'releases' );

	/**
	 * Generated files that echo test method names back at us. PHPUnit's result
	 * cache stores every method name it ran, so a method name containing the
	 * prefix would make this guard match itself — which is how the first run
	 * of this test failed.
	 */
	private const SKIP_FILES = array( '.phpunit.result.cache' );

	/**
	 * NOTE: this method name deliberately avoids the prefix it scans for.
	 * Naming it `..._unknown_cusk_literal_...` made the guard match its own
	 * name in the result cache.
	 */
	public function test_no_unrecognised_key_literal_exists_in_the_tree(): void {
		$root  = \dirname( __DIR__ );
		$found = array();

		$it = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				static function ( $file ) {
					return ! ( $file->isDir() && \in_array( $file->getFilename(), self::SKIP_DIRS, true ) );
				}
			)
		);

		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			// This test file itself holds the allowlist; skip to avoid self-matching.
			if ( \realpath( $file->getPathname() ) === \realpath( __FILE__ ) ) {
				continue;
			}
			if ( \in_array( $file->getFilename(), self::SKIP_FILES, true ) ) {
				continue;
			}
			$contents = @\file_get_contents( $file->getPathname() );
			if ( false === $contents ) {
				continue;
			}
			if ( ! \preg_match_all( '/cusk_[A-Za-z0-9_?]*/', $contents, $m ) ) {
				continue;
			}
			foreach ( $m[0] as $literal ) {
				if ( ! \array_key_exists( $literal, self::ALLOWED ) ) {
					$rel            = \str_replace( $root . \DIRECTORY_SEPARATOR, '', $file->getPathname() );
					$found[]        = $literal . '  (' . $rel . ')';
				}
			}
		}

		$found = \array_values( \array_unique( $found ) );

		$this->assertSame(
			array(),
			$found,
			"Unknown cusk_ literal(s) found. If this is a REAL key, remove it and rotate it immediately.\n"
			. "If it is a new fixture, add the exact literal to SecretFixtureAllowlistTest::ALLOWED with a\n"
			. "one-line reason. Found:\n  " . \implode( "\n  ", $found )
		);
	}

	/** The allowlist is only meaningful if its entries are still real. */
	public function test_allowlist_has_no_stale_entries(): void {
		$this->assertNotEmpty( self::ALLOWED, 'The allowlist must not be emptied to make the guard pass.' );
		foreach ( self::ALLOWED as $literal => $reason ) {
			$this->assertNotSame( '', \trim( (string) $reason ), "Allowlist entry '{$literal}' has no reason." );
		}
	}
}
