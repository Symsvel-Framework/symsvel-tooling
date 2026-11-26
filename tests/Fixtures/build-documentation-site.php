<?php

declare(strict_types=1);

use SymSvel\Tooling\Installation\DevDocumentationBuilder;
use SymSvel\Tooling\Installation\WorkspaceInitializer;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = $argv[1] ?? throw new RuntimeException('Temporary workspace required');
mkdir($root.'/backend/docs/guides/01-getting-started', 0775, true);
(new WorkspaceInitializer())->initialize($root);
file_put_contents($root.'/backend/docs/guides/01-getting-started/01-install.md', "# Installer le Back\n\n## Prérequis\n\nUn guide de test.\n\n## Installation\n\nContenu.\n\n~~~bash\nsymsvel doctor\n~~~\n\n!!! zeko \"Astuce de Zeko\"\n    Vérifiez les prérequis.\n");
file_put_contents($root.'/backend/docs/guides/01-getting-started/02-setup.md', "# Setup local\n\n## Configuration\n\nContenu.\n");
file_put_contents($root.'/dev-doc/docs/project.md', "# Mes notes\n\nContenu du projet.\n");
(new DevDocumentationBuilder())->build($root);
