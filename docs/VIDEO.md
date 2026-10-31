# Vidéo de démonstration — script et scénario

Durée cible : **2 min 30**. Format : 1920×1080, navigateur en plein écran, zoom 125 %, thème clair. Voix off ou sous-titres en français.

Préparation : base vide (`npm run e2e:db` ou installation neuve), application d'authentification sur un téléphone (ou [2FAS](https://2fas.com) / Aegis), extension chargée, une page de connexion de démonstration (par exemple un site de test personnel en HTTPS).

| Temps | À l'écran | Texte |
|---|---|---|
| 0:00 – 0:10 | Page d'accueil, schéma d'architecture du README | « Un coffre de mots de passe où le serveur ne voit jamais rien en clair. » |
| 0:10 – 0:30 | Inscription : email, phrase de passe, la jauge passe au vert ; essai de `password1234` refusé | « Le mot de passe maître ne quitte pas le navigateur. Les mots de passe trop courants sont refusés avant tout envoi. » |
| 0:30 – 0:45 | Kit de secours, Secret Key, bouton « Imprimer » | « Une Secret Key de 128 bits est générée ici. Même si la base est volée, sans elle le coffre est incraquable. » |
| 0:45 – 1:00 | QR code, scan avec le téléphone, code, codes de secours | « La double authentification est obligatoire : sans le téléphone, impossible de télécharger le coffre chiffré. » |
| 1:00 – 1:25 | Ajout d'une entrée avec le générateur, alerte de réutilisation, recherche, copie | « Chaque entrée est chiffrée avant l'envoi. » |
| 1:25 – 1:40 | Terminal : `SELECT ciphertext FROM vault_entry` puis Outils de développement → onglet Réseau | « En base et sur le réseau, il n'y a que des blocs chiffrés. » |
| 1:40 – 2:05 | Extension : déverrouillage, page de connexion, « Remplir » ; même page sur un autre domaine : rien n'est proposé | « L'extension déchiffre elle-même et ne remplit que sur le bon site. » |
| 2:05 – 2:20 | Paramètres : export de la sauvegarde, vérification HIBP | « Sauvegardes autonomes, vérification des fuites sans révéler les mots de passe. » |
| 2:20 – 2:30 | Verrouillage automatique, puis README (tests, CI verte) | « Plus de 140 tests automatisés, dont un qui vérifie qu'aucun secret ne quitte jamais le navigateur. » |

## Conseils

- Utiliser **uniquement des données fictives**, et une Secret Key créée pour la vidéo.
- Masquer l'URL d'un vrai serveur si la vidéo est publique.
- Exporter aussi un GIF court (inscription → coffre) pour le README, avec [Peek](https://github.com/phw/peek) ou `ffmpeg`.
