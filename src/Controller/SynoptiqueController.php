<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SynoptiqueController extends AbstractController
{
    #[Route('/synoptique', name: 'app_synoptique')]
    public function index(): Response
    {
        return $this->render('synoptique/index.html.twig');
    }
}
