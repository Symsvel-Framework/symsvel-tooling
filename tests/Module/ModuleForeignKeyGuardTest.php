<?php
declare(strict_types=1);
namespace SymSvel\Tooling\Tests\Module;
use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\Module\ModuleForeignKeyGuard;

final class ModuleForeignKeyGuardTest extends TestCase
{
    public function testAcceptsDeclaredCrossModuleReference(): void
    {
        (new ModuleForeignKeyGuard())->assertDeclaredTargets($this->schema('orders', 'users'), $this->modules(), 'orders');
        self::assertTrue(true);
    }
    public function testRejectsUnknownTarget(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('table non déclarée');
        (new ModuleForeignKeyGuard())->assertDeclaredTargets($this->schema('orders', 'missing'), $this->modules(), 'orders');
    }
    public function testRejectsForeignSource(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('source étrangère');
        (new ModuleForeignKeyGuard())->assertDeclaredTargets($this->schema('users', 'orders'), $this->modules(), 'orders');
    }
    private function modules(): array
    {
        return ['orders' => ['manifest' => ['tables' => ['orders']]], 'users' => ['manifest' => ['tables' => ['users']]]];
    }
    private function schema(string $source, string $target): object
    {
        return new class($source, $target) {
            public function __construct(private string $source, private string $target) {}
            public function getTables(): array
            {
                return [new class($this->source, $this->target) {
                    public function __construct(private string $source, private string $target) {}
                    public function getName(): string { return $this->source; }
                    public function getForeignKeys(): array
                    {
                        return [new class($this->target) {
                            public function __construct(private string $target) {}
                            public function getForeignTableName(): string { return $this->target; }
                        }];
                    }
                }];
            }
        };
    }
}
