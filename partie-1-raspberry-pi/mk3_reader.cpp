


#include <iostream>      // Affichage console (cout, cerr)

#include <vector>        // Tableau dynamique (pour stocker les octets)

#include <cstring>       // Manipulation de chaines (strlen)

#include <cstdint>       // Types uint8_t, uint16_t, etc.

#include <fcntl.h>        // Ouverture fichier (open)

#include <termios.h>     // Configuration port serie (termios, tcgetattr)

#include <unistd.h>      // Lecture/ecriture (read, write, close, usleep)

#include <sys/select.h>  // Surveillance port serie (select)

#include <iomanip>       // Formatage sortie (setw, fixed, setprecision)




// Port serie du MK3 sur le Raspberry Pi

const char* SERIAL_PORT = "/dev/ttyUSB0";


// Vitesse de communication avec le MK3

// 2400 bauds est le standard Victron pour le protocole VE.Bus

const speed_t BAUDRATE = B2400;





// Traduit le code produit (byte recu de l'onduleur) en nom lisible

// Chaque modele Victron a un code unique

const char* prod_name(uint8_t b) {

    switch (b) {

        case 0x28: return "MultiPlus";      // 0x28 = MultiPlus (le tien)

        case 0x01: return "MultiPlus (old)";  // Modele ancien

        case 0x14: return "MultiPlus 5000";    // MultiPlus 5000VA

        case 0x20: return "Quattro";           // Quattro

        case 0x10: return "MultiPlus-II";      // MultiPlus nouvelle generation

        case 0x03: return "Phoenix";           // Phoenix Inverter

        default:   return "inconnu";           // Code non reconnu

    }

}


// Traduit le code d'etat (2 bytes) en description lisible

// Le code etat est un 16-bit: les 8 bits hauts = mode principal,

// les 8 bits bas = mode secondaire (Bulk, Float, etc.)

const char* state_name(uint16_t s) {

    // Extraire les 8 bits hauts pour trouver le mode principal

    switch (s & 0xFF00) {

        case 0x0100: return "Inverter";   // Conversion DC->AC (sur batterie)

        case 0x0200: return "Passthru";   // Le courant passe direct (sans conversion)

        case 0x0300: return "Charge";     // Chargement batterie

        case 0x0400: return "Discharge";  // Decharge batterie

    }

    // Codes speciaux (mode secondaire)

    switch (s & 0xFF) {

        case 0x00: return "Off";         // Eteint

        case 0x01: return "Low power";   // Mode veille / economie

        case 0x02: return "Fault";       // Erreur / defaut

        case 0x03: return "Bulk";        // Phase de charge rapide (bulk)

        case 0x04: return "Absorption";  // Phase absorption (charge maintient)

        case 0x05: return "Float";       // Phase floating (maintien charge)

        case 0x06: return "Storage";     // Mode stockage (batterie pleine)

        case 0x07: return "Equalize";     // Phase equalisation

        default:   return "unknown";      // Inconnu

    }

}




// Lit les donnees du port serie avec un timeout

// @param fd        : descripteur du port serie (ouvert avec open())

// @param buf       : buffer ou stocker les octets lus

// @param len       : taille maximum du buffer

// @param timeout_ds: timeout en decisecondes (1 deciseconde = 0.1 seconde)

// @return          : nombre d'octets lus, ou 0 si timeout

int read_wait(int fd, uint8_t* buf, size_t len, int timeout_ds) {

    fd_set fds;                     // Ensemble de descripteurs a surveiller

    FD_ZERO(&fds);                  // Initialiser l'ensemble a vide

    FD_SET(fd, &fds);               // Ajouter notre port serie a l'ensemble


    struct timeval tv;              // Structure de configuration du timeout

    tv.tv_sec = timeout_ds / 10;              // Secondes (timeout_ds / 10)

    tv.tv_usec = (timeout_ds % 10) * 100000;   // Microsecondes (reste * 100000)


    // select() bloque jusqu'a ce que des donnees arrivent OU le timeout expire

    // retour: >0 = donnees pretes, 0 = timeout, <0 = erreur

    int ret = select(fd + 1, &fds, nullptr, nullptr, &tv);

    if (ret <= 0) return 0;  // Timeout ou erreur


    // Lecture des octets disponibles

    return static_cast<int>(read(fd, buf, len));

}


