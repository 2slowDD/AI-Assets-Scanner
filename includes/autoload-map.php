<?php
/**
 * The ONE class→file autoload map.
 *
 * Was duplicated as an inline array in ai-assets-scanner.php AND tests/bootstrap.php.
 * Both are hand-maintained, so a new class registered in one and forgotten in the other
 * leaves the whole suite green while a live site fatals on first use — the test map is
 * an injected activation path; this file makes both consumers load the real one (P17).
 *
 * Paths are relative to CU_SCANNER_DIR.
 */

defined( 'ABSPATH' ) || exit;

return [
    'CUScanner\\Plugin'           => 'includes/class-plugin.php',
    'CUScanner\\Settings'         => 'includes/class-settings.php',
    'CUScanner\\DomainNormalizer' => 'includes/class-domain-normalizer.php',
    'CUScanner\\FreeKeyBootstrap' => 'includes/class-free-key-bootstrap.php',
    'CUScanner\\ScanHistory'      => 'includes/class-scan-history.php',
    'CUScanner\\Api\\WpserviceClient' => 'includes/api/class-wpservice-client.php',
    'CUScanner\\Api\\RailwayClient'   => 'includes/api/class-railway-client.php',
    'CUScanner\\Api\\HttpException'   => 'includes/api/class-http-exception.php',
    'CUScanner\\MenuBadge'           => 'includes/class-menu-badge.php',
    'CUScanner\\Scanner\\PageDiscovery'   => 'includes/scanner/class-page-discovery.php',
    'CUScanner\\Scanner\\PluginDetector'  => 'includes/scanner/class-plugin-detector.php',
    'CUScanner\\Scanner\\BypassManager'   => 'includes/scanner/class-bypass-manager.php',
    'CUScanner\\Scanner\\OptimizerState'  => 'includes/scanner/class-optimizer-state.php',
    'CUScanner\\Scanner\\BypassHandler'   => 'includes/scanner/class-bypass-handler.php',
    'CUScanner\\Scanner\\CU_DepGraph_Island' => 'includes/scanner/class-cu-depgraph-island.php',
    'CUScanner\\Scanner\\Strategies\\AbstractOptimizerBypass' => 'includes/scanner/strategies/abstract-optimizer-bypass.php',
    'CUScanner\\Scanner\\Strategies\\FlyingPressBypass'        => 'includes/scanner/strategies/class-flying-press-bypass.php',
    'CUScanner\\Scanner\\Strategies\\SgOptimizerBypass'        => 'includes/scanner/strategies/class-sg-optimizer-bypass.php',
    'CUScanner\\Scanner\\Strategies\\HummingbirdBypass'        => 'includes/scanner/strategies/class-hummingbird-bypass.php',
    'CUScanner\\Scanner\\OptimizerBypassOrchestrator' => 'includes/scanner/class-optimizer-bypass-orchestrator.php',
    'CUScanner\\Scanner\\StrategyFactory'             => 'includes/scanner/class-strategy-factory.php',
    'CUScanner\\Scanner\\EventEmitter'    => 'includes/scanner/class-event-emitter.php',
    'CUScanner\\Scanner\\CuJsonBuilder'   => 'includes/scanner/class-cu-json-builder.php',
    'CUScanner\\Scanner\\RatchetMerger'   => 'includes/scanner/class-ratchet-merger.php',
    'CUScanner\\Scanner\\RulePusher'      => 'includes/scanner/class-rule-pusher.php',
    'CUScanner\\Scanner\\UrlPattern'      => 'includes/scanner/class-url-pattern.php',
    'CUScanner\\Scanner\\LastPushSyncUndo' => 'includes/scanner/class-last-push-sync-undo.php',
    'CUScanner\\Scanner\\SnapshotManager' => 'includes/scanner/class-snapshot-manager.php',
    'CUScanner\\Scanner\\GroupVersionManager' => 'includes/scanner/class-group-version-manager.php',
    'CUScanner\\Scanner\\Outbox'             => 'includes/scanner/class-outbox.php',
    'CUScanner\\Admin\\AdminPages'        => 'admin/class-admin-pages.php',
    'CUScanner\\Admin\\SettingsAjax'      => 'admin/class-settings-ajax.php',
    'CUScanner\\Admin\\ScannerAjax'       => 'admin/class-scanner-ajax.php',
    'CUScanner\\Admin\\PrivateUpdater'    => 'includes/admin/class-private-updater.php',
    'CUScanner\\Scanner\\RestPreflight'       => 'includes/scanner/class-rest-preflight.php',
    'CUScanner\\Admin\\OptimizerStateNotices' => 'includes/admin/class-optimizer-state-notices.php',
    'AIAS_Broken_Banner'                     => 'includes/class-broken-banner.php',
    'AIAS_Scan_Status'                       => 'includes/class-scan-status.php',
    'CUScanner\\Cdn\\AdapterInterface'       => 'includes/cdn/interface-adapter.php',
    'CUScanner\\Cdn\\Registry'               => 'includes/cdn/class-registry.php',
    'CUScanner\\Cdn\\CloudflareAdapter'      => 'includes/cdn/class-cloudflare-adapter.php',
    'CUScanner\\Cdn\\GenericAdapter'         => 'includes/cdn/class-generic-adapter.php',
    'CUScanner\\Cdn\\Detector'              => 'includes/cdn/class-detector.php',
    'CUScanner\\Migrations'                  => 'includes/class-migrations.php',
];
