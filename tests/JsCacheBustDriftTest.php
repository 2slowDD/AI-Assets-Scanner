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

	private const ADMIN_ASSETS_BY_CACHE_VERSION = array(
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
		// 1.8.0b admin redesign: scanner workflow markup/behavior and the full visual-system CSS.
		// Added as a new release fingerprint; all previously shipped rows remain immutable.
		'1.8.0b' => '2e2805f5a26a1f6ffe1090a4a546ec38d6f3710a83559d6bb7193681060ccacf',
		// 1.8.0b post-deploy UI fixes. Public plugin version intentionally remains 1.8.0b;
		// the independent asset key forces browsers to fetch the corrected CSS and JS.
		'1.8.0b.1' => '8a5af706d79ea60b3e1cf84dfdd3e0a956370d985798587823afb1ed1539b7b6',
		// 1.8.0b typography/radar refinement plus internal-rule action eligibility.
		'1.8.0b.2' => '7cc25ddc5f9a125c819a78395d8d4fbfa9a76d11cd79279ff8c8be9e3beb903b',
		// 1.8.0b final UI refinement: 11px table headings, working Settings help, aligned
		// completion copy, consistent 12px buttons, and simplified result guidance.
		'1.8.0b.3' => '6c64fbbe2e1a7eaafddb2daec34a5012177dc26362872e6e02531c129828ab58',
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
		// 1.8.0b admin redesign: readiness summaries, step state, completion metrics, and
		// semantic S / A / N result tokens.
		'1.0.11.0' => '0e4251433dd4da43f069d415c7609f83140d2a3b6ffa6961c673aecf1abfcaa0',
		// 1.8.0b results-reference refinement: live results sidebar, kept-assets disclosure,
		// external-only guidance, and the two-line URL/suffix presentation.
		'1.0.11.1' => '904444ea666f28768c6d09061200566614205a385b5d321daf2e2eebb188663b',
		// 1.8.0b result alignment refinement: total-credit balance, inline table help icons,
		// and restored non-zero recommendation emphasis.
		'1.0.11.2' => 'c352a0e42da0571217892c8aeeeb44583bfe75bf4424f4d61b6cb23d4b312e8c',
		// 1.8.0b visual refinement: zero-result rescan visibility resets on every result
		// render while the header progress track and compact result layout stay CSS-owned.
		'1.0.11.3' => '30e8b5497eca149650aac60105ad8980c86ca39933bd8c054e46564f83be1147',
		// 1.8.0b post-deploy motion, tooltip, and result-table layout fixes.
		'1.0.11.4' => 'de666d599a64ec0e7f339c8335d308396b8b4971030917438c44bb19e36e7b22',
		// 1.8.0b mixed-scan guard: disable direct actions when no internal rules exist.
		'1.0.11.5' => '20c1c828303a3d87c5a5201427dc67b534b035c54667882f29d8bace7a1835a2',
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

	private function asset_version_from_source(): string {
		$src = (string) file_get_contents( $this->root() . '/ai-assets-scanner.php' );
		$this->assertSame( 1, preg_match( "/define\(\s*'CU_SCANNER_ASSET_VERSION',\s*'([^']+)'\s*\)/", $src, $m ),
			'CU_SCANNER_ASSET_VERSION must be findable' );
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

	public function test_admin_assets_have_not_changed_without_an_asset_version_bump(): void {
		$version = $this->asset_version_from_source();
		$this->assertArrayHasKey(
			$version,
			self::ADMIN_ASSETS_BY_CACHE_VERSION,
			"No admin asset fingerprint is pinned for cache version {$version}. If you bumped the "
			. 'asset version, add a row to ADMIN_ASSETS_BY_CACHE_VERSION with the new fingerprint.'
		);
		$this->assertSame(
			self::ADMIN_ASSETS_BY_CACHE_VERSION[ $version ],
			$this->admin_js_fingerprint(),
			"Admin assets changed but CU_SCANNER_ASSET_VERSION is still {$version}. Every admin "
			. 'asset is enqueued with that cache key, so shipping this would give two different '
			. 'builds the same browser/CDN identity. Bump CU_SCANNER_ASSET_VERSION and ADD a row '
			. 'here — do not rewrite an existing released fingerprint.'
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