// Lit TOUTES les donnees disponibles en plusieurs appels

// Contrairement a read_wait() qui lit une seule fois, drain() attend

// et lit jusqu'a ce qu'il n'y ait plus de donnees pendant 0.2s

// @param fd        : descripteur du port serie

// @param buf       : buffer de stockage

// @param len       : taille maximum du buffer

// @param timeout_ds: timeout entre chaque lecture

// @return          : nombre total d'octets lus

int drain(int fd, uint8_t* buf, size_t len, int timeout_ds) {

    int total = 0;  // Compteur total d'octets lus


    // Boucle: lire tant qu'on a de la place et que des donnees arrivent

    while (total < (int)len) {

        int n = read_wait(fd, buf + total, len - total, timeout_ds);

        if (n <= 0) break;  // Plus rien -> on sort

        total += n;          // Ajouter les octets lus au total

        usleep(20000);       // Pause 20ms entre chaque lecture

    }

    return total;

}


// Convertit une chaine hexadecimale en bytes et envoie sur le port serie

// Exemple: "02FF56A9" -> envoie 4 octets: 0x02, 0xFF, 0x56, 0xA9

// @param fd       : descripteur du port serie

// @param hex_str  : chaine de caracteres hexadecimaux (pair de caracteres)

void send_hex(int fd, const char* hex_str) {

    std::vector<uint8_t> bytes;  // Tableau dynamique pour stocker les octets


    // Parcourir la chaine 2 caracteres par 2

    for (size_t i = 0; i < strlen(hex_str); i += 2) {

        // Extraire 2 caracteres (ex: "FF") et convertir en byte (0xFF)

        bytes.push_back(static_cast<uint8_t>(strtol(hex_str + i, nullptr, 16)));

    }


    // Envoyer tous les octets sur le port serie

    write(fd, bytes.data(), bytes.size());

    usleep(5000);  // Pause 5ms apres l'envoi (laisse le temps au MK3 de traiter)

}


// ============================================================================

// FONCTIONS DE DECODAGE DES DONNEES

// ============================================================================


// Decode une trame complete de 78 bytes recue du MK3

// Format d'un sous-trame de 9 octets:

//   [07][FF][56][prod][state_hi][state_lo][data_hi][data_lo][cs]

//   |    |   |   |    |        |        |        |        |

//   |    |   |   |    |        |        |        |        +-- Checksum (somme = 0)

//   |    |   |   |    |        |        |        +----------- Data bas (byte faible)

//   |    |   |   |    |        |        +------------------- Data haut (byte fort)

//   |    |   |   |    |        +-------------------------- Code etat (byte faible)

//   |    |   |   |    +----------------------------------- Code etat (byte fort)

//   |    |   |   +-------------------------------------- Code produit

//   |    |   +------------------------------------------ Marqueur VE.Bus (toujours FF 56)

//   |    +---------------------------------------------- Marqueur VE.Bus (toujours FF)

//   +--------------------------------------------------- ACK (confirmation, toujours 07)

// @param buf: trame brute recue

// @param len: longueur de la trame

