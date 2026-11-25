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
