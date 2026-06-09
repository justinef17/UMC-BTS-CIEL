<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact')]
    public function index(Request $request, ValidatorInterface $validator, MailerInterface $mailer): Response
    {
        $success  = false;
        $errors   = [];
        $formData = ['nom' => '', 'email' => '', 'sujet' => '', 'message' => ''];

        if ($request->isMethod('POST')) {

            // Protection CSRF 
            // Vérifie que le token caché du formulaire correspond au token
            // généré par Symfony pour cette session utilisateur.
            // Empêche les attaques Cross-Site Request Forgery (CSRF) :
            // un site malveillant ne peut pas soumettre le formulaire
            // à la place de l'utilisateur car il ne connaît pas le token.
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('contact_form', $token)) {
                // Le token est absent ou ne correspond pas → accès refusé
                // Lance une exception HTTP 403 Forbidden
                throw $this->createAccessDeniedException('Token CSRF invalide.');
            }

            // Nettoyage XSS
            $formData = [
                'nom' => htmlspecialchars(
                    strip_tags($request->request->get('nom', '')),
                    ENT_QUOTES, 'UTF-8'
                ),
                'email' => filter_var(
                    $request->request->get('email', ''),
                    FILTER_SANITIZE_EMAIL
                ),
                'sujet' => htmlspecialchars(
                    strip_tags($request->request->get('sujet', '')),
                    ENT_QUOTES, 'UTF-8'
                ),
                'message' => htmlspecialchars(
                    strip_tags($request->request->get('message', '')),
                    ENT_QUOTES, 'UTF-8'
                ),
            ];

            // ── Validation ───────────────────────────────────────────────────
            $constraints = new Assert\Collection([
                'nom'     => [new Assert\NotBlank(message: 'Le nom est obligatoire.'), new Assert\Length(['max' => 100])],
                'email'   => [new Assert\NotBlank(message: "L'email est obligatoire."), new Assert\Email(message: "L'email n'est pas valide.")],
                'sujet'   => [new Assert\Length(['max' => 200])],
                'message' => [new Assert\NotBlank(message: 'Le message est obligatoire.'), new Assert\Length(['max' => 2000])],
            ]);

            $violations = $validator->validate($formData, $constraints);

            if (count($violations) === 0) {

                // ── Envoi email ──────────────────────────────────────────────
                $email = (new Email())
                    ->from('gestionenergiemaupertuis@alwaysdata.net')
                    ->to('jujufr1703@gmail.com')
                    ->subject('Message UMC — ' . $formData['sujet'])
                    ->html('
                        <h2 style="color:#198754;">Nouveau message depuis l\'interface UMC</h2>
                        <p><strong>Nom :</strong> ' . $formData['nom'] . '</p>
                        <p><strong>Email :</strong> ' . $formData['email'] . '</p>
                        <p><strong>Sujet :</strong> ' . $formData['sujet'] . '</p>
                        <hr>
                        <p><strong>Message :</strong></p>
                        <p>' . nl2br($formData['message']) . '</p>
                        <hr>
                        <p style="color:#6c757d;font-size:12px;">
                            Message envoyé depuis le formulaire de contact UMC<br>
                            Lycée Maupertuis — Saint-Malo
                        </p>
                    ');

                $mailer->send($email);

                $success  = true;
                $formData = ['nom' => '', 'email' => '', 'sujet' => '', 'message' => ''];

            } else {
                foreach ($violations as $violation) {
                    $field          = str_replace(['[', ']'], '', $violation->getPropertyPath());
                    $errors[$field] = $violation->getMessage();
                }
            }
        }

        return $this->render('contact/index.html.twig', [
            'success'  => $success,
            'errors'   => $errors,
            'formData' => $formData,
        ]);
    }
}
