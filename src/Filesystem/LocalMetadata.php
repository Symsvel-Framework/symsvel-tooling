<?php

declare(strict_types=1);

namespace SymSvel\Tooling\Filesystem;

/** Local desktop metadata, never an application or dependency file. */
final class LocalMetadata
{
    public static function isIgnored(\SplFileInfo $entry): bool
    {
        return $entry->getFilename() === '.DS_Store' && !$entry->isLink() && $entry->isFile();
    }
}
