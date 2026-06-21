<?php
namespace CUScanner\Cdn;

if ( ! defined( 'ABSPATH' ) ) exit;

interface AdapterInterface {
    public function name(): string;
    /** @param array<string,string> $headers lower-cased header name => value */
    public function detect(array $headers): bool;
    public function instructionsHtml(string $scanner_secret): string;
    public function supportsRateLimitSkip(): bool;
    public function isValidated(): bool;
}
