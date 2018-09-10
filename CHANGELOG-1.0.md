Strime versions
===============

This file is made to list the evolutions of Strime over the time.

V1.0.6
------

Launch date: 2017/01/26

  * IMPROVEMENT: Mise en place d'un exception listener pour mieux gérer les erreurs éventuelles, notamment celle que l'on renvoit en cas de connexion indue à l'API.

V1.0.5
------

Launch date: 2017/01/05

  * BUG : Correction du pb d'envoi de la requête sur le webhook de fin d'encodage en test.
  * FEATURE : On met en place Guzzle à la place des requêtes cURL.
  * FEATURE : Mise en place de tests sur les classes TokenGenerator et HeadersAuthorization.
  * FEATURE : On gère l'identification des requêtes dans un EventListener.

V1.0.4
------

Launch date: 2016/xx/xx

  * IMPROVEMENT : On passe à 6h le délai avant timeout pour les encodages.
  * IMPROVEMENT : On passe en HTTPS sur l'environnement de test.
  * IMPROVEMENT : On affine le pourcentage de progression de l'encodage en utilisant le listener de FFMPEG.
  * IMPROVEMENT : upgrade des bundles

V1.0.3
------

Launch date: 2016/08/08

  * Possibilité d'encoder des vidéos au format portrait.

V1.0.2
------

Launch date: 2016/07/19

  * On crée un CRON job qui permet de relancer les encodages bloqués à 5%.
  * On déplace la génération de la miniature dans le endpoint d'initialisation d'un encodage.

V1.0.1
------

Launch date: 2016/05/31

  * On change le fonctionnement des logs en prod.
  * On commence par supprimer les fichiers encodés du serveur s'ils existent avant de relancer un encodage.

V1.0.0
------

Launch date: 2016/05/20

  * Full version of the app with Stripe integrated, real offers, encoding out of the API, ...