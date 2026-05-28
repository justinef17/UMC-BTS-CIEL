<?php


// Imports

namespace App\Controller;
// Déclare que ce fichier appartient au dossier src/Controller/
// Symfony utilise les namespaces pour retrouver les classes automatiquement

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
// AbstractController = classe mère de Symfony
// Elle fournit les méthodes render(), redirect(), etc.
// Tous tes contrôleurs héritent d'elle via "extends"

use Symfony\Component\HttpFoundation\Request;
// Request = objet qui représente la requête HTTP reçue
// Il contient : la méthode (GET/POST), les données du formulaire, l'URL, les headers

use Symfony\Component\HttpFoundation\Response;
// Response = objet que le contrôleur retourne au navigateur
// Il contient : le HTML généré par Twig, le code HTTP (200, 404...)

use Symfony\Component\Routing\Attribute\Route;
// Route = annotation PHP qui définit l'URL déclenchant ce contrôleur
// Exemple : #[Route('/contact')] → ce contrôleur répond à /contact

use Symfony\Component\Validator\Validator\ValidatorInterface;
// ValidatorInterface = service Symfony qui vérifie les données du formulaire
// Il est injecté automatiquement par Symfony (injection de dépendances)

use Symfony\Component\Validator\Constraints as Assert;
// Assert = ensemble de règles de validation prêtes à l'emploi
// Exemple : Assert\NotBlank, Assert\Email, Assert\Length
// "as Assert" est un alias pour écrire Assert\Email au lieu de Constraints\Email



// Controlleur


