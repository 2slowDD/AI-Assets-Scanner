<?php
// B4 — close the same-version JS drift class.
//
// THE BUG THIS EXISTS FOR, measured: b743c1c bumped the plugin to 1.7.94b, then ELEVEN more
// commits landed on origin/main — three of them user-visible — with no further bump. Every
// admin JS file is enqueued at ?ver=CU_SCANNER_VERSION, so two builds both calling themselves
// "1.7.94b" differed visibly in the browser and WordPress could offer no update between them.
// Only the operator noticing caught it, and it cost a full diagnostic cycle.
//
// A second instance of the same class was found on 2026-08-16: SCANNER_JS_VERSION, the console
// banner used to tell WHICH JS build a browser actually loaded, had been stuck at 1.0.10.29
// since 1.7.63b while five commits changed scanner.js underneath it. The one instrument for
// diagnosing the drift had drifted.
//
// THE GUARD IS INVERTED, deliberately — the same shape as SecretFixtureAllowlistTest. It does
// not try to detect "changed since last commit"; it pins a FINGERPRINT per released version.
// Edit any admin JS and the pin for the current version stops matching, so the suite reds until
// the version is bumped and a NEW row is added.
//
// ⛔ DO NOT "fix" a red by rewriting an existing row's hash. Each row is the fingerprint of a
// build that already shipped under that version string; rewriting one asserts that a released
// build was something other than what it was, and re-opens exactly the hole this closes. The
// correct fix is always: bump the version, add a row.
//
// ⚠️ SCOPE — the bump rule is narrower than it looks. Bump when admin/, includes/, or the root
// plugin files change. Do NOT bump for tests/, releases/, or tooling config: build-release.py's
// TOP_FILES/TOP_DIRS whitelist excludes them, so they never reach a customer, and bumping for a
// repo-only change ships two version numbers with a byte-identical ZIP — the mirror image of the
// bug above. This guard only watches admin/js, so it cannot fire on a tests-only change.
use PHPUnit\Framework\TestCase;

final class JsCacheBustDriftTest extends TestCase {

	/**
	 * Plugin version => sha256 of every cache-busted admin ASSET, concatenated in path order.
	 *
	 * ⚠️ Covers admin/css TOO, not just admin/js. The first version of this guard watched only
	 * `admin/js/*.js` — but `class-admin-pages.php:36` enqueues the stylesheet at the SAME
	 * `?ver=CU_SCANNER_VERSION`, so a CSS-only change could ship with nothing demanding a bump.
	 * That is the identical drift class this file exists to close, one directory over, and it was
	 * found the day after shipping by a CSS-only fix that the guard let through in silence.
	 *
	 * If another cache-busted asset directory is ever added, extend ASSET_GLOBS below — a guard
	 * that watches a subset of what it claims to protect is worse than none, because the green
	 * run reads as coverage.
	 */
	private const ASSET_GLOBS = array( '/admin/js/*.js', '/admin/css/*.css' );

	private const ADMIN_JS_BY_PLUGIN_VERSION = array(
		// R20 keep note + counted chip. First row: earlier builds predate the guard.
		'1.7.96b' => 'efcce89e7685eea728946ee1fc2d59a7dfa03f8e854d81fd01b5bc524f15f738',
		// FU-N — .catch() on all three settings.js fetch chains. Added, not rewritten: the
		// row above is 1.7.96b's shipped fingerprint and rewriting it would assert that a
		// released build was something other than what it was. This row is also the guard's
		// first real catch — it went red on the settings.js edit the same day it shipped.
		'1.7.97b' => 'a0d8fd4098ba6c5bc26d696e9d2443320456221f8a68196c6ca8da0881b640f9',
		// FU-AAS-TOOLTIP-SCROLLBAR-FLICKER. ⚠️ NOT COMPARABLE to the two rows above: this is the
		// first fingerprint that also covers admin/css, so it would differ from 1.7.97b even had
		// no byte changed. Rows are per-version fingerprints, never a diff between versions.
		'1.7.98b' => '0716df1d2561d11618b6fb39553885d6c650e0db3e900c269cca4c4c14268278',
		// 1.7.99b train: ET-note amber badge + plain-note muting (scanner.js + admin.css),
		// kept-chip hover tooltip with data-cu-row + title pass (scanner.js). Added, not
		// rewritten — the 1.7.98b row above is that shipped build's fingerprint.
		'1.7.99b' => 'fb730330926e5954dba60f8cc2286fcaaa8f1bf9375a63f2daab1b19ca05900f',
	);

