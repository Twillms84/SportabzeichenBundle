<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/sportabzeichen/manage', name: 'sportabzeichen_manage_')]
final class AnforderungViewController extends AbstractPageController
{
    #[Route(path: '/view', name: 'view')]
    public function view(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_MANAGE');

        $jahr = $request->query->getInt('jahr', 25);
        $kategorie = $request->query->get('kategorie', null);

        // Basis-Query
        $query = 'SELECT nummer, jahr, altersklasse, geschlecht, auswahlnummer, disziplin, kategorie,
                         bronze, silber, gold, abzeichen, einheit, schwimmnachweis, berechnungsart
                  FROM sportabzeichen_requirements
                  WHERE jahr = :jahr';
        $params = ['jahr' => $jahr];

        if ($kategorie) {
            $query .= ' AND kategorie = :kat';
            $params['kat'] = strtoupper($kategorie);
        }

        $query .= ' ORDER BY altersklasse, geschlecht, auswahlnummer, disziplin';

        $rows = $conn->fetchAllAssociative($query, $params);

        // verfügbare Jahre/Kategorien für Filter
        $years = $conn->fetchFirstColumn('SELECT DISTINCT jahr FROM sportabzeichen_requirements ORDER BY jahr');
        $categories = $conn->fetchFirstColumn('SELECT DISTINCT kategorie FROM sportabzeichen_requirements ORDER BY kategorie');

        return $this->render('@PulsRSportabzeichen/manage/view.html.twig', [
            'title' => 'Disziplinanforderungen (' . $jahr . ')',
            'anforderungen' => $rows,
            'jahre' => $years,
            'kategorien' => $categories,
            'selected_jahr' => $jahr,
            'selected_kategorie' => $kategorie,
        ]);
    }
}
