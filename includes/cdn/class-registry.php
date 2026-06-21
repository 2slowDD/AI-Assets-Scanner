<?php
namespace CUScanner\Cdn;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Registry {
    /** @var AdapterInterface[] */
    private array $adapters;

    /** @param AdapterInterface[] $adapters */
    public function __construct(array $adapters) {
        $this->adapters = $adapters;
    }

    /** @param array<string,string> $headers */
    public function detect(array $headers): ?AdapterInterface {
        foreach ($this->adapters as $a) {
            if ($a->detect($headers)) {
                return $a;
            }
        }
        return null;
    }

    /** @return AdapterInterface[] */
    public function all(): array {
        return $this->adapters;
    }
}
