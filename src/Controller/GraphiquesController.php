<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;

class GraphiquesController extends AbstractController
{
    #[Route('/graphiques', name: 'app_graphiques')]
    public function index(Request $request, Connection $connexion): Response
    {
        $periode = $request->query->get('periode', '24h');
        $debut   = $request->query->get('debut');
        $fin     = $request->query->get('fin');

        if ($debut && $fin) {
            $dateDebut = new \DateTime($debut);
            $dateFin   = new \DateTime($fin);
            $periode   = 'custom';
        } else {
            $dateFin   = new \DateTime();
            $dateDebut = match ($periode) {
                '1h'  => (clone $dateFin)->modify('-1 hour'),
                '6h'  => (clone $dateFin)->modify('-6 hours'),
                '7j'  => (clone $dateFin)->modify('-7 days'),
                '30j' => (clone $dateFin)->modify('-30 days'),
                default => (clone $dateFin)->modify('-24 hours'),
            };
        }
        $fmt = 'Y-m-d H:i:s';
        $tempDisponible = false;

        $donneesOnduleur = $connexion->fetchAllAssociative(
            "SELECT horodatage,
                    bat_tension    AS bat_v,
                    bat_courant    AS bat_a,
                    soc,
                    dc_in_tension  AS ac_in_v,
                    dc_in_courant  AS ac_in_a,
                    ac_out_tension AS ac_out_v,
                    ac_out_courant AS ac_out_a,
                    NULL AS Temperature
             FROM mesures
             WHERE horodatage BETWEEN :debut AND :fin
             ORDER BY horodatage ASC",
            ['debut' => $dateDebut->format($fmt), 'fin' => $dateFin->format($fmt)]
        );

        $derniereOnduleur = $connexion->fetchAssociative(
            "SELECT horodatage,
                    bat_tension    AS batV,
                    bat_courant    AS batA,
                    soc,
                    dc_in_tension  AS acInV,
                    dc_in_courant  AS acInA,
                    ac_out_tension AS acOutV,
                    ac_out_courant AS acOutA,
                    NULL AS batTemp
             FROM mesures ORDER BY horodatage DESC LIMIT 1"
        ) ?: null;

        $stats = [];
        if (!empty($donneesOnduleur)) {
            $pSortie   = array_map(fn($r) => $r['ac_out_v'] * $r['ac_out_a'], $donneesOnduleur);
            $socValues = array_column($donneesOnduleur, 'soc');
            $stats = [
                'puissanceMax' => max($pSortie),
                'puissanceMoy' => array_sum($pSortie) / count($pSortie),
                'socMin'       => min($socValues),
                'socMax'       => max($socValues),
                'nbMesures'    => count($donneesOnduleur),
            ];
        }

        $onduleurJson = json_encode(array_map(fn($r) => [
            'horodatage' => $r['horodatage'],
            'batV'       => (float) $r['bat_v'],
            'batA'       => (float) $r['bat_a'],
            'soc'        => (int)   $r['soc'],
            'acInV'      => (float) $r['ac_in_v'],
            'acInA'      => (float) $r['ac_in_a'],
            'acOutV'     => (float) $r['ac_out_v'],
            'acOutA'     => (float) $r['ac_out_a'],
            'batTemp'    => null,
        ], $donneesOnduleur));

        return $this->render('graphiques/index.html.twig', [
            'donneesOnduleurJson' => $onduleurJson,
            'donneesReelles'      => !empty($donneesOnduleur),
            'derniereOnduleur'    => $derniereOnduleur,
            'stats'               => $stats,
            'periodeActive'       => $periode,
            'dateDebut'           => $dateDebut->format('Y-m-d\TH:i'),
            'dateFin'             => $dateFin->format('Y-m-d\TH:i'),
            'tempDisponible'      => $tempDisponible,
        ]);
    }

    #[Route('/api/graphiques/derniere', name: 'app_graphiques_api')]
    public function dernieresMesures(Connection $connexion): JsonResponse
    {
        $onduleur = $connexion->fetchAssociative(
            "SELECT horodatage,
                    bat_tension    AS bat_v,
                    bat_courant    AS bat_a,
                    soc,
                    dc_in_tension  AS ac_in_v,
                    dc_in_courant  AS ac_in_a,
                    ac_out_tension AS ac_out_v,
                    ac_out_courant AS ac_out_a,
                    NULL AS Temperature
             FROM mesures ORDER BY horodatage DESC LIMIT 1"
        );
        return $this->json(['onduleur' => $onduleur ?: null]);
    }
}
