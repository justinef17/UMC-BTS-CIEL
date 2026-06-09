<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class TelechargementController extends AbstractController
{
    #[Route('/telechargement', name: 'app_telechargement')]
    public function index(): Response
    {
        return $this->render('telechargement/index.html.twig');
    }

    #[Route('/telechargement/export', name: 'app_telechargement_export')]
    public function export(Request $request, Connection $connexion): Response
    {
        $format  = $request->query->get('format',  'csv');
        $source  = $request->query->get('source',  'onduleur');
        $periode = $request->query->get('periode', 'jour');
        $date    = $request->query->get('date',    date('Y-m-d'));
        $debut   = $request->query->get('debut');
        $fin     = $request->query->get('fin');

        [$dateDebut, $dateFin] = $this->calculerBornes($periode, $date, $debut, $fin);

        if ($source === 'onduleur') {
            $donnees = $connexion->fetchAllAssociative(
                'SELECT horodatage,
                        dc_in_tension  AS ac_in_v,
                        dc_in_courant  AS ac_in_a,
                        ac_out_tension AS ac_out_v,
                        ac_out_courant AS ac_out_a
                 FROM mesures
                 WHERE horodatage BETWEEN :debut AND :fin
                 ORDER BY horodatage ASC',
                ['debut' => $dateDebut, 'fin' => $dateFin]
            );
            $colonnes = ['horodatage','ac_in_v','ac_in_a','ac_out_v','ac_out_a'];
        } elseif ($source === 'batteries') {
            $donnees = $connexion->fetchAllAssociative(
                'SELECT horodatage,
                        bat_tension AS bat_v,
                        bat_courant AS bat_a,
                        soc
                 FROM mesures
                 WHERE horodatage BETWEEN :debut AND :fin
                 ORDER BY horodatage ASC',
                ['debut' => $dateDebut, 'fin' => $dateFin]
            );
            $colonnes = ['horodatage','bat_v','bat_a','soc'];
        } else {
            $donnees = $connexion->fetchAllAssociative(
                'SELECT horodatage,
                        dc_in_tension  AS ac_in_v,
                        dc_in_courant  AS ac_in_a,
                        ac_out_tension AS ac_out_v,
                        ac_out_courant AS ac_out_a,
                        bat_tension    AS bat_v,
                        bat_courant    AS bat_a,
                        soc
                 FROM mesures
                 WHERE horodatage BETWEEN :debut AND :fin
                 ORDER BY horodatage ASC',
                ['debut' => $dateDebut, 'fin' => $dateFin]
            );
            $colonnes = ['horodatage','ac_in_v','ac_in_a','ac_out_v','ac_out_a','bat_v','bat_a','soc'];
        }

        $nomFichier = sprintf('umc_%s_%s_%s', $source, $periode, date('Y-m-d'));

        return match($format) {
            'xlsx'  => $this->exportXlsx($donnees, $colonnes, $nomFichier),
            'pdf'   => $this->exportPdf($donnees, $colonnes, $nomFichier, $source, $dateDebut, $dateFin),
            default => $this->exportCsv($donnees, $colonnes, $nomFichier),
        };
    }

    private function exportCsv(array $donnees, array $colonnes, string $nomFichier): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($donnees, $colonnes) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $colonnes, ';', '"', '\\');
            foreach ($donnees as $ligne) {
                fputcsv($handle, array_map(fn($c) => $ligne[$c] ?? '', $colonnes), ';', '"', '\\');
            }
            fclose($handle);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$nomFichier}.csv\"");
        return $response;
    }

    private function exportXlsx(array $donnees, array $colonnes, string $nomFichier): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mesures UMC');

        foreach ($colonnes as $col => $nom) {
            $cellule = chr(65 + $col) . '1';
            $sheet->setCellValue($cellule, $nom);
            $sheet->getStyle($cellule)->getFont()->setBold(true);
            $sheet->getStyle($cellule)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF198754');
            $sheet->getStyle($cellule)->getFont()->getColor()->setARGB('FFFFFFFF');
        }

        foreach ($donnees as $ligne => $row) {
            foreach ($colonnes as $col => $nom) {
                $sheet->setCellValue(chr(65 + $col) . ($ligne + 2), $row[$nom] ?? '');
            }
        }

        foreach (range('A', chr(64 + count($colonnes))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer  = new Xlsx($spreadsheet);
        $tmpFile = tempnam(sys_get_temp_dir(), 'umc_xlsx_');
        $writer->save($tmpFile);

        $response = new Response(file_get_contents($tmpFile));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$nomFichier}.xlsx\"");
        unlink($tmpFile);
        return $response;
    }

    private function exportPdf(array $donnees, array $colonnes, string $nomFichier, string $source, string $debut, string $fin): Response
 {
    $html = $this->renderView('telechargement/pdf.html.twig', [
        'donnees'  => $donnees,
        'colonnes' => $colonnes,
        'source'   => $source,
        'debut'    => $debut,
        'fin'      => $fin,
    ]);

    $mpdf = new \Mpdf\Mpdf([
        'margin_left'   => 10,
        'margin_right'  => 10,
        'margin_top'    => 10,
        'margin_bottom' => 10,
        'orientation'   => 'L',
    ]);

    $mpdf->WriteHTML($html);

    return new Response($mpdf->Output($nomFichier . '.pdf', 'S'), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => "attachment; filename=\"{$nomFichier}.pdf\"",
    ]);
  }

    private function calculerBornes(string $periode, string $date, ?string $debut, ?string $fin): array
    {
        if ($periode === 'perso' && $debut && $fin) {
            return [$debut . ' 00:00:00', $fin . ' 23:59:59'];
        }

        $dt = new \DateTime($date ?: 'now');

        return match ($periode) {
            'semaine' => [
                (clone $dt)->modify('monday this week')->format('Y-m-d') . ' 00:00:00',
                (clone $dt)->modify('sunday this week')->format('Y-m-d') . ' 23:59:59',
            ],
            'mois' => [
                $dt->format('Y-m') . '-01 00:00:00',
                $dt->format('Y-m-') . $dt->format('t') . ' 23:59:59',
            ],
            'annee' => [
                $dt->format('Y') . '-01-01 00:00:00',
                $dt->format('Y') . '-12-31 23:59:59',
            ],
            default => [
                $dt->format('Y-m-d') . ' 00:00:00',
                $dt->format('Y-m-d') . ' 23:59:59',
            ],
        };
    }
}
