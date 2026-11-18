<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Tests\I18n;

use PHPUnit\Framework\TestCase;
use SymSvel\Tooling\I18n\TranslationSourceCatalog;

final class TranslationSourceCatalogTest extends TestCase
{
    public function testCollectsSystemAndModuleSourcesAndRejectsExecutableTypescript(): void
    {
        $root = sys_get_temp_dir().'/symsvel-sources-'.bin2hex(random_bytes(6));
        mkdir($root.'/backend', 0775, true);
        mkdir($root.'/bo/src/lib/translations/system', 0775, true);
        mkdir($root.'/module/bo/src/lib/translations', 0775, true);
        try {
            file_put_contents($root.'/bo/src/lib/translations/system/fr.ts', 'export default {"pagination":{"first":"Première page"}} as const;');
            file_put_contents($root.'/module/bo/src/lib/translations/fr.ts', 'export default {"title":"Administrateurs","strength":{"0":"Aucune saisie"}} as const;');
            file_put_contents($root.'/module/bo/src/lib/translations/en.ts', 'export default {"title":"Administrators"} as const;');
            $catalog = new TranslationSourceCatalog();
            $modules = [['module' => 'admins', 'path' => $root.'/module', 'components' => ['bo' => 'bo']]];
            $result = $catalog->collect($root.'/backend', $modules);
            self::assertSame(1, $result['schema']);
            self::assertCount(4, $result['entries']);
            self::assertContains(['scope' => 'bo', 'module' => 'admins', 'key' => 'strength.0', 'language' => 'fr', 'value' => 'Aucune saisie'], $result['entries']);
            self::assertContains(['scope' => 'bo', 'module' => 'system', 'key' => 'pagination.first', 'language' => 'fr', 'value' => 'Première page'], $result['entries']);
            self::assertContains(['scope' => 'bo', 'module' => 'admins', 'key' => 'title', 'language' => 'en', 'value' => 'Administrators'], $result['entries']);
            $manifest = $catalog->publish($root.'/backend', $result);
            self::assertFileExists($manifest);
            self::assertSame($result, json_decode((string) file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR));
            self::assertSame($manifest, $catalog->publish($root.'/backend', $result));
            file_put_contents($root.'/module/bo/src/lib/translations/en.ts', 'export default loadDynamicTranslations() as const;');
            $this->expectException(\InvalidArgumentException::class);
            $catalog->collect($root.'/backend', $modules);
        } finally {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $entry) {
                $entry->isFile() || $entry->isLink() ? unlink($entry->getPathname()) : rmdir($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public function testRejectsAmbiguousFlattenedKeysAndJsonArrays(): void
    {
        $root = sys_get_temp_dir().'/symsvel-source-invalid-'.bin2hex(random_bytes(6));
        mkdir($root.'/backend', 0775, true);
        mkdir($root.'/bo/src/lib/translations/system', 0775, true);
        try {
            $path = $root.'/bo/src/lib/translations/system/fr.ts';
            $catalog = new TranslationSourceCatalog();
            foreach ([
                'export default {"label":["one","two"]} as const;',
                'export default {"a.b":"one","a":{"b":"two"}} as const;',
            ] as $invalid) {
                file_put_contents($path, $invalid);
                try {
                    $catalog->collect($root.'/backend', []);
                    self::fail('Invalid source must never produce an inventory.');
                } catch (\InvalidArgumentException) {
                    // Validation must happen before an inventory can be published.
                }
            }
        } finally {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $entry) {
                $entry->isFile() || $entry->isLink()
                    ? unlink($entry->getPathname()) : rmdir($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public function testReservesSystemAndGeneratedModuleNamespaces(): void
    {
        $root = sys_get_temp_dir().'/symsvel-source-reserved-'.bin2hex(random_bytes(6));
        mkdir($root.'/backend', 0775, true);
        mkdir($root.'/bo/src/lib', 0775, true);
        try {
            foreach (['system', 'generated'] as $owner) {
                try {
                    (new TranslationSourceCatalog())->collect($root.'/backend', [[
                        'module' => $owner, 'path' => $root.'/absent',
                        'components' => ['bo' => 'bo'],
                    ]]);
                    self::fail('A reserved module name cannot own translation sources.');
                } catch (\InvalidArgumentException) {
                }
            }
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isFile() || $item->isLink()
                    ? unlink($item->getPathname()) : rmdir($item->getPathname());
            }
            rmdir($root);
        }
    }
}