class ContactController extends AbstractController
// extends AbstractController = hérite de toutes les méthodes de Symfony
// Sans ça, render() et les autres méthodes Symfony ne seraient pas disponibles
{

    // Déclaration de la route
    #[Route('/contact', name: 'app_contact')]
    // #[Route(...)] = attribut PHP 8 qui remplace les annotations en commentaires
    // '/contact' = URL qui déclenche cette méthode
    // name: 'app_contact' = nom de la route pour la référencer dans Twig :
    //   {{ path('app_contact') }} génère l'URL /contact


    // Méthode principale
    public function index(Request $request, ValidatorInterface $validator): Response
    // $request = la requête HTTP (GET ou POST) injectée par Symfony
    // $validator = le service de validation injecté automatiquement par Symfony
    // : Response = type de retour obligatoire (Symfony exige une Response)
    {

        // Variables initiales 
        $success = false;
        // false par défaut — passera à true si le formulaire est valide et envoyé

        $errors = [];
        // Tableau vide — contiendra les messages d'erreur par champ
        // Exemple après validation : ['email' => "L'email n'est pas valide."]

        $formData = ['nom' => '', 'email' => '', 'sujet' => '', 'message' => ''];
        // Valeurs initiales des champs — vides au chargement de la page (requête GET)
        // Permet de pré-remplir le formulaire si l'utilisateur a déjà saisi quelque chose
        // et qu'une erreur de validation est survenue


        // ── Traitement uniquement si la méthode est POST
        if ($request->isMethod('POST')) {
        // isMethod('POST') = vérifie que l'utilisateur vient de soumettre le formulaire
        // En GET (simple visite de la page) ce bloc est ignoré


            //Nettoyage des données (protection XSS)
            $formData = [

                'nom' => htmlspecialchars(
                    strip_tags($request->request->get('nom', '')),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                // $request->request->get('nom', '') = lit le champ "nom" du formulaire POST
                // '' = valeur par défaut si le champ est absent
                //
                // strip_tags() = supprime toutes les balises HTML et PHP
                //   Exemple : "Jean<script>alert(1)</script>" = "Jeanalert(1)"
                //
                // htmlspecialchars() = convertit les caractères spéciaux en entités HTML
                //   < → &lt;    > → &gt;    " → &quot;    ' → &#039;    & → &amp;
                //   Exemple : "<script>" → "&lt;script&gt;" → inoffensif dans le HTML
                //
                // ENT_QUOTES = convertit à la fois les guillemets simples ET doubles
                // 'UTF-8' = encodage utilisé pour éviter les bugs avec les accents

                'email' => filter_var(
                    $request->request->get('email', ''),
                    FILTER_SANITIZE_EMAIL
                ),
                // filter_var avec FILTER_SANITIZE_EMAIL = supprime les caractères
                // illégaux dans une adresse email (espaces, <, >, ...)
                // Exemple : "test <script>@test.fr" → "testscript@test.fr"
                // La validation réelle (format correct) se fait à l'étape 2

                'sujet' => htmlspecialchars(
                    strip_tags($request->request->get('sujet', '')),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                // Même traitement que 'nom'

                'message' => htmlspecialchars(
                    strip_tags($request->request->get('message', '')),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                // Même traitement — important car le message peut contenir
                // n'importe quel texte saisi par l'utilisateur
            ];


            //Validation des données
            $constraints = new Assert\Collection([
            // Assert\Collection = règles de validation pour un tableau associatif
            // Chaque clé du tableau correspond à un champ du formulaire

                'nom' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire.'),
                    // NotBlank = le champ ne doit pas être vide ou contenir seulement des espaces

                    new Assert\Length(['max' => 100]),
                    // Length max = le champ ne doit pas dépasser 100 caractères
                    // Protège contre les données trop longues qui pourraient saturer la BDD
                ],

                'email' => [
                    new Assert\NotBlank(message: "L'email est obligatoire."),
                    new Assert\Email(message: "L'email n'est pas valide."),
                    // Email = vérifie que la valeur est au format email valide
                    // Exemple valide : jean@exemple.fr
                    // Exemple invalide : "pasunmail", "test@", "@test.fr"
                ],

                'sujet' => [
                    new Assert\Length(['max' => 200]),
                    // Pas de NotBlank → le sujet est optionnel
                    // Mais limité à 200 caractères maximum
                ],

                'message' => [
                    new Assert\NotBlank(message: 'Le message est obligatoire.'),
                    new Assert\Length(['max' => 2000]),
                    // Limité à 2000 caractères pour éviter les messages trop longs
                ],
            ]);

            $violations = $validator->validate($formData, $constraints);
            // validate() compare $formData avec les règles définies dans $constraints
            // Retourne un objet ConstraintViolationList
            // Si tout est valide → la liste est vide (count = 0)
            // Sinon → la liste contient une violation par règle non respectée


            // ÉTAPE 3 : Résultat de la validation
            if (count($violations) === 0) {
            // count($violations) === 0 = aucune erreur de validation

                // Formulaire valide
                $success = true;
                // Passe à true → Twig affichera le message de confirmation vert

                $formData = ['nom' => '', 'email' => '', 'sujet' => '', 'message' => ''];
                // Réinitialise le formulaire après envoi réussi
                // Les champs apparaîtront vides dans le HTML

                // TODO (à implémenter plus tard) :
                // → Enregistrer le message en BDD
                // → Envoyer un email à contact@tridimension.fr via Symfony Mailer

            } else {

                // Formulaire invalide — on récupère les messages d'erreur
                foreach ($violations as $violation) {
                // foreach parcourt chaque violation une par une

                    $field = str_replace(['[', ']'], '', $violation->getPropertyPath());
                    // getPropertyPath() retourne le chemin du champ en erreur
                    // Exemple brut : "[email]"
                    // str_replace(['[', ']'], '', ...) supprime les crochets
                    // Résultat : "email"
                    // Ce nom correspond exactement à la clé du tableau $errors

                    $errors[$field] = $violation->getMessage();
                    // getMessage() retourne le message d'erreur défini dans la contrainte
                    // Exemple : $errors['email'] = "L'email n'est pas valide."
                    // Ces messages seront affichés sous les champs dans le formulaire Twig
                }
            }
        }


        // etour de la réponse à Twig 
        return $this->render('contact/index.html.twig', [
        // render() = méthode héritée d'AbstractController
        // Elle prend le template Twig et lui passe les variables PHP

            'success'  => $success,
            // true ou false → Twig affiche ou cache le message de confirmation

            'errors'   => $errors,
            // Tableau des erreurs → Twig affiche les messages sous chaque champ
            // Exemple dans Twig : {% if errors.email %}{{ errors.email }}{% endif %}

            'formData' => $formData,
            // Valeurs actuelles du formulaire → Twig les remet dans les champs
            // Permet de ne pas perdre ce que l'utilisateur a tapé en cas d'erreur
        ]);
    }
}