	/**
	 * SCANNER_JS_VERSION => sha256 of admin/js/scanner.js alone. Separate from the above
	 * because this one is the console banner's build identifier, not a cache-bust key: it must
	 * move whenever scanner.js moves, even if the plugin version moved for an unrelated reason.
	 */
	private const SCANNER_JS_BY_BANNER_VERSION = array(
		'1.0.10.30' => 'bb0cc8c75e600da9f606b6bea5858c0064811d657c25f4ad214fcb2b20f6ce13',
		// positionHelpBox() + its delegated listeners (FU-AAS-TOOLTIP-SCROLLBAR-FLICKER). This
		// row exists because the guard caught the omission: scanner.js gained a function and the
		// banner had not moved, which is the second time it has fired on a real miss.
		'1.0.10.31' => '4ec33d11e90d56e69a7c3882087564e496c2a8f2f74db27b101ba7ddc5fe714a',
		// 1.7.99b train: ET noopt note gains the ⏳ prefix; kept chip gains data-cu-row and
		// the post-render title pass + buildKeptChipTitle() (FU-KEPT-BADGE-HOVER-INFO).
		'1.0.10.32' => '2877a279c39106b55da50e0932517e6b3001443a9272f8a1dc3439c90021447e',
	);

	private function root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * The version as it appears in the plugin header SOURCE, not the constant.
	 *
	 * Load-bearing: tests/bootstrap.php defines CU_SCANNER_VERSION as '1.0.0', so a guard that
	 * read the constant would compare a fixture against itself and pass no matter what shipped.
	 * That shadow is the reason the three-place lockstep was blind for as long as it was.
	 */
	private function plugin_version_from_source(): string {
		$src = (string) file_get_contents( $this->root() . '/ai-assets-scanner.php' );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $src, $m ),
			'the plugin header Version: line must be findable' );
		return $m[1];
	}

	private function scanner_js_banner_version(): string {
		$src = (string) file_get_contents( $this->root() . '/admin/js/scanner.js' );
		$this->assertSame( 1, preg_match( "/SCANNER_JS_VERSION\s*=\s*'([^']+)'/", $src, $m ),
			'SCANNER_JS_VERSION must be findable in admin/js/scanner.js' );
		return $m[1];
	}

	private function admin_js_fingerprint(): string {
		$files = array();
		foreach ( self::ASSET_GLOBS as $glob ) {
			$files = array_merge( $files, (array) glob( $this->root() . $glob ) );
		}
		sort( $files );
		$blob = '';
		foreach ( $files as $file ) {
			$blob .= (string) file_get_contents( $file );
		}
		return hash( 'sha256', $blob );
	}

	public function test_admin_js_has_not_changed_without_a_plugin_version_bump(): void {
		$version = $this->plugin_version_from_source();
		$this->assertArrayHasKey(
			$version,
			self::ADMIN_JS_BY_PLUGIN_VERSION,
			"No admin/js fingerprint is pinned for plugin version {$version}. If you bumped the "
			. 'version, add a row to ADMIN_JS_BY_PLUGIN_VERSION with the new fingerprint.'
		);
		$this->assertSame(
			self::ADMIN_JS_BY_PLUGIN_VERSION[ $version ],
			$this->admin_js_fingerprint(),
			"admin/js/*.js changed but the plugin version is still {$version}. Every admin JS "
			. 'file is enqueued at ?ver=CU_SCANNER_VERSION, so shipping this would give two '
			. 'different builds the same version and WordPress could offer no update between '
			. 'them. Bump the version in all three places (plugin header, CU_SCANNER_VERSION, '
			. 'README badge) and ADD a row here — do not rewrite the existing one.'
		);
	}

	public function test_scanner_js_has_not_changed_without_a_banner_version_bump(): void {
		$banner = $this->scanner_js_banner_version();
		$this->assertArrayHasKey(
			$banner,
			self::SCANNER_JS_BY_BANNER_VERSION,
			"No scanner.js fingerprint is pinned for SCANNER_JS_VERSION {$banner}. Add a row."
		);
		$this->assertSame(
			self::SCANNER_JS_BY_BANNER_VERSION[ $banner ],
			hash( 'sha256', (string) file_get_contents( $this->root() . '/admin/js/scanner.js' ) ),
			"admin/js/scanner.js changed but SCANNER_JS_VERSION is still {$banner}. That constant "
			. 'is the console banner, i.e. the only way to tell which JS build a browser actually '
			. 'loaded — if it does not move, the instrument for diagnosing a bad deploy is itself '
			. 'stale. Bump it and ADD a row here.'
		);
	}

	// The three plugin-version SITES agreeing with each other is already covered, and covered
	// well, by tests/VersionLockstepTest.php (FU-H) — verified by mutation rather than assumed:
	// dropping the README badge back a version reds its test_the_three_version_sites_agree.
	// This file deliberately does not repeat it. What it adds is orthogonal: the sites agreeing
	// says nothing about whether the version MOVED when the code under it did.
}