void parse_full_response(const uint8_t* buf, int len) {

    if (len < 10) return;  // Trame trop courte -> on ignore


    // Le premier octet (0x4E = 78) indique la longueur totale du message

    // Les donnees commencent a l'octet 1 (apres le 0x4E)

    int pos = 1;

    std::cout << "  Trame complete (" << len << " bytes):\n";


    int frame_idx = 0;  // Compteur de sous-trames decodees


    // Parcourir la trame par blocs de 9 octets

    while (pos + 9 <= len) {

        // Rechercher le marqueur VE.Bus (FF 56) qui debute chaque sous-trame

        uint8_t sig1 = buf[pos];      // Premier octet du marqueur (doit etre 0xFF)

        uint8_t sig2 = buf[pos + 1];   // Deuxieme octet du marqueur (doit etre 0x56)


        if (sig1 == 0xFF && sig2 == 0x56) {

            // Trame valide trouvee -> decoder les champs


            // Extraire le code produit (octet 2 du sous-trame)

            uint8_t prod = buf[pos + 2];


            // Extraire le code etat (2 octets: pos+3 et pos+4, big-endian)

            // Big-endian: octet fort d'abord (pos+4), puis octet faible (pos+3)

            // Exemple: [DB] [11] -> 0xDB11 = 0xDB*256 + 0x11 = 56081

            uint16_t state = (buf[pos + 4] << 8) | buf[pos + 3];


            // Extraire la donnee haute (2 octets: pos+5 et pos+6, big-endian)

            uint16_t data_hi = (buf[pos + 6] << 8) | buf[pos + 5];


            // Extraire la donnee basse (2 octets: pos+7 et pos+8, big-endian)

            uint16_t data_lo = (buf[pos + 8] << 8) | buf[pos + 7];


            // Ignorer le checksum (derniere ligne: ne sert que pour la verification)

            (void)buf[pos + 9];


            // Verifier le checksum: somme de tous les octets de la sous-trame

            // doit etre egale a 0 (complement a 2)

            // Exemple: 07 + FF + 56 + 28 + DB + 11 + 42 + 00 = 0x15D -> -0x15D = 0x4E (checksum)

            uint8_t sum = 0;

            for (int i = pos; i < pos + 9; ++i) sum += buf[i];

            bool cs_ok = (sum == 0);  // true = checksum valide, false = erreur


            // Afficher les informations decodees

            std::cout << "  Frame[" << frame_idx << "] ";

            std::cout << "prod=" << prod_name(prod) << " ";

            std::cout << "state=0x" << std::hex << state << std::dec

                      << "(" << state_name(state) << ") ";

            std::cout << "data_hi=0x" << std::hex << data_hi << std::dec << " ";

            std::cout << "data_lo=0x" << std::hex << data_lo << std::dec << " ";

            std::cout << "cs=" << (cs_ok ? "OK" : "BAD") << "\n";


            // Interpreter la donnee haute de plusieurs facons

            // (on ne sait pas encore quelle echelle est la bonne)

            if (data_hi > 0) {

                std::cout << "           data_hi => ";

                std::cout << std::fixed << std::setprecision(1);

                std::cout << "x1=" << data_hi << " ";                    // Unite brute

                std::cout << "/10=" << (data_hi/10.0) << " ";            // 0.1V ou 0.1A par unite

                std::cout << "/100=" << (data_hi/100.0) << " ";          // 0.01V ou 0.01A par unite

                std::cout << "/1000=" << (data_hi/1000.0) << "\n";       // 0.001 par unite

            }

            // Interpreter la donnee basse

            if (data_lo > 0) {

                std::cout << "           data_lo => ";

                std::cout << std::fixed << std::setprecision(1);

                std::cout << "x1=" << data_lo << " ";

                std::cout << "/10=" << (data_lo/10.0) << " ";

                std::cout << "/100=" << (data_lo/100.0) << " ";

                std::cout << "/1000=" << (data_lo/1000.0) << "\n";

            }


            pos += 9;       // Avancer de 9 octets pour lire le prochain frame

            frame_idx++;    // Incrementer le compteur de frames

        } else {

            // Pas de marqueur VE.Bus trouve -> avancer octet par octet

            pos++;

        }

    }


    // Methode alternative: parser depuis le debut (sans tenir compte du 0x4E initial)

    std::cout << "\n  Alternative (depuis byte 0):\n";

    pos = 0;

    frame_idx = 0;

    while (pos + 9 <= len) {

        if (buf[pos] == 0xFF && buf[pos+1] == 0x56) {

            uint16_t state = (buf[pos+4] << 8) | buf[pos+3];

            uint16_t dh = (buf[pos+6] << 8) | buf[pos+5];

            uint16_t dl = (buf[pos+8] << 8) | buf[pos+7];

            uint8_t sum = 0;

            for (int i = pos; i < pos + 9; ++i) sum += buf[i];

            std::cout << "  [" << frame_idx << "] state=0x" << std::hex << state << std::dec

                      << " dh=0x" << std::hex << dh << std::dec << " dl=0x" << std::hex << dl << std::dec

                      << " cs=" << (sum==0 ? "OK" : "BAD") << "\n";

            std::cout << "       dh/10=" << (dh/10.0) << " dh/100=" << (dh/100.0)

                      << " dl/10=" << (dl/10.0) << " dl/100=" << (dl/100.0) << "\n";

            pos += 9;

            frame_idx++;

        } else {

            pos++;

        }

    }

}


