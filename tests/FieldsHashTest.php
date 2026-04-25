<?php
// tests/FieldsHashTest.php
// Cross-repo parity: this file MUST produce byte-identical hashes to
// wpservice-saas/includes/lib-fields-hash.php. The test vectors below
// are locked-in; if they change, idempotency on the SaaS endpoint
// breaks for in-flight queued events.

require_once __DIR__ . '/../includes/lib-fields-hash.php';

class FieldsHashTest extends \PHPUnit\Framework\TestCase {

    public function test_hash_is_deterministic_across_key_orderings(): void {
        $a = cu_fields_hash( [ 'plugin' => 'rocket', 'class' => 'A', 'scan_id' => 'abc' ] );
        $b = cu_fields_hash( [ 'scan_id' => 'abc', 'class' => 'A', 'plugin' => 'rocket' ] );
        $this->assertSame( $a, $b );
    }

    public function test_hash_is_16_hex_chars(): void {
        $h = cu_fields_hash( [ 'a' => 1 ] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $h );
    }

    public function test_nested_arrays_are_deep_sorted(): void {
        $a = cu_fields_hash( [ 'fields' => [ 'b' => 2, 'a' => 1 ] ] );
        $b = cu_fields_hash( [ 'fields' => [ 'a' => 1, 'b' => 2 ] ] );
        $this->assertSame( $a, $b );
    }

    public function test_known_vector_is_stable(): void {
        // Locked-in test vector — MUST match wpservice-saas/tests/unit/FieldsHashTest.php
        // and the JSON canonicalization defined in spec §4.7.1.
        $h = cu_fields_hash( [ 'a' => 1, 'b' => [ 'd' => 4, 'c' => 3 ] ] );
        $this->assertSame(
            substr( hash( 'sha256', '{"a":1,"b":{"c":3,"d":4}}' ), 0, 16 ),
            $h
        );
    }
}
