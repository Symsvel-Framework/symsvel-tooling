# Documentation de votre projet

Le site développeur local se trouve dans dev-doc/site. Les guides SymSvel sont assemblés depuis les composants et modules installés ; le reste de dev-doc/docs est à vous. Ne modifiez pas le sous-dossier généré symsvel.

~~~bash
symsvel documentation:build-dev
~~~

Le site est régénéré après les opérations locales prises en charge par Tooling. Vous pouvez aussi le reconstruire explicitement avec cette commande.

Pour les guides destinés aux administrateurs et utilisateurs de votre application, écrivez dans admin-doc/docs et user-doc/docs. Puis actualisez le corpus privé :

~~~bash
symsvel documentation:sync
~~~

Ces guides ne sont pas exportés dans le site statique développeur. Ne publiez jamais de secrets dans vos documents.

## Navigation et lecture locale

Le thème Material for MkDocs affiche les chapitres repliables à gauche, les guides au centre et le sommaire des titres de la page à droite (sur écran large). La navigation est générée depuis les guides disponibles et les modules installés ; les pages Markdown propres à votre projet sont accessibles dans « Documentation du projet ». La recherche et les liens fonctionnent également en ouvrant `dev-doc/site/index.html` directement, sans serveur web. Aucun chargement de police externe n'est nécessaire.

Les anciennes configurations MkDocs et dépendances Python standard, non modifiées, sont migrées à la reconstruction. Si vous personnalisez `dev-doc/mkdocs.yml` ou `requirements.txt`, Tooling conserve ces fichiers : vous prenez alors en charge le thème, la navigation et leurs dépendances. La configuration générée est reconnaissable à son marqueur `symsvel-layout` ; ne modifiez pas ce marqueur pour forcer une mise à jour.