// Essaie d'extraire les mesures (tension, courant) de la trame

// Parcourt tous les offsets possibles et affiche les valeurs plausibles

// @param buf: trame brute recue

// @param len: longueur de la trame

void extract_measurements(const uint8_t* buf, int len) {

    std::cout << "\n  --- Tentatives d'interpretation ---\n";


    // Pour chaque position de depart possible

    for (int start = 0; start < len - 1; ++start) {

        // Lire 2 octets en big-endian (octet fort d'abord)

        uint16_t val = (buf[start] << 8) | buf[start + 1];


        // Calculer les valeurs selon differentes echelles

        float f10  = val / 10.0f;   // 0.1V ou 0.1A par unite

        float f100 = val / 100.0f;  // 0.01V ou 0.01A par unite


        // Si la valeur ressemble a une tension AC (180-280V en 0.1V = 1800-2800)

        if (f10 > 180 && f10 < 300) {

            std::cout << "  offset " << start << ": AC voltage = " << f10 << "V (0.1V/unit)\n";

        }

        // Si la valeur ressemble a une batterie 24V (20-30V en 0.01V = 2000-3000)

        if (f100 > 20 && f100 < 60) {

            std::cout << "  offset " << start << ": Battery = " << f100 << "V (0.01V/unit)\n";

        }

        // Si la valeur ressemble a une batterie en 0.1V (200-600 = 20V-60V)

        if (f10 > 200 && f10 < 600) {

            std::cout << "  offset " << start << ": Battery = " << f10 << "V (0.1V/unit)\n";

        }

        // Si la valeur ressemble a un courant (0-150A en 0.1A = 0-1500)

        if (f10 > 0 && f10 < 150) {

            std::cout << "  offset " << start << ": Current = " << f10 << "A\n";

        }

    }

}




