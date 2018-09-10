CHANGELOG
=========

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

[Unreleased]
------------

### Added

### Changed

- IMPROVEMENT: Correction du problème de couleur sur la waveform grise des fichiers audios.


[1.7.0] - 2017-10-11
--------------------

### Added

- FEATURE: Création d'un CRON job permettant de supprimer les fichiers audio non convertis.
- FEATURE: Création d'un endpoint permettant de programmer l'encodage d'un fichier audio.

### Changed

- IMPROVEMENT: On modifie les commandes des CRON d'encodage des vidéos pour gagner en cohérence.
- IMPROVEMENT: Changement de l'arborescence du dossier uploads pour pouvoir gérer plusieurs types de fichiers.


[1.6.0] - 2017-08-10
--------------------

### Added

### Changed

- IMPROVEMENT: On modifie le nom du serveur d'encodage dans les notifs Slack.
- IMPROVEMENT: Amélioration des logs dans le endpoint de génération des screenshots pour les commentaires.


[1.5.0] - 2017-06-23
--------------------

### Added

- FEATURE: Mise en place du load balancing.

### Changed

- IMPROVEMENT: On remet en place le vrai serveur de test.


[1.4.0] - 2017-06-12
--------------------

### Added

- FEATURE: Création d'un EventListener pour loguer les erreurs lors de l'exécution de commandes.

### Changed

- BUG: Correction d'un bug dans le nom du fichier converti à copier en cas d'erreur de l'original pour la génération des screenshots de commentaires.


[1.3.0] - 2017-04-28
--------------------

### Changed

- BUG: Correction d'un bug dans le fait de ne pas tester s'il y a réellement un job à traiter.
- IMPROVEMENT: On gère mieux les exceptions au moment de la copie des fichiers vidéos pour la génération des miniatures des commentaires.


[1.2.1] - 2017-04-12
--------------------

### Changed

- BUG: Pour la génération des miniatures des commentaires, si on a un fichier .qt, on utilise plutôt le fichier converti MP4.
- BUG: Pour la génération des miniatures des commentaires, modifications du test sur l'existence du dossiers thumbnails.


[1.2.0] - 2017-04-06
--------------------

### Added

- FEATURE: Création d'un CRON job pour supprimer les fichiers vidéos copiés de manière temporaire sur le serveur pour générer les screenshots des commentaires
- FEATURE: Création d'un endpoint permettant de générer un screenshot à un instant T pour les commentaires.
- FEATURE: Intégration de notifications Slack dans le CRON d'encodage des vidéos.
- FEATURE: Installation de Slackify

### Changed

- IMPROVEMENT: On utilise un bucket dédié au stockage des screenshots des commentaires.
- IMPROVEMENT: On ajoute la locale à la requête envoyée à l'app en fin d'encodage pour shooter le mail de notif.
- IMPROVEMENT: Mise à jour de composer

[1.1.0] - 2017-03-07
--------------------

### Added

- FEATURE: On adapte la numérotation de l'app au Semantic Version (http://semver.org/)
- FEATURE: Création d'un CHANGELOG-1.0.md pour archivage
