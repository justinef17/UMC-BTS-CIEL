# UMC-BTS-CIEL

Interface de monitoring de la production et consommation électrique solaire de l'UMC du Lycée Maupertuis (Saint-Malo), en partenariat avec TRIDIMENSION.

**Site en ligne :** [https://gestionenergiemaupertuis.alwaysdata.net](https://gestionenergiemaupertuis.alwaysdata.net)

---

## Équipe — BTS CIEL 2024-2026

| Membre | Partie |
|--------|--------|
| **Théo Thébault** | Partie 1 — Raspberry Pi, acquisition données VE.Bus, MariaDB locale |
| **Raphaël Houde-Hamelin** | Partie 2 — IHM Qt/C++, transfert FTPS, synchronisation BDD |
| **Justine Friant** | Partie 3 — Interface web Symfony, déploiement Alwaysdata |

**Professeurs référents :** Thierry Lafferrière, Patrick Bazin
 
**Partenaire industriel :** [TRIDIMENSION](https://tridimension.fr) — Saint-Malo

---

```
Panneaux solaires → Onduleur Victron 1600 VA → Batteries 24V
                          ↓
                    Raspberry Pi 3 (Théo)
                    Protocole VE.Bus MK2
                    Script Python (serial)
                          ↓
                    MariaDB locale
                          ↓
                    FTPS sécurisé (Raphaël)
                    IHM Qt/C++ supervision
                          ↓
                    MariaDB Alwaysdata
                          ↓
                    Symfony 7.4 (Justine)
                    Bootstrap 5 + Chart.js
                          ↓
                    https://gestionenergiemaupertuis.alwaysdata.net
```
 
---
## Partie 1 — Acquisition des données (Théo)
 
**Objectif :** Récupérer les mesures de l'onduleur Victron et les stocker en base de données locale.
 
### Technologies
- **Raspberry Pi 3** — mini-ordinateur embarqué, tourne 24h/24
- **Protocole VE.Bus MK2** — communication série avec l'onduleur Victron
- **Python** — script d'acquisition (serial, mysql-connector)
- **MariaDB locale** — stockage des mesures sur la Raspberry Pi
### Fichiers
- `mk3.py` — gestion du port série (2400 bauds, 8N1)
- `protocol.py` — décodage des trames VE.Bus (checksum, extraction)
- `decoder.py` — conversion des valeurs brutes en grandeurs physiques
- `client.py` — client MK3 : init session, requête données, lecture
- `com_test.py` — script principal d'acquisition en boucle
### Données collectées
| Mesure | Colonne BDD | Unité |
|--------|-------------|-------|
| Tension batterie | bat_tension | V |
| Courant batterie | bat_courant | A |
| État de charge | soc | % |
| Tension DC entrée | dc_in_tension | V |
| Courant DC entrée | dc_in_courant | A |
| Tension AC sortie | ac_out_tension | V |
| Courant AC sortie | ac_out_courant | A |
| Température | Temperature | °C |
 
---
 
## Partie 2 — Transfert FTPS et IHM Qt (Raphaël)
 
**Objectif :** Transférer les données de la Raspberry Pi vers le serveur distant et superviser le système via une IHM.
 
### Technologies
- **FTPS** — transfert sécurisé chiffré TLS
- **Qt / C++** — interface graphique de supervision
- **FileZilla Server** — serveur FTPS sur la Raspberry Pi
- **MariaDB** — synchronisation base locale → base distante Alwaysdata
### Fonctionnalités
- Transfert automatisé des mesures vers Alwaysdata
- IHM Qt affichant les données en temps réel
- Chiffrement TLS pour la sécurité du transfert
- Script d'insertion des données dans la BDD distante (`insert.php`)
---
 
## Partie 3 — Interface web Symfony (Justine)
 
**Objectif :** Développer une interface web pour visualiser et exporter les données électriques de l'UMC.
 
### Technologies
- **Symfony 7.4** — PHP 8.5, architecture MVC
- **Doctrine DBAL** — requêtes SQL paramétrées (protection injection SQL)
- **Twig** — moteur de templates avec héritage (`{% extends %}`)
- **Bootstrap 5** — interface responsive (mobile + desktop)
- **Chart.js** — 6 graphiques dynamiques (P = U × I calculée en JS)
- **mPDF** — génération des exports PDF
- **Symfony Mailer** — envoi emails via SMTP Alwaysdata (port 465)
- **GoatCounter** — statistiques de fréquentation (RGPD)
### Pages du site
| Page | Route | Contrôleur | Description |
|------|-------|------------|-------------|
| Accueil | `/` | AccueilController | Présentation projet, historique UMC, carousel photos |
| Synoptique | `/synoptique` | SynoptiqueController | Schéma interactif du système photovoltaïque |
| Graphiques | `/graphiques` | GraphiquesController | 6 graphiques Chart.js, filtres période, données temps réel |
| Téléchargement | `/telechargement` | TelechargementController | Export CSV, XLSX, PDF avec filtres date/source |
| Contact | `/contact` | ContactController | Formulaire sécurisé + envoi mail SMTP |
| Ressources | `/ressources` | RessourcesController | Documentation technique, liens GitHub |
 
### Sécurité
- **XSS** — `htmlspecialchars()` + `strip_tags()` sur tous les champs
- **CSRF** — token Symfony (`isCsrfTokenValid`)
- **Injection SQL** — paramètres préparés DBAL (`:debut`, `:fin`)
- **Validation** — Symfony Validator (NotBlank, Email, Length)
- **HTTPS** — certificat Let's Encrypt + Force HTTPS
- **En-têtes HTTP** — X-Frame-Options: DENY, X-Content-Type-Options: nosniff, X-XSS-Protection, Referrer-Policy
---

## Hébergement et services
| Service | Détail |
|---------|--------|
| Hébergement | Alwaysdata (gratuit) — PHP 8.5 + MariaDB |
| HTTPS | Let's Encrypt — renouvellement automatique |
| SMTP | smtp-gestionenergiemaupertuis.alwaysdata.net:465 |
| Analytics | [GoatCounter](https://umc-maupertuis.goatcounter.com) — gratuit, RGPD |
| SEO | Google Search Console — sitemap.xml soumis |
| Versionnement | GitHub — ce dépôt |
 
---
 
## Composants du système photovoltaïque
| Composant | Caractéristiques |
|-----------|-----------------|
| Panneaux solaires | Sunman 375W × 4 = 1500W, monocristallin |
| Régulateur MPPT | BlueSolar 150V / 35A |
| Onduleur | Victron 1600 VA, DC 24V → AC 230V |
| Batteries | Gel 24V, 175.8 Ah, 4219 Wh |
| Composteur | T60_40L, besoin 1.5 kW/jour |
 
---
 
*Lycée Maupertuis - Saint-Malo - BTS CIEL 2024-2026*
