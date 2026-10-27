<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Module;

/**
 * Binds explicit removal approval to the current module and migration state.
 */
final class ModuleRemovalConfirmation
{
    public function __construct(private readonly ModuleMigrationRegistry $registry = new ModuleMigrationRegistry())
    {
    }

    /**
     * @param array{modules: list<string>, versions: list<string>, sources: list<string>, tables: list<string>} $plan
     */
    public function fingerprint(string $workspace, array $plan): string
    {
        return hash('sha256', json_encode([
            'modules' => $plan['modules'],
            'versions' => $plan['versions'],
            'sources' => $plan['sources'],
            'tables' => $plan['tables'],
            'receipts' => $this->registry->read($workspace),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{modules: list<string>, versions: list<string>, sources: list<string>, tables: list<string>} $plan
     */
    public function assertMatches(string $workspace, array $plan, string $expected): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected)
            || !hash_equals($this->fingerprint($workspace, $plan), $expected)) {
            throw new \DomainException('Confirmation de désinstallation périmée ou invalide.');
        }
    }
}