int main() {

    std::cout << "=== VE.Bus MK3 Reader v8 ===\n";

    std::cout << "MultiPlus 24V/1600VA (PMP242160000)\n\n";



    // ETAPE 1: Ouvrir et configurer le port serie

    

    // Ouvrir le port USB en lecture/ecriture (O_RDWR)

    // O_NOCTTY: ne pas devenir le terminal de controle du processus

    // O_NDELAY: ne pas attendre de signal DCD (ne pas bloquer)

    int fd = open(SERIAL_PORT, O_RDWR | O_NOCTTY | O_NDELAY);

    if (fd < 0) {

        std::cerr << "Erreur: impossible d'ouvrir " << SERIAL_PORT << "\n";

        return 1;  // Quitter avec erreur

    }


    // Enlever le mode bloquant (pour que read() ne bloque pas indefiniment)

    fcntl(fd, F_SETFL, 0);


    // Configurer les parametres du port serie

    struct termios options;

    tcgetattr(fd, &options);  // Lire la configuration actuelle


    // Vitesse d'emission et de reception: 2400 bauds

    cfsetispeed(&options, BAUDRATE);

    cfsetospeed(&options, BAUDRATE);


    // Options du port:

    // CLOCAL: ignorer les signaux de controle modem (CD, RI)

    // CREAD: autoriser la reception de caracteres

    options.c_cflag |= CLOCAL | CREAD;


    // Pas de parite (N = None)

    options.c_cflag &= ~PARENB;


    // Pas de bit de stop (1 bit de stop)

    options.c_cflag &= ~CSTOPB;


    // 8 bits de donnees (standard)

    options.c_cflag &= ~CSIZE;

    options.c_cflag |= CS8;


    // Desactiver les modes canonique et echo (traitement minimal)

    options.c_lflag &= ~(ICANON | ECHO | ECHOE | ISIG);


    // Desactiver le controle de flux materiel et logiciel

    options.c_iflag &= ~(IXON | IXOFF | IXANY);


    // Desactiver le traitement de sortie (pas de conversion)

    options.c_oflag &= ~OPOST;


    // Parametres de lecture non-bloquante:

    // VMIN=0:  pas de minimum de caracteres requis

    // VTIME=3: timeout de 0.3 seconde (3 * 0.1s)

    options.c_cc[VMIN] = 0;

    options.c_cc[VTIME] = 3;


    // Appliquer la configuration immediatement (TCSANOW)

    tcsetattr(fd, TCSANOW, &options);


    // Buffer pour stocker les donnees recues

    uint8_t buf[512];


  

    // ETAPE 2: Envoi de la commande d'initialisation


    std::cout << "[Init]\n";


    // Vider les buffers serie (eviter de lire des donnees anciennes)

    tcflush(fd, TCIOFLUSH);


    // Commande "Identify" pour demander au MK3 son identite

    // 02 = longueur (2 octets de donnees)

    // FF 56 = marqueur VE.Bus

    // A9 = checksum (02 + FF + 56 = 0x157 -> -0x157 = 0xA9)

    send_hex(fd, "02FF56A9");


    // Attendre 0.8 seconde que le MK3 reponde

    usleep(800000);


    // Lire la reponse (drain pour tout lire)

    int n = drain(fd, buf, sizeof(buf), 20);

    if (n > 0) {

        // Afficher les premiers octets en hexa

        std::cout << "  Recu " << n << " bytes: ";

        for (int i = 0; i < std::min(n, 32); ++i) printf("%02X", buf[i]);

        if (n > 32) std::cout << "...";

        std::cout << "\n";


        // Decoder la trame complete

        parse_full_response(buf, n);


        // Extraire les mesures plausibles

        extract_measurements(buf, n);

    }



    // ETAPE 3: Envoi de la commande de login


    std::cout << "\n[Login]\n";

    tcflush(fd, TCIFLUSH);         // Vider le buffer d'entree uniquement

    usleep(100000);                // Attendre 0.1 seconde

    send_hex(fd, "02FF564FAB");    // Commande de connexion

    usleep(500000);               // Attendre 0.5 seconde

    n = drain(fd, buf, sizeof(buf), 10);

    if (n > 0) {

        std::cout << "  Recu " << n << " bytes: ";

        for (int i = 0; i < std::min(n, 32); ++i) printf("%02X", buf[i]);

        std::cout << "\n";

        extract_measurements(buf, n);

    }


    // ETAPE 4: Boucle de polling (lecture continue)


    std::cout << "\n[Polling] Ctrl+C pour arreter\n\n";


    int cycle = 0;  // Compteur de cycles de lecture


    // Boucle infinie: lire les donnees toutes les 3 secondes

    while (true) {

        tcflush(fd, TCIFLUSH);  // Vider le buffer avant chaque lecture

        usleep(50000);         // Attendre 0.05 seconde


        // Commande de polling VE.Bus

        // 03 = longueur (3 octets)

        // FF 57 = marqueur pour demande de donnees

        // 00 = index du registre (0 = premier registre)

        // 57 = checksum (03 + FF + 57 + 00 = 0x1D4 -> -0x1D4 = 0x57)

        send_hex(fd, "03FF570057");


        // Attendre que les donnees arrivent

        usleep(400000);


        // Lire les donnees disponibles

        int got = drain(fd, buf, sizeof(buf), 10);


        // Afficher le cycle et les donnees

        std::cout << "[" << std::setw(3) << cycle << "] ";

        if (got > 0) {

            std::cout << got << " bytes: ";

            for (int i = 0; i < std::min(got, 20); ++i) printf("%02X", buf[i]);

            if (got > 20) std::cout << "...";

            std::cout << "\n";

            extract_measurements(buf, got);

        } else {

            std::cout << "(timeout)\n";

        }


        ++cycle;              // Incrementer le compteur

        usleep(3000000);      // Attendre 3 secondes avant le prochain cycle

    }


    // Ce code n'est jamais atteint (boucle infinie)

    // Mais en cas d'arret propre (Ctrl+C), on ferme le port

    close(fd);

    return 0;

}
